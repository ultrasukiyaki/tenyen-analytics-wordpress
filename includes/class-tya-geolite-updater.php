<?php

declare(strict_types=1);

use Tenyen\Analytics\Crypto;
use Tenyen\Analytics\MmdbReader;

if (!defined('ABSPATH')) {
    exit;
}

final class TYA_GeoLite_Updater
{
    public const CRON_HOOK = 'tya_geolite_weekly_update';
    public const RETRY_HOOK = 'tya_geolite_retry';
    public const STATE_OPTION = 'tya_geolite_state';
    public const LOCK_OPTION = 'tya_geolite_lock';
    private const MAX_ARCHIVE_BYTES = 104857600;
    private const MAX_DATABASE_BYTES = 209715200;
    private const STALE_SECONDS = 45 * DAY_IN_SECONDS;
    private const ENDPOINTS = [
        'city' => ['edition' => 'GeoLite2-City', 'filename' => 'GeoLite2-City.mmdb'],
        'asn' => ['edition' => 'GeoLite2-ASN', 'filename' => 'GeoLite2-ASN.mmdb'],
    ];

    private ?Closure $downloadOverride;
    private ?Closure $extractOverride;
    private ?Closure $inspectOverride;

    public function __construct(?callable $download = null, ?callable $extract = null, ?callable $inspect = null)
    {
        $this->downloadOverride = $download === null ? null : Closure::fromCallable($download);
        $this->extractOverride = $extract === null ? null : Closure::fromCallable($extract);
        $this->inspectOverride = $inspect === null ? null : Closure::fromCallable($inspect);
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action(self::CRON_HOOK, [$this, 'scheduledUpdate']);
        add_action(self::RETRY_HOOK, [$this, 'scheduledUpdate']);
        $this->syncSchedule();
    }

    /** @param array<string,array<string,int|string>> $schedules */
    public static function cronSchedules(array $schedules): array
    {
        $schedules['tya_weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display' => __('Once weekly', 'tenyen-analytics'),
        ];
        return $schedules;
    }

