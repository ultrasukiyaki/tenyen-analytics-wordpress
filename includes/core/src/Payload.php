<?php

declare(strict_types=1);

namespace Tenyen\Analytics;

final class Payload
{
    private const EVENT_TYPES = [
        'pageview', 'engagement', 'outbound', 'download', 'internal_link_click',
        'button_click', 'form_submit', 'not_found', 'custom',
    ];

    public static function normalize(array $input): array
    {
        $event = self::text($input['event'] ?? 'pageview', 32);
        if (!in_array($event, self::EVENT_TYPES, true)) {
            $event = 'custom';
        }

        return [
            'event' => $event,
            'visitor_id' => self::uuid($input['visitor_id'] ?? ''),
            'session_id' => self::uuid($input['session_id'] ?? ''),
            'path' => self::text($input['path'] ?? '/', 2048),
            'title' => self::text($input['title'] ?? '', 512),
            'referrer' => self::text($input['referrer'] ?? '', 2048),
            'language' => self::text($input['language'] ?? '', 32),
            'timezone' => self::text($input['timezone'] ?? '', 64),
            'screen' => self::text($input['screen'] ?? '', 32),
            'viewport' => self::text($input['viewport'] ?? '', 32),
            'duration_ms' => self::integer($input['duration_ms'] ?? 0, 0, 86400000),
            'scroll_depth' => self::integer($input['scroll_depth'] ?? 0, 0, 100),
            'target_url' => self::text($input['target_url'] ?? '', 2048),
            'event_name' => self::eventName($input['event_name'] ?? ''),
            'event_meta' => self::metadata($input['metadata'] ?? []),
        ];
    }

    private static function eventName(mixed $value): string
    {
        $value = self::text($value, 64);
        return preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $value) ? $value : '';
    }

    /** @return array<string,string|int|float|bool> */
    private static function metadata(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) return [];
        $result = [];
        foreach ($value as $key => $item) {
            if (count($result) >= 10 || !is_string($key) || !preg_match('/^[a-z][a-z0-9_.-]{0,31}$/i', $key) || !is_scalar($item)) continue;
            if (is_string($item)) {
                $item = self::text($item, 256);
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private static function uuid(mixed $value): string
    {
        $value = is_string($value) ? strtolower($value) : '';
        return preg_match('/^[a-f0-9-]{16,64}$/', $value) ? $value : '';
    }

    private static function text(mixed $value, int $maxLength): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int)$value : 0;
        return max($min, min($max, $value));
    }
}
