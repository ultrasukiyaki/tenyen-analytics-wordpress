<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('TYA_VERSION', '0.8.1');
define('AUTH_KEY', 'test-auth-key-that-is-not-a-real-secret');
define('SECURE_AUTH_SALT', 'test-secure-salt-that-is-not-a-real-secret');

$testRoot = sys_get_temp_dir() . '/tya-geolite-test-' . bin2hex(random_bytes(4));
mkdir($testRoot, 0700, true);
$GLOBALS['geo_options'] = [
    'tya_city_db' => $testRoot . '/GeoLite2-City.mmdb',
    'tya_asn_db' => $testRoot . '/GeoLite2-ASN.mmdb',
];
$GLOBALS['geo_scheduled'] = [];
$GLOBALS['geo_actions'] = [];
$GLOBALS['geo_filters'] = [];
$GLOBALS['geo_routes'] = [];
$GLOBALS['geo_fail_download'] = [];
$GLOBALS['geo_fail_extract'] = [];
$GLOBALS['geo_wrong_type'] = [];
$GLOBALS['geo_corrupt'] = [];
$GLOBALS['geo_downloads'] = [];
$GLOBALS['geo_generation'] = 0;
$GLOBALS['geo_build_epoch'] = time();
$GLOBALS['geo_can_manage'] = true;

function __(string $value, string $domain = ''): string { return $value; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['geo_options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['geo_options'][$key] = $value; return true; }
function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool { if (array_key_exists($key, $GLOBALS['geo_options'])) return false; $GLOBALS['geo_options'][$key] = $value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['geo_options'][$key]); return true; }
function add_action(string $hook, callable $callback): void { $GLOBALS['geo_actions'][$hook][] = $callback; }
function add_filter(string $hook, callable $callback): void { $GLOBALS['geo_filters'][$hook][] = $callback; }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['geo_scheduled'][$hook] ?? false; }
function wp_schedule_event(int $time, string $recurrence, string $hook): bool { $GLOBALS['geo_scheduled'][$hook] = $time; return true; }
function wp_schedule_single_event(int $time, string $hook): bool { $GLOBALS['geo_scheduled'][$hook] = $time; return true; }
function wp_unschedule_event(int $time, string $hook): bool { unset($GLOBALS['geo_scheduled'][$hook]); return true; }
function wp_generate_uuid4(): string { static $i = 0; return 'test-' . ++$i; }
function wp_mkdir_p(string $directory): bool { return is_dir($directory) || mkdir($directory, 0700, true); }
function wp_salt(string $scheme): string { return 'test-' . $scheme . '-salt'; }
function rest_sanitize_boolean(mixed $value): bool { return filter_var($value, FILTER_VALIDATE_BOOLEAN); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? ''; }
function current_user_can(string $capability): bool { return $capability === 'manage_options' && $GLOBALS['geo_can_manage']; }
function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['geo_routes'][$namespace . $route] = $args; }

final class WP_REST_Server { public const READABLE = 'GET'; public const CREATABLE = 'POST'; }
final class WP_REST_Request
{
    public function __construct(private array $input = []) {}
    public function get_json_params(): array { return $this->input; }
}
final class WP_REST_Response
{
    public function __construct(public mixed $data, public int $status = 200) {}
}

require dirname(__DIR__) . '/includes/core/src/Crypto.php';
require dirname(__DIR__) . '/includes/class-tya-geolite-updater.php';

