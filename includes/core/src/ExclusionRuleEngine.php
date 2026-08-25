<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class ExclusionRuleEngine
{
    public const TYPES = [
        'ip_exact', 'ip_cidr', 'path_exact', 'path_prefix', 'administrator', 'bot',
        'country', 'region', 'asn', 'organization', 'category', 'browser', 'os',
        'device', 'referrer_domain', 'utm_source', 'utm_medium', 'utm_campaign',
    ];
    public const SCOPES = ['collection', 'analysis'];
    public const ANALYSIS_TYPES = [
        'ip_exact', 'path_exact', 'path_prefix', 'bot', 'country', 'region', 'asn',
        'organization', 'browser', 'os', 'device', 'referrer_domain', 'utm_source',
        'utm_medium', 'utm_campaign',
    ];

    /** @var array<string,int> */
    private const PRECEDENCE = [
        'ip_exact' => 10, 'ip_cidr' => 20, 'administrator' => 30, 'bot' => 40,
        'path_exact' => 50, 'path_prefix' => 60, 'country' => 70, 'region' => 80,
        'asn' => 90, 'organization' => 100, 'category' => 110, 'browser' => 120,
        'os' => 130, 'device' => 140, 'referrer_domain' => 150,
        'utm_source' => 160, 'utm_medium' => 170, 'utm_campaign' => 180,
    ];

    /** @return array{valid:bool,value:string,error:string} */
    public static function validate(string $type, mixed $value, string $scope): array
    {
        $type = strtolower(trim($type));
        $scope = strtolower(trim($scope));
        if (!in_array($type, self::TYPES, true)) return self::invalid('Unsupported rule type.');
        if (!in_array($scope, self::SCOPES, true)) return self::invalid('Unsupported rule scope.');
        if ($scope === 'analysis' && !in_array($type, self::ANALYSIS_TYPES, true)) {
            return self::invalid('This rule type is collection-only.');
        }
        if (is_array($value) || is_object($value)) return self::invalid('Rule value must be plain text.');
        $value = trim((string)$value);
        if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F<>]/', $value)) return self::invalid('Rule value contains invalid characters.');

        if (in_array($type, ['administrator', 'bot'], true)) return ['valid' => true, 'value' => '1', 'error' => ''];
        if ($value === '') return self::invalid('Rule value is required.');

        if ($type === 'ip_exact') {
            $binary = @inet_pton($value);
            if ($binary === false) return self::invalid('Invalid IP address.');
            return ['valid' => true, 'value' => (string)@inet_ntop($binary), 'error' => ''];
        }
        if ($type === 'ip_cidr') {
            $cidr = self::canonicalCidr($value);
            return $cidr === null ? self::invalid('Invalid IPv4 or IPv6 CIDR.') : ['valid' => true, 'value' => $cidr, 'error' => ''];
        }
        if ($type === 'asn') {
            if (!preg_match('/^(?:AS)?([1-9][0-9]{0,9})$/i', $value, $match) || (int)$match[1] > 4294967295) return self::invalid('Invalid ASN.');
            return ['valid' => true, 'value' => (string)(int)$match[1], 'error' => ''];
        }
        if ($type === 'country') {
            $value = strtoupper($value);
            if (!preg_match('/^[A-Z]{2}$/', $value)) return self::invalid('Country must be a two-letter code.');
            return ['valid' => true, 'value' => $value, 'error' => ''];
        }
        if ($type === 'device') {
            $value = strtolower($value);
            if (!in_array($value, ['desktop', 'mobile', 'tablet'], true)) return self::invalid('Unsupported device type.');
            return ['valid' => true, 'value' => $value, 'error' => ''];
        }
        if ($type === 'category') {
            $value = strtolower(str_replace('-', '_', $value));
            if (!in_array($value, ['research', 'government', 'company', 'isp', 'cloud', 'proxy', 'bot', 'unknown'], true)) return self::invalid('Unsupported organization category.');
            return ['valid' => true, 'value' => $value, 'error' => ''];
        }
        if ($type === 'referrer_domain') {
            $value = self::domain($value);
            if ($value === '') return self::invalid('Invalid referrer domain.');
        } elseif (in_array($type, ['path_exact', 'path_prefix'], true)) {
            $value = self::path($value);
            if ($value === '') return self::invalid('Invalid path.');
        } else {
            $value = self::lower($value);
        }
        $limit = in_array($type, ['path_exact', 'path_prefix'], true) ? 512 : 256;
        if (self::length($value) > $limit) return self::invalid('Rule value is too long.');
        return ['valid' => true, 'value' => $value, 'error' => ''];
    }

    /** @param array<int,array<string,mixed>> $rules @param array<string,mixed> $context */
    public static function diagnose(array $rules, array $context, string $scope): array
    {
        $eligible = array_values(array_filter($rules, static fn(array $rule): bool =>
            !empty($rule['enabled']) && (string)($rule['scope'] ?? '') === $scope
        ));
        usort($eligible, static function (array $a, array $b): int {
            $pa = self::PRECEDENCE[(string)($a['type'] ?? '')] ?? 999;
            $pb = self::PRECEDENCE[(string)($b['type'] ?? '')] ?? 999;
            return $pa <=> $pb ?: (int)($a['rule_id'] ?? 0) <=> (int)($b['rule_id'] ?? 0);
        });
        foreach ($eligible as $rule) {
            if (self::matches($rule, $context)) {
                $type = (string)$rule['type'];
                return [
                    'excluded' => true, 'rule_id' => (int)($rule['rule_id'] ?? 0),
                    'type' => $type, 'value' => (string)$rule['value'], 'scope' => $scope,
                    'precedence' => self::PRECEDENCE[$type] ?? 999, 'action' => 'exclude',
                    'reason' => self::reason($type, (string)$rule['value']),
                ];
            }
        }
        return ['excluded' => false, 'rule_id' => null, 'type' => null, 'value' => null,
            'scope' => $scope, 'precedence' => null, 'action' => 'include',
            'reason' => 'No enabled rule matched.'];
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $context */
    private static function matches(array $rule, array $context): bool
    {
        $type = (string)($rule['type'] ?? '');
        $value = (string)($rule['value'] ?? '');
        return match ($type) {
            'ip_exact' => self::sameIp((string)($context['ip'] ?? ''), $value),
            'ip_cidr' => self::inCidr((string)($context['ip'] ?? ''), $value),
            'path_exact' => self::path((string)($context['path'] ?? '')) === $value,
            'path_prefix' => str_starts_with(self::path((string)($context['path'] ?? '')), $value),
            'administrator' => !empty($context['administrator']),
            'bot' => !empty($context['bot']),
            'country' => strtoupper((string)($context['country'] ?? '')) === $value,
            'region' => self::lower((string)($context['region'] ?? '')) === $value,
            'asn' => (string)(int)($context['asn'] ?? 0) === $value,
            'organization' => self::lower((string)($context['organization'] ?? '')) === $value,
            'category' => self::lower((string)($context['category'] ?? '')) === $value,
            'browser' => self::lower((string)($context['browser'] ?? '')) === $value,
            'os' => self::lower((string)($context['os'] ?? '')) === $value,
            'device' => self::lower((string)($context['device'] ?? '')) === $value,
            'referrer_domain' => self::domain((string)($context['referrer_domain'] ?? '')) === $value,
            'utm_source', 'utm_medium', 'utm_campaign' => self::lower((string)($context[$type] ?? '')) === $value,
            default => false,
        };
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton((string)$network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes) || !is_numeric($prefix)) return false;
        $bits = (int)$prefix;
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($full > 0 && substr($addressBytes, 0, $full) !== substr($networkBytes, 0, $full)) return false;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($addressBytes[$full]) & $mask) === (ord($networkBytes[$full]) & $mask);
    }

    private static function canonicalCidr(string $value): ?string
    {
        if (!preg_match('~^([^/]+)/([0-9]{1,3})$~', $value, $match)) return null;
        $bytes = @inet_pton($match[1]);
        if ($bytes === false) return null;
        $bits = (int)$match[2];
        $max = strlen($bytes) * 8;
        if ($bits < 0 || $bits > $max) return null;
        $network = $bytes;
        for ($i = $bits; $i < $max; $i++) {
            $byte = intdiv($i, 8); $offset = 7 - ($i % 8);
            $network[$byte] = chr(ord($network[$byte]) & ~(1 << $offset));
        }
        return (string)@inet_ntop($network) . '/' . $bits;
    }

    private static function sameIp(string $left, string $right): bool
    {
        $a = @inet_pton($left); $b = @inet_pton($right);
        return $a !== false && $b !== false && hash_equals($a, $b);
    }

    private static function path(string $value): string
    {
        $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
        if ($path === '' || $path[0] !== '/') return '';
        return preg_replace('~/+~', '/', $path) ?? '';
    }

    private static function domain(string $value): string
    {
        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        $host = strtolower(rtrim((string)$host, '.'));
        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) ? $host : '';
    }

    private static function reason(string $type, string $value): string { return sprintf('Matched %s rule "%s".', $type, $value); }
    private static function invalid(string $error): array { return ['valid' => false, 'value' => '', 'error' => $error]; }
    private static function lower(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); }
    private static function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
}