    public function registerRoutes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('tenyen-analytics/v1', '/admin/geolite/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'statusRest'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/geolite/settings', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'settingsRest'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/geolite/update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'updateRest'],
            'permission_callback' => $permission,
        ]);
    }

    public function statusRest(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'geolite' => $this->status()]);
    }

    public function settingsRest(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        $account = trim((string)($input['account_id'] ?? ''));
        $license = trim((string)($input['license_key'] ?? ''));
        $clear = rest_sanitize_boolean($input['clear_license_key'] ?? false);
        $automatic = rest_sanitize_boolean($input['automatic'] ?? false);
        $storedCredentials = $this->credentials();

        if ($account !== '' && !preg_match('/^[0-9]{1,20}$/', $account)) {
            return $this->error(__('MaxMind account ID must contain digits only.', 'tenyen-analytics'), 400);
        }
        if ($license !== '' && !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $license)) {
            return $this->error(__('MaxMind license key format is invalid.', 'tenyen-analytics'), 400);
        }
        $storedKeyValid = preg_match('/^[A-Za-z0-9_-]{8,128}$/', $storedCredentials['license_key']) === 1;
        if ($automatic && ($account === '' || ($license === '' && (!$storedKeyValid || $clear)))) {
            return $this->error(__('Account ID and license key are required for automatic updates.', 'tenyen-analytics'), 400);
        }

        update_option('tya_maxmind_account_id', $account, false);
        if ($clear) {
            delete_option('tya_maxmind_license_key');
        } elseif ($license !== '') {
            try {
                $protected = 'v1:' . base64_encode($this->credentialCrypto()->encrypt($license));
            } catch (Throwable) {
                return $this->error(__('The MaxMind license key could not be encrypted.', 'tenyen-analytics'), 500);
            }
            update_option('tya_maxmind_license_key', $protected, false);
        }
        update_option('tya_geolite_auto_update', $automatic ? 1 : 0, false);
        $this->syncSchedule();
        return new WP_REST_Response(['ok' => true, 'geolite' => $this->status()]);
    }

    public function updateRest(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        $database = sanitize_key((string)($input['database'] ?? 'all'));
        if (!in_array($database, ['all', 'city', 'asn'], true)) {
            return $this->error(__('Unknown GeoLite2 database selection.', 'tenyen-analytics'), 400);
        }
        if (!$this->credentials()['valid']) {
            return $this->error(__('Valid MaxMind credentials are required.', 'tenyen-analytics'), 400);
        }
        $result = $this->runUpdate($database);
        return new WP_REST_Response(['ok' => $result['status'] !== 'locked', 'geolite' => $this->status()], $result['status'] === 'locked' ? 409 : 200);
    }

    public function scheduledUpdate(): void
    {
        if (!(bool)get_option('tya_geolite_auto_update', 0)) {
            return;
        }
        $this->runUpdate('all');
    }

    /** @return array{status:string,results:array<string,array<string,mixed>>} */
    public function runUpdate(string $database = 'all'): array
    {
        if (!in_array($database, ['all', 'city', 'asn'], true)) {
            return ['status' => 'invalid', 'results' => []];
        }
        $token = $this->acquireLock();
        if ($token === '') {
            return ['status' => 'locked', 'results' => []];
        }

        $results = [];
        try {
            $credentials = $this->credentials();
            if (!$credentials['valid']) {
                foreach ($database === 'all' ? ['city', 'asn'] : [$database] as $type) {
                    $results[$type] = $this->recordFailure($type, __('MaxMind credentials are missing or invalid.', 'tenyen-analytics'));
                }
                return ['status' => 'failed', 'results' => $results];
            }
            foreach ($database === 'all' ? ['city', 'asn'] : [$database] as $type) {
                $results[$type] = $this->updateOne($type, $credentials['account_id'], $credentials['license_key']);
            }
            update_option('tya_geolite_last_run', gmdate('Y-m-d H:i:s'), false);
            $failed = array_filter($results, static fn(array $result): bool => $result['status'] !== 'current');
            if ($failed !== []) {
                $this->scheduleRetry();
            } else {
                $this->clearRetry();
                update_option('tya_geolite_failures', 0, false);
            }
            return ['status' => $failed === [] ? 'complete' : 'partial', 'results' => $results];
        } finally {
            $current = get_option(self::LOCK_OPTION, []);
            if (is_array($current) && hash_equals($token, (string)($current['token'] ?? ''))) {
                delete_option(self::LOCK_OPTION);
            }
            $this->cleanupStaleTemps();
        }
    }

    /** @return array<string,mixed> */
    private function updateOne(string $type, string $account, string $license): array
    {
        $definition = self::ENDPOINTS[$type];
        $target = $this->targetPath($type);
        $directory = dirname($target);
        $attempt = gmdate('Y-m-d H:i:s');
        $this->mergeState($type, ['last_attempt' => $attempt, 'status' => 'updating', 'error' => '']);

        if ($target === '' || basename($target) === '') {
            return $this->recordFailure($type, __('The GeoLite2 database path is not configured.', 'tenyen-analytics'));
        }
        if ((!is_dir($directory) && !wp_mkdir_p($directory)) || !is_writable($directory)) {
            return $this->recordFailure($type, __('The configured GeoLite2 directory is not writable.', 'tenyen-analytics'));
        }
        $tempDirectory = $directory . '/.tya-geolite-' . wp_generate_uuid4();
        if (!wp_mkdir_p($tempDirectory)) {
            return $this->recordFailure($type, __('Could not create a temporary update directory.', 'tenyen-analytics'));
        }
        @chmod($tempDirectory, 0700);
        $archive = $tempDirectory . '/download.tar.gz';
        $candidate = $tempDirectory . '/' . $definition['filename'];
        $backup = $target . '.tya-backup';

        try {
            $this->download($definition['edition'], $archive, $account, $license);
            if (!is_file($archive) || filesize($archive) === false || filesize($archive) < 64 || filesize($archive) > self::MAX_ARCHIVE_BYTES) {
                throw new TYA_GeoLite_Safe_Exception(__('The downloaded GeoLite2 archive has an invalid size.', 'tenyen-analytics'));
            }
            $this->extract($archive, $definition['filename'], $candidate);
            $metadata = $this->inspect($candidate);
            $databaseType = (string)($metadata['database_type'] ?? '');
            if ($databaseType !== $definition['edition']) {
                throw new TYA_GeoLite_Safe_Exception(__('The archive contains the wrong MaxMind database type.', 'tenyen-analytics'));
            }
            $buildEpoch = (int)($metadata['build_epoch'] ?? 0);
            if ($buildEpoch < 946684800 || $buildEpoch > time() + DAY_IN_SECONDS) {
                throw new TYA_GeoLite_Safe_Exception(__('The extracted MMDB has invalid build metadata.', 'tenyen-analytics'));
            }
            $size = filesize($candidate);
            if ($size === false || $size < 64 || $size > self::MAX_DATABASE_BYTES || !is_readable($candidate)) {
                throw new TYA_GeoLite_Safe_Exception(__('The extracted MMDB is corrupt or unreadable.', 'tenyen-analytics'));
            }
            @chmod($candidate, 0640);
            if (is_file($backup) && !@unlink($backup)) {
                throw new TYA_GeoLite_Safe_Exception(__('A stale GeoLite2 backup could not be removed.', 'tenyen-analytics'));
            }
            $hadTarget = is_file($target);
            if ($hadTarget && !@rename($target, $backup)) {
                throw new TYA_GeoLite_Safe_Exception(__('The existing GeoLite2 database could not be backed up.', 'tenyen-analytics'));
            }
            if (!@rename($candidate, $target)) {
                if ($hadTarget && is_file($backup)) {
                    @rename($backup, $target);
                }
                throw new TYA_GeoLite_Safe_Exception(__('The validated GeoLite2 database could not be activated.', 'tenyen-analytics'));
            }
            @chmod($target, 0640);
            if (is_file($backup)) {
                @unlink($backup);
            }
            $state = [
                'installed' => true,
                'path' => basename($target),
                'database_type' => $databaseType,
                'build_date' => $buildEpoch > 0 ? gmdate('Y-m-d H:i:s', $buildEpoch) : '',
                'size' => (int)filesize($target),
                'last_success' => gmdate('Y-m-d H:i:s'),
                'last_attempt' => $attempt,
                'status' => 'current',
                'error' => '',
            ];
            $this->mergeState($type, $state);
            return $state;
        } catch (Throwable $error) {
            return $this->recordFailure($type, $this->safeError($error));
        } finally {
            $this->removeTree($tempDirectory);
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $databases = [];
        foreach (array_keys(self::ENDPOINTS) as $type) {
            $saved = is_array($state[$type] ?? null) ? $state[$type] : [];
            $databases[$type] = array_merge($saved, $this->health($type));
        }
        $credentials = $this->credentials();
        return [
            'automatic' => (bool)get_option('tya_geolite_auto_update', 0),
            'account_id' => (string)get_option('tya_maxmind_account_id', ''),
            'license_key_masked' => $credentials['has_key'] ? '••••••••' : '',
            'credentials_configured' => $credentials['valid'],
            'last_run' => (string)get_option('tya_geolite_last_run', ''),
            'next_run' => $this->scheduledDate(self::CRON_HOOK),
            'next_retry' => $this->scheduledDate(self::RETRY_HOOK),
            'locked' => $this->lockActive(),
            'databases' => $databases,
        ];
    }

    /** @return array<string,mixed> */
    private function health(string $type): array
    {
        $path = $this->targetPath($type);
        $base = ['installed' => false, 'path' => basename($path), 'database_type' => '', 'build_date' => '', 'size' => 0, 'health' => 'missing'];
        if (!is_file($path)) {
            return $base;
        }
        if (!is_readable($path)) {
            return array_merge($base, ['installed' => true, 'size' => (int)(filesize($path) ?: 0), 'health' => 'unreadable']);
        }
        try {
            $metadata = $this->inspect($path);
            $databaseType = (string)($metadata['database_type'] ?? '');
            $buildEpoch = max(0, (int)($metadata['build_epoch'] ?? 0));
            if ($buildEpoch < 946684800 || $buildEpoch > time() + DAY_IN_SECONDS) {
                throw new UnexpectedValueException('Invalid MMDB build metadata.');
            }
            $health = $databaseType === self::ENDPOINTS[$type]['edition'] ? 'current' : 'wrong_type';
            if ($health === 'current' && $buildEpoch > 0 && $buildEpoch < time() - self::STALE_SECONDS) {
                $health = 'stale';
            }
            return [
                'installed' => true,
                'path' => basename($path),
                'database_type' => $databaseType,
                'build_date' => $buildEpoch > 0 ? gmdate('Y-m-d H:i:s', $buildEpoch) : '',
                'size' => (int)(filesize($path) ?: 0),
                'health' => $health,
            ];
        } catch (Throwable) {
            return array_merge($base, ['installed' => true, 'size' => (int)(filesize($path) ?: 0), 'health' => 'corrupt']);
        }
    }

    private function download(string $edition, string $destination, string $account, string $license): void
    {
        if ($this->downloadOverride !== null) {
            ($this->downloadOverride)($edition, $destination, $account, $license);
            return;
        }
        if (!in_array($edition, ['GeoLite2-City', 'GeoLite2-ASN'], true)) {
            throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 edition is not allowed.', 'tenyen-analytics'));
        }
        $url = 'https://download.maxmind.com/geoip/databases/' . rawurlencode($edition) . '/download?suffix=tar.gz';
        $request = [
            'timeout' => 60,
            'redirection' => 0,
            'stream' => true,
            'filename' => $destination,
            'limit_response_size' => self::MAX_ARCHIVE_BYTES,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($account . ':' . $license)],
            'user-agent' => 'Tenyen-Analytics/' . TYA_VERSION,
        ];
        $response = wp_safe_remote_get($url, $request);
        if (is_wp_error($response)) {
            throw new TYA_GeoLite_Safe_Exception(__('The MaxMind download request failed.', 'tenyen-analytics'));
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $redirect = (string)wp_remote_retrieve_header($response, 'location');
            if (!$this->validDownloadRedirect($redirect)) {
                throw new TYA_GeoLite_Safe_Exception(__('MaxMind returned an invalid database download redirect.', 'tenyen-analytics'));
            }
            unset($request['headers']);
            $request['redirection'] = 2;
            $response = wp_safe_remote_get($redirect, $request);
            if (is_wp_error($response)) {
                throw new TYA_GeoLite_Safe_Exception(__('The MaxMind download request failed.', 'tenyen-analytics'));
            }
            $code = (int)wp_remote_retrieve_response_code($response);
        }
        if ($code !== 200) {
            throw new TYA_GeoLite_Safe_Exception($this->downloadError($code));
        }
    }

    private function validDownloadRedirect(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
            return false;
        }
        return strtolower((string)($parts['host'] ?? '')) === 'mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com';
    }

    private function downloadError(int $code): string
    {
        return match ($code) {
            401 => __('MaxMind rejected the configured account ID or license key.', 'tenyen-analytics'),
            403 => __('MaxMind accepted the credentials, but this account is not permitted to download the selected GeoLite2 database. Check GeoLite enrollment and product permissions.', 'tenyen-analytics'),
            429 => __('MaxMind temporarily rate-limited database downloads. Try again later.', 'tenyen-analytics'),
            default => sprintf(
                __('MaxMind returned HTTP status %d.', 'tenyen-analytics'),
                $code
            ),
        };
    }

    private function extract(string $archive, string $expectedFilename, string $destination): void
    {
        if ($this->extractOverride !== null) {
            ($this->extractOverride)($archive, $expectedFilename, $destination);
            return;
        }
        try {
            $phar = new PharData($archive);
        } catch (Throwable) {
            throw new TYA_GeoLite_Safe_Exception(__('The downloaded GeoLite2 archive is invalid.', 'tenyen-analytics'));
        }
        $matches = [];
        $entries = 0;
        $iterator = new RecursiveIteratorIterator($phar);
        $archivePrefix = 'phar://' . str_replace('\\', '/', $archive) . '/';
        foreach ($iterator as $entry) {
            if (++$entries > 256) {
                throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 archive contains too many files.', 'tenyen-analytics'));
            }
            $entryPath = str_replace('\\', '/', $entry->getPathname());
            if (!str_starts_with($entryPath, $archivePrefix)) {
                throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 archive contains an unsafe path.', 'tenyen-analytics'));
            }
            $relative = substr($entryPath, strlen($archivePrefix));
            if (!self::safeArchivePath($relative)) {
                throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 archive contains an unsafe path.', 'tenyen-analytics'));
            }
            if ($entry->isLink()) {
                throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 archive contains an unsafe link.', 'tenyen-analytics'));
            }
            if (!$entry->isDir() && basename($relative) === $expectedFilename) {
                if ($entry->getSize() > self::MAX_DATABASE_BYTES) {
                    throw new TYA_GeoLite_Safe_Exception(__('The GeoLite2 database exceeds the size limit.', 'tenyen-analytics'));
                }
                $matches[] = $entry->getPathname();
            }
        }
        if (count($matches) !== 1) {
            throw new TYA_GeoLite_Safe_Exception(__('The expected MMDB file is missing from the archive.', 'tenyen-analytics'));
        }
        $source = @fopen($matches[0], 'rb');
        $output = @fopen($destination, 'xb');
        if ($source === false || $output === false) {
            if (is_resource($source)) fclose($source);
            if (is_resource($output)) fclose($output);
            throw new TYA_GeoLite_Safe_Exception(__('The MMDB file could not be extracted safely.', 'tenyen-analytics'));
        }
        $copied = stream_copy_to_stream($source, $output, self::MAX_DATABASE_BYTES + 1);
        fclose($source);
        fclose($output);
        if ($copied === false || $copied > self::MAX_DATABASE_BYTES) {
            @unlink($destination);
            throw new TYA_GeoLite_Safe_Exception(__('The extracted MMDB exceeds the size limit.', 'tenyen-analytics'));
        }
    }

    public static function safeArchivePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '..' || $part === '') return false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function inspect(string $path): array
    {
        if ($this->inspectOverride !== null) {
            return (array)($this->inspectOverride)($path);
        }
        $reader = new MmdbReader($path);
        try {
            return $reader->metadata();
        } finally {
            $reader->close();
        }
    }

    private function targetPath(string $type): string
    {
        return (string)get_option($type === 'city' ? 'tya_city_db' : 'tya_asn_db', '');
    }

    /** @return array{account_id:string,license_key:string,has_key:bool,valid:bool} */
    private function credentials(bool $decrypt = true): array
    {
        $account = trim((string)get_option('tya_maxmind_account_id', ''));
        $encrypted = (string)get_option('tya_maxmind_license_key', '');
        try {
            $encoded = str_starts_with($encrypted, 'v1:') ? substr($encrypted, 3) : '';
            $payload = $encoded !== '' ? base64_decode($encoded, true) : false;
            $license = $decrypt && is_string($payload) ? $this->credentialCrypto()->decrypt($payload) : '';
        } catch (Throwable) {
            $license = '';
        }
        $valid = preg_match('/^[0-9]{1,20}$/', $account) === 1
            && $encrypted !== ''
            && (!$decrypt || preg_match('/^[A-Za-z0-9_-]{8,128}$/', $license) === 1);
        return ['account_id' => $account, 'license_key' => $license, 'has_key' => $encrypted !== '', 'valid' => $valid];
    }

    private function credentialCrypto(): Crypto
    {
        $encryption = defined('AUTH_KEY') ? (string)AUTH_KEY : wp_salt('auth');
        $hash = defined('SECURE_AUTH_SALT') ? (string)SECURE_AUTH_SALT : wp_salt('secure_auth');
        return new Crypto($encryption . '|tenyen-geolite', $hash . '|tenyen-geolite-integrity');
    }

    private function acquireLock(): string
    {
        if ($this->lockActive()) return '';
        $token = wp_generate_uuid4();
        return add_option(self::LOCK_OPTION, ['token' => $token, 'expires' => time() + 30 * MINUTE_IN_SECONDS], '', false) ? $token : '';
    }

    private function lockActive(): bool
    {
        $lock = get_option(self::LOCK_OPTION, []);
        if (!is_array($lock)) return false;
        if ((int)($lock['expires'] ?? 0) <= time()) {
            delete_option(self::LOCK_OPTION);
            return false;
        }
        return (string)($lock['token'] ?? '') !== '';
    }

    public function syncSchedule(): void
    {
        $enabled = (bool)get_option('tya_geolite_auto_update', 0);
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($enabled && !$next) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'tya_weekly', self::CRON_HOOK);
        } elseif (!$enabled && $next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
        if (!$enabled) $this->clearRetry();
    }

    private function scheduleRetry(): void
    {
        if (!(bool)get_option('tya_geolite_auto_update', 0) || wp_next_scheduled(self::RETRY_HOOK)) return;
        $failures = min(8, max(1, (int)get_option('tya_geolite_failures', 0) + 1));
        update_option('tya_geolite_failures', $failures, false);
        $delays = [HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS];
        wp_schedule_single_event(time() + $delays[min(2, $failures - 1)], self::RETRY_HOOK);
    }

    private function clearRetry(): void
    {
        $retry = wp_next_scheduled(self::RETRY_HOOK);
        if ($retry) wp_unschedule_event($retry, self::RETRY_HOOK);
    }

    private function scheduledDate(string $hook): string
    {
        $timestamp = wp_next_scheduled($hook);
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    /** @return array<string,mixed> */
    private function recordFailure(string $type, string $message): array
    {
        $state = ['last_attempt' => gmdate('Y-m-d H:i:s'), 'status' => 'failed', 'error' => $message];
        $this->mergeState($type, $state);
        return $state;
    }

    /** @param array<string,mixed> $changes */
    private function mergeState(string $type, array $changes): void
    {
        $state = get_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $current = is_array($state[$type] ?? null) ? $state[$type] : [];
        $state[$type] = array_merge($current, $changes);
        update_option(self::STATE_OPTION, $state, false);
    }

    private function safeError(Throwable $error): string
    {
        if ($error instanceof TYA_GeoLite_Safe_Exception) return $error->getMessage();
        return __('GeoLite2 validation failed. The existing database was kept.', 'tenyen-analytics');
    }

    private function cleanupStaleTemps(): void
    {
        foreach (['city', 'asn'] as $type) {
            $directory = dirname($this->targetPath($type));
            if (!is_dir($directory)) continue;
            $entries = glob($directory . '/.tya-geolite-*', GLOB_ONLYDIR) ?: [];
            foreach (array_slice($entries, 0, 50) as $entry) {
                $modified = @filemtime($entry);
                if ($modified !== false && $modified < time() - DAY_IN_SECONDS) $this->removeTree($entry);
            }
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || !str_starts_with(basename($directory), '.tya-geolite-')) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    private function error(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'message' => $message], $status);
    }
}

final class TYA_GeoLite_Safe_Exception extends RuntimeException
{
}