function assertGeo(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$download = static function (string $edition, string $destination, string $account, string $license): void {
    $type = str_ends_with($edition, 'City') ? 'city' : 'asn';
    $GLOBALS['geo_downloads'][] = [$edition, $account, $license];
    if (($GLOBALS['geo_fail_download'][$type] ?? false) === true) throw new RuntimeException('The MaxMind download request failed.');
    file_put_contents($destination, str_repeat('A', 128));
};
$extract = static function (string $archive, string $expected, string $destination): void {
    $type = str_contains($expected, 'City') ? 'city' : 'asn';
    if (($GLOBALS['geo_fail_extract'][$type] ?? false) === true) throw new RuntimeException('The expected MMDB file is missing from the archive.');
    $GLOBALS['geo_generation']++;
    file_put_contents($destination, str_repeat($type === 'city' ? 'C' : 'N', 256) . ':' . $GLOBALS['geo_generation']);
};
$inspect = static function (string $path): array {
    $type = str_contains(basename($path), 'City') ? 'city' : 'asn';
    if (($GLOBALS['geo_corrupt'][$type] ?? false) === true) throw new RuntimeException('corrupt input with /private/path and secret');
    $databaseType = $type === 'city' ? 'GeoLite2-City' : 'GeoLite2-ASN';
    if (($GLOBALS['geo_wrong_type'][$type] ?? false) === true) $databaseType = $type === 'city' ? 'GeoLite2-ASN' : 'GeoLite2-City';
    return ['database_type' => $databaseType, 'build_epoch' => $GLOBALS['geo_build_epoch']];
};

$updater = new TYA_GeoLite_Updater($download, $extract, $inspect);
$updater->boot();
$updater->registerRoutes();
assertGeo(TYA_GeoLite_Updater::cronSchedules([])['tya_weekly']['interval'] === 7 * DAY_IN_SECONDS, 'GeoLite2 automatic update schedule is not conservative and weekly.');
$downloadError = new ReflectionMethod(TYA_GeoLite_Updater::class, 'downloadError');
assertGeo(str_contains($downloadError->invoke($updater, 401), 'account ID or license key'), 'A MaxMind authentication failure was not identified correctly.');
assertGeo(str_contains($downloadError->invoke($updater, 403), 'not permitted'), 'A MaxMind product-permission failure was incorrectly reported as invalid credentials.');
assertGeo(str_contains($downloadError->invoke($updater, 429), 'rate-limited'), 'A MaxMind rate limit was not identified correctly.');
assertGeo(str_contains($downloadError->invoke($updater, 404), '404'), 'An unexpected MaxMind HTTP status was hidden from diagnostics.');
$downloadRedirect = new ReflectionMethod(TYA_GeoLite_Updater::class, 'validDownloadRedirect');
assertGeo($downloadRedirect->invoke($updater, 'https://mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com/file?signature=test'), 'The documented MaxMind R2 download host was rejected.');
assertGeo(!$downloadRedirect->invoke($updater, 'https://example.com/file?signature=test'), 'An untrusted download redirect host was accepted.');
assertGeo(!$downloadRedirect->invoke($updater, 'http://mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com/file'), 'A non-HTTPS download redirect was accepted.');
assertGeo($updater->status()['databases']['city']['health'] === 'missing' && $updater->status()['databases']['asn']['health'] === 'missing', 'Missing database health was not detected independently.');
assertGeo(isset($GLOBALS['geo_actions'][TYA_GeoLite_Updater::CRON_HOOK], $GLOBALS['geo_actions'][TYA_GeoLite_Updater::RETRY_HOOK]), 'GeoLite2 Cron hooks were not registered.');
foreach (['/admin/geolite/status', '/admin/geolite/settings', '/admin/geolite/update'] as $route) {
    assertGeo(isset($GLOBALS['geo_routes']['tenyen-analytics/v1' . $route]), 'GeoLite2 REST route was not registered: ' . $route);
}

$invalidAccount = $updater->settingsRest(new WP_REST_Request(['account_id' => 'abc', 'license_key' => 'abcdefgh', 'automatic' => true]));
assertGeo($invalidAccount->status === 400, 'Invalid account ID was accepted.');
$invalidKey = $updater->settingsRest(new WP_REST_Request(['account_id' => '12345', 'license_key' => 'bad key', 'automatic' => true]));
assertGeo($invalidKey->status === 400, 'Invalid license key was accepted.');
$missingCredentials = $updater->settingsRest(new WP_REST_Request(['account_id' => '', 'license_key' => '', 'automatic' => true]));
assertGeo($missingCredentials->status === 400, 'Automatic update was enabled without credentials.');

$settings = $updater->settingsRest(new WP_REST_Request(['account_id' => '12345', 'license_key' => 'license_key_123456', 'automatic' => true]));
assertGeo($settings->status === 200 && get_option('tya_geolite_auto_update') === 1, 'Valid automatic update settings were rejected.');
assertGeo(str_starts_with((string)get_option('tya_maxmind_license_key'), 'v1:') && !str_contains((string)get_option('tya_maxmind_license_key'), 'license_key_123456'), 'License key was not stored as a versioned encrypted value.');
assertGeo(($settings->data['geolite']['license_key_masked'] ?? '') === '••••••••' && !str_contains(json_encode($settings->data), 'license_key_123456'), 'License key was exposed by the status response.');
$validEncryptedKey = get_option('tya_maxmind_license_key');
$GLOBALS['geo_options']['tya_maxmind_license_key'] = 'v1:not-valid-base64!';
assertGeo($updater->status()['credentials_configured'] === false, 'Corrupt encrypted credentials were reported as usable.');
$GLOBALS['geo_options']['tya_maxmind_license_key'] = $validEncryptedKey;
assertGeo(isset($GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::CRON_HOOK]), 'Enabling automatic updates did not schedule the weekly job.');

