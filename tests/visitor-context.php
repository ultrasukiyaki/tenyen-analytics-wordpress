<?php

declare(strict_types=1);

define('ARRAY_A', 'ARRAY_A');

function __(string $text, string $domain = ''): string { return $text; }

require dirname(__DIR__) . '/includes/core/src/VisitorContext.php';
require dirname(__DIR__) . '/includes/core/src/ReverseDns.php';
require dirname(__DIR__) . '/includes/core/src/HostnameCache.php';

use Tenyen\Analytics\HostnameCache;
use Tenyen\Analytics\ReverseDns;
use Tenyen\Analytics\VisitorContext;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};

$check(VisitorContext::location(['country_code'=>'jp','country_name'=>'Japan','region'=>'Tokyo','city'=>'Chiyoda']) === 'Japan (JP) / Tokyo / Chiyoda', 'complete location');
$check(VisitorContext::location(['country_code'=>'JP']) === 'JP', 'country code only');
$check(VisitorContext::location(['region'=>'Tokyo','city'=>'Chiyoda']) === 'Tokyo / Chiyoda', 'partial location');
$check(VisitorContext::location([]) === '—', 'missing location');
$check(VisitorContext::organization(['asn'=>12345,'asn_org'=>'Example']) === 'AS12345 Example', 'ASN and organization');
$check(VisitorContext::organization(['asn'=>0]) === '—', 'no AS0');
$check(VisitorContext::organization(['asn'=>null,'asn_org'=>'Example']) === 'Example', 'organization only');
$check(VisitorContext::organization(['asn'=>4294967295]) === 'AS4294967295', '32-bit ASN');
$check(VisitorContext::environment(['os'=>'Linux']) === 'Linux', 'no empty separators');

foreach (['8.8.8.8','2606:4700:4700::1111'] as $ip) $check(ReverseDns::publicAddress($ip), 'public address '.$ip);
foreach (['','invalid','127.0.0.1','10.0.0.1','169.254.1.2','100.64.0.1','192.0.2.1','198.51.100.1','203.0.113.1','224.0.0.1','255.255.255.255','::1','fe80::1','fc00::1','2001:db8::1','::ffff:8.8.8.8'] as $ip) {
    $check(!ReverseDns::publicAddress($ip), 'non-public address rejected '.$ip);
}
$check(ReverseDns::hostname('DNS.Example.COM.') === 'dns.example.com', 'PTR normalization');
foreach (['<script>.com','a..com','-a.com',str_repeat('a',64).'.com','8.8.8.8','a b.com'] as $name) {
    $check(ReverseDns::hostname($name) === '', 'invalid PTR rejected');
}

$wire = static function (string $name): string {
    $result = '';
    foreach (explode('.', $name) as $part) $result .= chr(strlen($part)) . $part;
    return $result . "\0";
};
$question = '8.8.8.8.in-addr.arpa';
$query = $wire($question) . pack('nn', 12, 1);
$ptr = $wire('dns.example.com');
$packet = pack('nnnnnn', 42, 0x8180, 1, 1, 0, 0) . $query . "\xc0\x0c" . pack('nnNn', 12, 1, 60, strlen($ptr)) . $ptr;
$check(ReverseDns::response($packet, 42, $question)['hostname'] === 'dns.example.com', 'compressed PTR response');
$check(ReverseDns::response($packet, 43, $question)['status'] === 'failed', 'transaction ID checked');
$check(ReverseDns::response($packet, 42, '1.1.1.1.in-addr.arpa')['status'] === 'failed', 'question checked');
$negative = pack('nnnnnn', 42, 0x8183, 1, 0, 0, 0) . $query;
$check(ReverseDns::response($negative, 42, $question)['status'] === 'unavailable', 'negative DNS response');
$check(ReverseDns::response(substr($packet, 0, -1), 42, $question)['status'] === 'failed', 'truncated packet rejected');
$servfail = pack('nnnnnn', 42, 0x8182, 1, 0, 0, 0) . $query;
$check(ReverseDns::response($servfail, 42, $question)['status'] === 'failed', 'DNS server failure backoff status');

$future = gmdate('Y-m-d H:i:s', time() + 60);
$check(HostnameCache::display(null) === 'Awaiting hostname lookup', 'uncached pending');
$check(HostnameCache::display(['status'=>'resolved','hostname'=>'DNS.Example.COM','expires_at'=>$future]) === 'dns.example.com', 'cached hostname');
$check(HostnameCache::display(['status'=>'resolved','hostname'=>'<script>','expires_at'=>$future]) === 'Hostname unavailable', 'hostile cached PTR hidden');
foreach (['unavailable','failed'] as $status) {
    $check(HostnameCache::display(['status'=>$status,'hostname'=>'','expires_at'=>$future]) === 'Hostname unavailable', 'negative display '.$status);
}
$check(HostnameCache::display(['status'=>'resolved','hostname'=>'dns.example.com','expires_at'=>'2000-01-01 00:00:00']) === 'Awaiting hostname lookup', 'expired cache');

echo "visitor-context: {$checks} checks passed\n";
