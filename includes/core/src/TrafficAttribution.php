<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class TrafficAttribution
{
    public const UTM_LIMITS = [
        'utm_source' => 128,
        'utm_medium' => 128,
        'utm_campaign' => 256,
        'utm_content' => 256,
        'utm_term' => 256,
    ];

    private const SEARCH_HOSTS = [
        'google.' => true, 'bing.com' => true, 'search.yahoo.' => true,
        'duckduckgo.com' => true, 'baidu.com' => true, 'yandex.' => true,
    ];

    private const SOCIAL_HOSTS = [
        'facebook.com' => true, 'instagram.com' => true, 'twitter.com' => true,
        'x.com' => true, 'bsky.app' => true, 'linkedin.com' => true,
        'reddit.com' => true, 'youtube.com' => true, 'youtu.be' => true,
    ];

    /** @return array<string,string> */
    public static function fromPage(string $path, string $referrer, string $siteUrl): array
    {
        $utm = self::utm($path);
        $referrerHost = self::host($referrer);
        $siteHost = self::host($siteUrl);

        if ($utm['utm_campaign'] !== '' || $utm['utm_source'] !== '' || $utm['utm_medium'] !== '') {
            $channel = 'Campaign';
        } elseif ($referrer === '') {
            $channel = 'Direct';
        } elseif ($referrerHost === '') {
            $channel = 'Unknown';
        } elseif (self::sameSite($referrerHost, $siteHost)) {
            $channel = 'Internal';
        } elseif (self::matches($referrerHost, self::SEARCH_HOSTS)) {
            $channel = 'Organic Search';
        } elseif (self::matches($referrerHost, self::SOCIAL_HOSTS) || self::isMastodon($referrerHost)) {
            $channel = 'Social';
        } else {
            $channel = 'Referral';
        }

        return ['traffic_channel' => $channel, 'referrer_host' => $referrerHost] + $utm;
    }

    /** @return array<string,string> */
    public static function utm(string $path): array
    {
        $result = array_fill_keys(array_keys(self::UTM_LIMITS), '');
        $query = parse_url($path, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $result;
        }
        foreach (explode('&', $query) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = strtolower(rawurldecode(str_replace('+', ' ', $rawKey)));
            if (!array_key_exists($key, self::UTM_LIMITS) || $result[$key] !== '') {
                continue;
            }
            $result[$key] = self::text(rawurldecode(str_replace('+', ' ', $rawValue)), self::UTM_LIMITS[$key]);
        }
        return $result;
    }

    public static function host(string $url): string
    {
        if ($url === '') return '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return '';
        $host = strtolower(rtrim($host, '.'));
        return preg_match('/^[a-z0-9.-]+$/', $host) ? self::text($host, 255) : '';
    }

    private static function sameSite(string $left, string $right): bool
    {
        return $left === $right || str_ends_with($left, '.' . $right) || str_ends_with($right, '.' . $left);
    }

    /** @param array<string,bool> $registry */
    private static function matches(string $host, array $registry): bool
    {
        foreach ($registry as $needle => $_) {
            if (str_ends_with($needle, '.') ? str_contains($host, $needle) : ($host === $needle || str_ends_with($host, '.' . $needle))) {
                return true;
            }
        }
        return false;
    }

    private static function isMastodon(string $host): bool
    {
        return $host === 'mastodon.social' || str_contains($host, 'mastodon.');
    }

    private static function text(string $value, int $limit): string
    {
        if (!preg_match('//u', $value)) return '';
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }
}