file_put_contents(get_option('tya_city_db'), str_repeat('O', 128));
file_put_contents(get_option('tya_asn_db'), str_repeat('O', 128));
$success = $updater->runUpdate('all');
assertGeo($success['status'] === 'complete', 'Successful City and ASN update did not complete.');
$invalidSelection = $updater->updateRest(new WP_REST_Request(['database' => 'arbitrary']));
assertGeo($invalidSelection->status === 400, 'Arbitrary GeoLite2 update selection was accepted.');
assertGeo(str_starts_with(file_get_contents(get_option('tya_city_db')), 'C') && str_starts_with(file_get_contents(get_option('tya_asn_db')), 'N'), 'Validated databases were not atomically activated.');
assertGeo(!is_file(get_option('tya_city_db') . '.tya-backup') && !is_file(get_option('tya_asn_db') . '.tya-backup'), 'Successful update left backup files behind.');
$state = get_option(TYA_GeoLite_Updater::STATE_OPTION);
assertGeo($state['city']['status'] === 'current' && $state['asn']['status'] === 'current', 'City and ASN state was not tracked independently.');
$health = $updater->status()['databases'];
assertGeo($health['city']['health'] === 'current' && $health['city']['path'] === 'GeoLite2-City.mmdb' && $health['city']['build_date'] !== '' && $health['city']['size'] > 0, 'Current City health metadata is incomplete.');
$GLOBALS['geo_build_epoch'] = time() - 46 * DAY_IN_SECONDS;
assertGeo($updater->status()['databases']['city']['health'] === 'stale', 'Stale MMDB build date was not detected.');
$GLOBALS['geo_build_epoch'] = time();

$oldCity = file_get_contents(get_option('tya_city_db'));
$GLOBALS['geo_fail_download']['city'] = true;
$partial = $updater->runUpdate('all');
assertGeo($partial['status'] === 'partial' && $partial['results']['city']['status'] === 'failed' && $partial['results']['asn']['status'] === 'current', 'City-only failure invalidated ASN or was not reported independently.');
assertGeo(file_get_contents(get_option('tya_city_db')) === $oldCity, 'Working City DB was overwritten after download failure.');
unset($GLOBALS['geo_fail_download']['city']);

$oldAsn = file_get_contents(get_option('tya_asn_db'));
$GLOBALS['geo_fail_extract']['asn'] = true;
$partial = $updater->runUpdate('all');
assertGeo($partial['status'] === 'partial' && $partial['results']['city']['status'] === 'current' && $partial['results']['asn']['status'] === 'failed', 'ASN-only failure invalidated City or was not reported independently.');
assertGeo(file_get_contents(get_option('tya_asn_db')) === $oldAsn, 'Working ASN DB was overwritten when the expected MMDB was missing.');
unset($GLOBALS['geo_fail_extract']['asn']);

