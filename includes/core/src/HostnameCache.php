<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class HostnameCache
{
    private const LOCK_OPTION = 'tya_hostname_lock';

    public function __construct(
        private readonly object $wpdb,
        private readonly string $eventsTable,
        private readonly string $cacheTable
    ) {}

    /** One query for the visible page. No decryption or DNS. @return array<string,array<string,mixed>> */
    public function forRows(array $rows): array
    {
        $hashes = [];
        foreach ($rows as $row) {
            if (is_string($row['ip_hash'] ?? null) && strlen($row['ip_hash']) === 32) {
                $hashes[bin2hex($row['ip_hash'])] = true;
            }
        }
        if ($hashes === []) return [];
        $quoted = array_map(static fn(string $hash): string => "'" . strtoupper($hash) . "'", array_keys($hashes));
        $entries = $this->wpdb->get_results(
            "SELECT HEX(ip_hash) ip_hash_hex,hostname,status,expires_at FROM {$this->cacheTable} WHERE HEX(ip_hash) IN (" . implode(',', $quoted) . ')',
            \ARRAY_A
        ) ?: [];
        $result = [];
        foreach ($entries as $entry) $result[strtolower((string)$entry['ip_hash_hex'])] = $entry;
        return $result;
    }

    public static function display(?array $entry): string
    {
        if (!$entry || (string)($entry['expires_at'] ?? '') <= gmdate('Y-m-d H:i:s')) {
            return __('Awaiting hostname lookup', 'tenyen-analytics');
        }
        $hostname = ReverseDns::hostname((string)($entry['hostname'] ?? ''));
        return ($entry['status'] ?? '') === 'resolved' && $hostname !== ''
            ? $hostname
            : __('Hostname unavailable', 'tenyen-analytics');
    }

    /** @return array{processed:int,busy:bool} */
    public function run(Crypto $crypto, ?callable $resolve = null): array
    {
        $now = time();
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        $lock = get_option(self::LOCK_OPTION, null);
        if (is_array($lock) && (int)($lock['expires'] ?? 0) > $now) return ['processed' => 0, 'busy' => true];
        if ($lock !== null) delete_option(self::LOCK_OPTION);
        if (!add_option(self::LOCK_OPTION, ['token' => $token, 'expires' => $now + 30], '', false)) return ['processed' => 0, 'busy' => true];
        try {
            $this->prune();
            $rows = $this->wpdb->get_results(
                "SELECT HEX(e.ip_hash) ip_hash_hex,MAX(e.event_id) event_id
                 FROM {$this->eventsTable} e LEFT JOIN {$this->cacheTable} c ON c.ip_hash=e.ip_hash
                 WHERE e.ip_hash IS NOT NULL AND (c.ip_hash IS NULL OR c.expires_at<=UTC_TIMESTAMP())
                 GROUP BY e.ip_hash ORDER BY event_id DESC LIMIT 20",
                \ARRAY_A
            ) ?: [];
            $resolve ??= (new ReverseDns())->resolve(...);
            $processed = 0;
            $deadline = microtime(true) + 15;
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) break;
                $encrypted = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT ip_encrypted FROM {$this->eventsTable} WHERE event_id=%d",
                    (int)$row['event_id']
                ));
                $ip = $crypto->decryptIp(is_string($encrypted) ? $encrypted : null);
                $result = ['status' => 'unavailable', 'hostname' => ''];
                if (ReverseDns::publicAddress($ip)) {
                    try { $result = $resolve($ip); }
                    catch (\Throwable) { $result = ['status' => 'failed', 'hostname' => '']; }
                }
                $hostname = ReverseDns::hostname((string)($result['hostname'] ?? ''));
                $status = ($result['status'] ?? '') === 'resolved' && $hostname !== ''
                    ? 'resolved'
                    : (($result['status'] ?? '') === 'failed' ? 'failed' : 'unavailable');
                $ttl = $status === 'resolved' ? DAY_IN_SECONDS : ($status === 'failed' ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS);
                $hash = strtolower((string)($row['ip_hash_hex'] ?? ''));
                if (!preg_match('/^[0-9a-f]{64}$/D', $hash)) continue;
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->cacheTable} (ip_hash,hostname,status,expires_at,updated_at)
                     VALUES (UNHEX(%s),%s,%s,%s,%s)
                     ON DUPLICATE KEY UPDATE hostname=VALUES(hostname),status=VALUES(status),expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)",
                    $hash,
                    $status === 'resolved' ? $hostname : '',
                    $status,
                    gmdate('Y-m-d H:i:s', time() + $ttl),
                    gmdate('Y-m-d H:i:s')
                ));
                $processed++;
            }
            return ['processed' => $processed, 'busy' => false];
        } finally {
            $current = get_option(self::LOCK_OPTION, []);
            if (is_array($current) && hash_equals($token, (string)($current['token'] ?? ''))) delete_option(self::LOCK_OPTION);
        }
    }

    public function prune(): void
    {
        $this->wpdb->query(
            "DELETE c FROM {$this->cacheTable} c WHERE NOT EXISTS (SELECT 1 FROM {$this->eventsTable} e WHERE e.ip_hash=c.ip_hash)"
        );
    }
}
