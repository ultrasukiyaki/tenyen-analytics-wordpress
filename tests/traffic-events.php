<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/core/src/TrafficAttribution.php';
require dirname(__DIR__) . '/includes/core/src/Payload.php';

use Tenyen\Analytics\Payload;
use Tenyen\Analytics\TrafficAttribution;

$cases = [
    ['/', '', 'Direct'],
    ['/', 'https://example.test/inside', 'Internal'],
    ['/', 'https://www.google.com/search?q=test', 'Organic Search'],
    ['/', 'https://bing.com/search?q=test', 'Organic Search'],
    ['/', 'https://bsky.app/profile/example', 'Social'],
    ['/', 'https://mastodon.social/@example', 'Social'],
    ['/', 'https://news.example.net/story', 'Referral'],
    ['/landing?utm_campaign=Summer&utm_source=Mail', 'https://google.com/', 'Campaign'],
];
foreach ($cases as [$path, $referrer, $expected]) {
    $actual = TrafficAttribution::fromPage($path, $referrer, 'https://example.test/');
    if ($actual['traffic_channel'] !== $expected) {
        throw new RuntimeException("Expected {$expected}, got {$actual['traffic_channel']}");
    }
}

$utm = TrafficAttribution::utm('/?UTM_SOURCE=First&utm_source=Second&utm_campaign=' . str_repeat('x', 400));
if ($utm['utm_source'] !== 'First' || strlen($utm['utm_campaign']) !== 256) {
    throw new RuntimeException('UTM normalization failed.');
}

$custom = Payload::normalize([
    'event' => 'custom',
    'event_name' => 'radio_play',
    'metadata' => ['station' => 'example', 'nested' => ['not' => 'allowed'], 'callback' => static fn() => null],
]);
if ($custom['event_name'] !== 'radio_play' || $custom['event_meta'] !== ['station' => 'example']) {
    throw new RuntimeException('Custom event normalization failed.');
}

$invalid = Payload::normalize(['event' => 'custom', 'event_name' => '<script>bad</script>']);
if ($invalid['event_name'] !== '') {
    throw new RuntimeException('Invalid custom event name was accepted.');
}

echo "traffic-events: ok\n";