$shortDownload = static function (string $edition, string $destination): void { file_put_contents($destination, 'invalid'); };
$invalidArchiveUpdater = new TYA_GeoLite_Updater($shortDownload, $extract, $inspect);
$oldCity = file_get_contents(get_option('tya_city_db'));
$invalidArchive = $invalidArchiveUpdater->runUpdate('city');
assertGeo($invalidArchive['status'] === 'partial' && file_get_contents(get_option('tya_city_db')) === $oldCity, 'Invalid archive replaced the working City DB.');

$GLOBALS['geo_wrong_type']['city'] = true;
assertGeo($updater->status()['databases']['city']['health'] === 'wrong_type', 'Wrong installed MMDB type was not detected by health checks.');
$wrong = $updater->runUpdate('city');
assertGeo($wrong['status'] === 'partial' && file_get_contents(get_option('tya_city_db')) === $oldCity, 'Wrong database type replaced the working City DB.');
unset($GLOBALS['geo_wrong_type']['city']);
$GLOBALS['geo_corrupt']['city'] = true;
assertGeo($updater->status()['databases']['city']['health'] === 'corrupt', 'Corrupt installed MMDB was not detected by health checks.');
$corrupt = $updater->runUpdate('city');
assertGeo($corrupt['status'] === 'partial' && file_get_contents(get_option('tya_city_db')) === $oldCity, 'Corrupt MMDB replaced the working City DB.');
assertGeo(!str_contains((string)$corrupt['results']['city']['error'], '/private/path'), 'Unsafe validation detail leaked into status.');
unset($GLOBALS['geo_corrupt']['city']);

$stale = $testRoot . '/.tya-geolite-stale';
mkdir($stale, 0700); file_put_contents($stale . '/partial', 'partial'); touch($stale, time() - 2 * DAY_IN_SECONDS);
$updater->runUpdate('city');
assertGeo(!is_dir($stale), 'Stale temporary update directory was not cleaned.');

assertGeo(TYA_GeoLite_Updater::safeArchivePath('GeoLite2-City_20260825/GeoLite2-City.mmdb'), 'Safe archive path was rejected.');
foreach (['../GeoLite2-City.mmdb', '/tmp/GeoLite2-City.mmdb', 'C:/temp/GeoLite2-City.mmdb', "safe/\0bad"] as $unsafe) {
    assertGeo(!TYA_GeoLite_Updater::safeArchivePath($unsafe), 'Archive traversal or absolute path was accepted.');
}

$validTar = $testRoot . '/valid.tar';
$validPhar = new PharData($validTar);
$validPhar->addFromString('GeoLite2-City_20260825/GeoLite2-City.mmdb', str_repeat('M', 256));
unset($validPhar);
$extractMethod = new ReflectionMethod(TYA_GeoLite_Updater::class, 'extract');
$extractMethod->setAccessible(true);
$extractedFixture = $testRoot . '/extracted-city.fixture';
$extractMethod->invoke(new TYA_GeoLite_Updater(), $validTar, 'GeoLite2-City.mmdb', $extractedFixture);
clearstatcache(true, $extractedFixture);
assertGeo(filesize($extractedFixture) === 256, 'A bounded archive with the expected MMDB could not be extracted.');

$missingTar = $testRoot . '/missing.tar';
$missingPhar = new PharData($missingTar);
$missingPhar->addFromString('GeoLite2-City_20260825/README.txt', str_repeat('R', 128));
unset($missingPhar);
$missingRejected = false;
try { $extractMethod->invoke(new TYA_GeoLite_Updater(), $missingTar, 'GeoLite2-City.mmdb', $testRoot . '/missing.fixture'); }
catch (Throwable $error) { $missingRejected = str_contains($error->getMessage(), 'expected MMDB'); }
assertGeo($missingRejected, 'Archive without the expected MMDB was accepted.');

