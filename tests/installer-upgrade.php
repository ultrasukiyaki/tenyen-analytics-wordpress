<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['tya_upgrade_options']=['tya_schema_version'=>'0.6.3','tya_metadata_sentinel'=>'preserve','tya_saved_view_sentinel'=>'preserve','tya_site_token'=>'existing-token'];
$GLOBALS['tya_upgrade_scheduled']=[];$GLOBALS['tya_dbdelta']=[];
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['tya_upgrade_options'][$key]??$default;}
function update_option(string $key,mixed $value):bool{$GLOBALS['tya_upgrade_options'][$key]=$value;return true;}
function add_option(string $key,mixed $value):bool{if(array_key_exists($key,$GLOBALS['tya_upgrade_options']))return false;$GLOBALS['tya_upgrade_options'][$key]=$value;return true;}
function wp_generate_password():string{return 'new-token';}
function wp_upload_dir():array{return ['basedir'=>'/tmp/tya-upgrade-test'];}
function trailingslashit(string $path):string{return rtrim($path,'/').'/';}
function wp_mkdir_p(string $path):bool{return true;}
function wp_next_scheduled(string $hook):int|false{return $GLOBALS['tya_upgrade_scheduled'][$hook]??false;}
function wp_schedule_event(int $time,string $recurrence,string $hook):bool{$GLOBALS['tya_upgrade_scheduled'][$hook]=$time;return true;}
function wp_unschedule_event(int $time,string $hook):bool{unset($GLOBALS['tya_upgrade_scheduled'][$hook]);return true;}
final class UpgradeWpdb{public string $prefix='wp_';public function get_charset_collate():string{return 'DEFAULT CHARACTER SET utf8mb4';}}
$GLOBALS['wpdb']=new UpgradeWpdb();
require dirname(__DIR__).'/includes/class-tya-installer.php';
TYA_Installer::maybeUpgrade();
if(get_option('tya_schema_version')!=='0.7.1')throw new RuntimeException('v0.7.0 baseline did not upgrade to the v0.7.1 schema.');
if(!str_contains(implode("\n",$GLOBALS['tya_dbdelta']),'wp_tya_daily_aggregates')||!str_contains(implode("\n",$GLOBALS['tya_dbdelta']),'wp_tya_daily_dimensions'))throw new RuntimeException('Aggregate tables were not included in dbDelta.');
if(get_option('tya_metadata_sentinel')!=='preserve'||get_option('tya_saved_view_sentinel')!=='preserve'||get_option('tya_site_token')!=='existing-token')throw new RuntimeException('Baseline upgrade changed metadata, saved views, or keys.');
if(!isset($GLOBALS['tya_upgrade_scheduled']['tya_daily_aggregation'],$GLOBALS['tya_upgrade_scheduled']['tya_daily_cleanup']))throw new RuntimeException('Upgrade did not schedule aggregation and cleanup.');
echo "installer-upgrade: ok\n";
