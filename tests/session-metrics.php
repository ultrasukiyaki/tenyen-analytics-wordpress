<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('ARRAY_A', 'ARRAY_A');
require dirname(__DIR__) . '/includes/class-tya-session-repository.php';

$event = static fn(int $id, string $time, string $type, string $path = '/', int $duration = 0, int $scroll = 0): array => [
    'event_id' => $id, 'occurred_at' => $time, 'event_type' => $type, 'visitor_id' => 'visitor-a',
    'path' => $path, 'duration_ms' => $duration, 'scroll_depth' => $scroll,
];

$single = TYA_Session_Repository::summarizeEvents('session-a', [
    $event(3, '2026-07-25 00:00:02', 'engagement', '/one', 30000, 70),
    $event(1, '2026-07-25 00:00:00', 'pageview', '/one'),
    $event(2, '2026-07-25 00:00:01', 'engagement', '/one', 10000, 30),
]);
if (!$single || !$single['bounce'] || $single['pageviews'] !== 1 || $single['engaged_ms'] !== 30000) {
    throw new RuntimeException('Single-page cumulative engagement summary failed.');
}
if ($single['engagement_by_path']['/one']['scroll_depth'] !== 70) {
    throw new RuntimeException('Maximum cumulative scroll summary failed.');
}

$multi = TYA_Session_Repository::summarizeEvents('session-b', [
    $event(4, '2026-07-25 00:00:01', 'download', '/two'),
    $event(2, '2026-07-25 00:00:00', 'pageview', '/two'),
    $event(1, '2026-07-25 00:00:00', 'pageview', '/one'),
    $event(3, '2026-07-25 00:00:01', 'external_click', '/one'),
]);
if (!$multi || $multi['bounce'] || $multi['pageviews'] !== 2) {
    throw new RuntimeException('Multi-page session summary failed.');
}
if ($multi['events'][0]['event_id'] !== 1 || $multi['events'][2]['event_id'] !== 3) {
    throw new RuntimeException('Timestamp and event-id ordering failed.');
}

if (TYA_Session_Repository::summarizeEvents('', [$event(1, '2026-07-25 00:00:00', 'pageview')]) !== null) {
    throw new RuntimeException('Missing session ID was not excluded.');
}

$zero = TYA_Session_Repository::calculateRates(0, 0, 0, 0, 0);
if ($zero !== ['bounce_rate' => 0.0, 'exit_rate' => 0.0, 'pageviews_per_session' => 0.0]) {
    throw new RuntimeException('Zero denominator handling failed.');
}
$rates = TYA_Session_Repository::calculateRates(2, 4, 3, 12, 4);
if ($rates['bounce_rate'] !== 50.0 || $rates['exit_rate'] !== 25.0 || $rates['pageviews_per_session'] !== 3.0) {
    throw new RuntimeException('Journey metric formulas failed.');
}

echo "Session metric checks passed.\n";