$invalidTar = $testRoot . '/invalid.tar.gz';
file_put_contents($invalidTar, str_repeat('X', 128));
$invalidRejected = false;
try { $extractMethod->invoke(new TYA_GeoLite_Updater(), $invalidTar, 'GeoLite2-City.mmdb', $testRoot . '/invalid.fixture'); }
catch (Throwable $error) { $invalidRejected = str_contains($error->getMessage(), 'archive is invalid'); }
assertGeo($invalidRejected, 'Invalid archive data was accepted.');

$GLOBALS['geo_options'][TYA_GeoLite_Updater::LOCK_OPTION] = ['token' => 'other', 'expires' => time() + 60];
assertGeo($updater->runUpdate('all')['status'] === 'locked', 'Concurrent GeoLite2 update lock was ignored.');
unset($GLOBALS['geo_options'][TYA_GeoLite_Updater::LOCK_OPTION]);

$GLOBALS['geo_fail_download']['city'] = true;
$updater->runUpdate('city');
assertGeo(isset($GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::RETRY_HOOK]), 'Failed automatic update did not schedule bounded retry.');
$firstRetryDelay = $GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::RETRY_HOOK] - time();
assertGeo($firstRetryDelay >= HOUR_IN_SECONDS - 2 && $firstRetryDelay <= HOUR_IN_SECONDS + 2, 'First retry did not use the one-hour backoff.');
unset($GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::RETRY_HOOK]);
$updater->runUpdate('city');
$secondRetryDelay = $GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::RETRY_HOOK] - time();
assertGeo($secondRetryDelay >= 6 * HOUR_IN_SECONDS - 2 && $secondRetryDelay <= 6 * HOUR_IN_SECONDS + 2, 'Repeated failure did not increase bounded retry backoff.');
unset($GLOBALS['geo_fail_download']['city']);
$manualCityPath = get_option('tya_city_db');
$disabled = $updater->settingsRest(new WP_REST_Request(['account_id' => '12345', 'license_key' => '', 'automatic' => false]));
assertGeo($disabled->status === 200 && !isset($GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::CRON_HOOK], $GLOBALS['geo_scheduled'][TYA_GeoLite_Updater::RETRY_HOOK]), 'Disabling automatic updates left scheduled jobs active.');
assertGeo(get_option('tya_city_db') === $manualCityPath && $updater->status()['databases']['city']['installed'], 'Manual MMDB path compatibility was lost.');

$clearWhileEnabled = $updater->settingsRest(new WP_REST_Request(['account_id' => '12345', 'license_key' => '', 'clear_license_key' => true, 'automatic' => true]));
assertGeo($clearWhileEnabled->status === 400, 'Saved license key could be cleared while automatic updates remained enabled.');
$cleared = $updater->settingsRest(new WP_REST_Request(['account_id' => '12345', 'license_key' => '', 'clear_license_key' => true, 'automatic' => false]));
assertGeo($cleared->status === 200 && get_option('tya_maxmind_license_key', '') === '', 'License key removal failed.');

$GLOBALS['geo_can_manage'] = false;
foreach (['/admin/geolite/status', '/admin/geolite/settings', '/admin/geolite/update'] as $route) {
    $definition = $GLOBALS['geo_routes']['tenyen-analytics/v1' . $route];
    assertGeo(($definition['permission_callback'])() === false, 'Unauthorized GeoLite2 REST access was allowed.');
}

$source = file_get_contents(dirname(__DIR__) . '/includes/class-tya-geolite-updater.php');
assertGeo(!str_contains($source, 'error_log('), 'GeoLite2 updater may write secrets or request details to logs.');
$ui = file_get_contents(dirname(__DIR__) . '/assets/admin-geolite.js');
assertGeo(str_contains($ui, 'textContent') && !str_contains($ui, 'innerHTML'), 'GeoLite2 status UI does not use safe text rendering.');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
rmdir($testRoot);

echo "geolite-updater: ok\n";
