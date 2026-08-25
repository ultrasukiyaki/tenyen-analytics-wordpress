<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/core/src/ExclusionRuleEngine.php';

use Tenyen\Analytics\ExclusionRuleEngine;

function rule(int $id, string $type, string $value, string $scope = 'collection', bool $enabled = true): array
{
    $valid = ExclusionRuleEngine::validate($type, $value, $scope);
    if (!$valid['valid']) throw new RuntimeException("Fixture rejected: {$type} {$value}: {$valid['error']}");
    return ['rule_id'=>$id,'type'=>$type,'value'=>$valid['value'],'scope'=>$scope,'enabled'=>$enabled];
}
function excluded(array $rules, array $context, string $scope = 'collection'): array
{
    return ExclusionRuleEngine::diagnose($rules, $context, $scope);
}
function assertTrue(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

foreach (['192.0.2.4','2001:db8::4'] as $ip) {
    assertTrue(excluded([rule(1,'ip_exact',$ip)],['ip'=>$ip])['excluded'], "Exact IP failed: {$ip}");
}
$ipv4 = rule(2,'ip_cidr','192.0.2.129/24');
assertTrue($ipv4['value']==='192.0.2.0/24', 'IPv4 CIDR was not canonicalized.');
assertTrue(excluded([$ipv4],['ip'=>'192.0.2.0'])['excluded'], 'IPv4 CIDR lower boundary failed.');
assertTrue(excluded([$ipv4],['ip'=>'192.0.2.255'])['excluded'], 'IPv4 CIDR upper boundary failed.');
assertTrue(!excluded([$ipv4],['ip'=>'192.0.3.0'])['excluded'], 'IPv4 CIDR exceeded boundary.');
$ipv6 = rule(3,'ip_cidr','2001:db8::9/64');
assertTrue(excluded([$ipv6],['ip'=>'2001:db8::ffff'])['excluded'], 'IPv6 CIDR boundary failed.');
assertTrue(!excluded([$ipv6],['ip'=>'2001:db8:0:1::1'])['excluded'], 'IPv6 CIDR exceeded boundary.');
assertTrue(!ExclusionRuleEngine::validate('ip_cidr','192.0.2.0/33','collection')['valid'], 'Invalid IPv4 CIDR accepted.');
assertTrue(!ExclusionRuleEngine::validate('ip_cidr','2001:db8::/129','collection')['valid'], 'Invalid IPv6 CIDR accepted.');
assertTrue(!ExclusionRuleEngine::validate('ip_cidr','192.0.2.0/24','analysis')['valid'], 'Analysis CIDR must be rejected.');

$paths = [rule(4,'path_exact','https://example.test/private?x=1'),rule(5,'path_prefix','/internal/')];
assertTrue(excluded($paths,['path'=>'/private?else=2'])['rule_id']===4, 'Exact path semantics failed.');
assertTrue(excluded($paths,['path'=>'/internal/report'])['rule_id']===5, 'Path prefix semantics failed.');
assertTrue(!excluded($paths,['path'=>'/intern'])['excluded'], 'Path prefix overmatched.');

$environment = [
    rule(6,'administrator','1'), rule(7,'bot','1'), rule(8,'country','jp'),
    rule(9,'region','Tokyo'), rule(10,'asn','AS2516'), rule(11,'organization','Example Corp'),
    rule(12,'category','research'), rule(13,'browser','Chrome'), rule(14,'os','Windows'),
    rule(15,'device','mobile'), rule(16,'referrer_domain','https://NEWS.Example/one'),
    rule(17,'utm_source','Newsletter'), rule(18,'utm_medium','Email'), rule(19,'utm_campaign','Launch'),
];
foreach ([
    ['administrator'=>true], ['bot'=>true], ['country'=>'JP'], ['region'=>'Tokyo'], ['asn'=>2516],
    ['organization'=>'EXAMPLE CORP'], ['category'=>'research'], ['browser'=>'CHROME'], ['os'=>'windows'],
    ['device'=>'mobile'], ['referrer_domain'=>'news.example'], ['utm_source'=>'newsletter'],
    ['utm_medium'=>'email'], ['utm_campaign'=>'launch'],
] as $index => $context) assertTrue(excluded([$environment[$index]],$context)['excluded'], 'Environment rule failed at ' . $index);

$disabled = rule(20,'bot','1','collection',false);
assertTrue(!excluded([$disabled],['bot'=>true])['excluded'], 'Disabled rule matched.');
$conflict = excluded([rule(50,'path_prefix','/'),rule(99,'ip_exact','192.0.2.1')],['path'=>'/','ip'=>'192.0.2.1']);
assertTrue($conflict['rule_id']===99 && $conflict['precedence']===10 && $conflict['action']==='exclude', 'Deterministic precedence failed.');
$analysis = rule(21,'organization','Hidden Org','analysis');
assertTrue(!excluded([$analysis],['organization'=>'Hidden Org'],'collection')['excluded'], 'Analysis rule affected collection.');
assertTrue(excluded([$analysis],['organization'=>'Hidden Org'],'analysis')['excluded'], 'Analysis rule did not hide matching history.');
assertTrue(excluded([$analysis],['organization'=>'Other'],'analysis')['action']==='include', 'Diagnostic include result failed.');
assertTrue(!ExclusionRuleEngine::validate('organization','<script>alert(1)</script>','collection')['valid'], 'XSS value accepted.');

$pluginSource = (string)file_get_contents(dirname(__DIR__) . '/includes/class-tya-plugin.php');
$diagnosticPosition = strpos($pluginSource, '$this->exclusions()->collectionDiagnostic(');
$insertPosition = strpos($pluginSource, '$wpdb->insert(', $diagnosticPosition ?: 0);
assertTrue($diagnosticPosition !== false && $insertPosition !== false && $diagnosticPosition < $insertPosition, 'Collection exclusion is not evaluated before storage.');
$managerSource = (string)file_get_contents(dirname(__DIR__) . '/includes/class-tya-exclusions.php');
assertTrue(!str_contains($managerSource, 'TYA_Installer::tableName()'), 'Exclusion management must not mutate historical events.');

$large = [];
for ($i=1;$i<=2000;$i++) $large[]=rule($i,'asn',(string)$i);
assertTrue(excluded($large,['asn'=>2000])['rule_id']===2000, 'Large rule-set match failed.');

echo "exclusions: ok\n";
