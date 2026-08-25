<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('AUTH_KEY', 'lifecycle-test-auth-key');
define('SECURE_AUTH_SALT', 'lifecycle-test-secure-salt');

$GLOBALS['tya_options'] = ['tya_retention_days'=>90, 'tya_annotation_sentinel'=>'preserve'];
$GLOBALS['tya_scheduled'] = ['tya_daily_cleanup'=>time()+3600];
$GLOBALS['tya_actions'] = [];

function __(string $value): string { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower($value)) ?? ''; }
function wp_unslash(string $value): string { return stripslashes($value); }
function wp_json_encode(mixed $value, int $flags=0): string|false { return json_encode($value,$flags); }
function get_option(string $key, mixed $default=false): mixed { return $GLOBALS['tya_options'][$key] ?? $default; }
function update_option(string $key, mixed $value): bool { $GLOBALS['tya_options'][$key]=$value; return true; }
function add_option(string $key, mixed $value): bool { if(array_key_exists($key,$GLOBALS['tya_options']))return false; $GLOBALS['tya_options'][$key]=$value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['tya_options'][$key]); return true; }
function wp_generate_uuid4(): string { static $id=0; return 'test-token-' . ++$id; }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['tya_scheduled'][$hook] ?? false; }
function wp_schedule_single_event(int $time, string $hook): bool { $GLOBALS['tya_scheduled'][$hook]=$time; return true; }
function wp_schedule_event(int $time, string $recurrence, string $hook): bool { $GLOBALS['tya_scheduled'][$hook]=$time; return true; }
function add_action(string $hook, callable $callback): void { $GLOBALS['tya_actions'][$hook][]=$callback; }

class WP_REST_Request { public function __construct(private array $json=[]) {} public function get_json_params(): array { return $this->json; } }
class WP_REST_Response { public function __construct(public mixed $data, public int $status=200) {} public function header(string $name, string $value): void {} }

final class TYA_Installer { public static function tableName(): string { return 'wp_tya_events'; } public static function dailyAggregatesTable(): string { return 'wp_tya_daily'; } public static function annotationsTable(): string { return 'wp_tya_annotations'; } public static function entityTagsTable(): string { return 'wp_tya_entity_tags'; } }
final class TYA_Plugin { public static function instance(): self { static $instance; return $instance ??= new self(); } public function analysisWhere(string $alias=''): string { return ' AND NOT (' . $alias . '.is_bot=1)'; } }
final class TYA_Aggregation { public function coverageBefore(string $cutoff): array { $complete=$GLOBALS['tya_coverage_complete']??true; return ['complete'=>$complete,'cutoff'=>substr($cutoff,0,10).' 00:00:00','missing_days'=>$complete?0:1,'message'=>$complete?'':'Cleanup is blocked until every affected UTC day has a current aggregate.']; } public function boundary(string $start,string $end,string $actor): array { return ['aggregate_days'=>['2026-08-01'],'raw_ranges'=>[],'source'=>'aggregate']; } public function dimensions(string $type,string $start,string $end,string $actor,int $limit,array $filters=[]): array { return $GLOBALS['tya_aggregate_dimensions']??[]; } }

final class LifecycleWpdb
{
    /** @var array<int,int> */ public array $expired = [];
    public int $deleted = 0;
    public array $exportRows = [];
    public string $lastQuery = '';
    public bool $failDelete = false;
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string)$arg : "'" . str_replace("'", "''", (string)$arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }
    public function get_col(string $query): array { return array_slice($this->expired,0,1000); }
    public function query(string $query): int|false
    {
        if ($this->failDelete) return false;
        if (!preg_match('/IN \(([^)]+)\)/',$query,$match)) return 0;
        $ids=array_map('intval',explode(',',$match[1])); $before=count($this->expired);
        $this->expired=array_values(array_diff($this->expired,$ids)); $count=$before-count($this->expired); $this->deleted+=$count; return $count;
    }
    public function get_var(string $query): int|string { if(str_contains($query,'MIN(aggregate_day)')||str_contains($query,'DATE(MIN(occurred_at))'))return '2026-08-01';return str_contains($query,'information_schema') ? 4096 : count($this->expired); }
    public function get_row(string $query, string $output): array
    {
        if (str_starts_with($query,'SHOW TABLE STATUS')) return ['Data_length'=>1024,'Index_length'=>512];
        if (str_contains($query,'MIN(occurred_at)')) return ['events'=>count($this->expired),'sessions'=>count($this->expired)>0?3:0,'oldest'=>'2026-01-01 00:00:00','newest'=>'2026-08-25 00:00:00'];
        return ['events'=>count($this->expired),'sessions'=>count($this->expired)>0?3:0];
    }
    public function get_results(string $query, string $output): array
    {
        if (str_contains($query,'DATE_FORMAT(occurred_at')) return [['month'=>'2026-08','events'=>4,'sessions'=>2]];
        $this->lastQuery=$query;
        preg_match('/event_id>(\d+)/',$query,$match); $cursor=(int)($match[1]??0);
        return array_values(array_filter($this->exportRows,static fn(array $row):bool=>(int)$row['event_id']>$cursor));
    }
}

function assertLifecycle(bool $condition, string $message): void { if(!$condition)throw new RuntimeException($message); }

$GLOBALS['wpdb'] = new LifecycleWpdb();
require dirname(__DIR__) . '/includes/core/src/Crypto.php';
require dirname(__DIR__) . '/includes/class-tya-lifecycle.php';

assertLifecycle(TYA_Lifecycle::sanitizeRetention(0)===0,'Unlimited retention failed.');
assertLifecycle(TYA_Lifecycle::sanitizeRetention(90)===90,'Retention preset failed.');
assertLifecycle(TYA_Lifecycle::sanitizeRetention(99999)===3650,'Retention upper bound failed.');
assertLifecycle(TYA_Lifecycle::csvCell('=SUM(A1:A2)')==="'=SUM(A1:A2)",'CSV formula injection was not neutralized.');
assertLifecycle(TYA_Lifecycle::csvCell(" \t@cmd")==="' \t@cmd",'Whitespace-prefixed CSV formula was not neutralized.');
assertLifecycle(TYA_Lifecycle::csvCell('日本語')==='日本語','Unicode CSV field changed.');
assertLifecycle(TYA_Lifecycle::exportRequestError('events','csv','omit',false)==='','Default export request was rejected.');
assertLifecycle(TYA_Lifecycle::exportRequestError('events','json','raw',false)==='raw_confirmation','Unconfirmed raw-IP export was accepted.');
assertLifecycle(TYA_Lifecycle::exportRequestError('unknown','csv','omit',false)==='invalid','Unknown export dataset was accepted.');
assertLifecycle(TYA_Lifecycle::maskIp('192.0.2.129')==='192.0.2.0','IPv4 masking failed.');
assertLifecycle(TYA_Lifecycle::maskIp('2001:db8:abcd:1234::1')==='2001:db8:abcd::','IPv6 masking failed.');

$lifecycle = new TYA_Lifecycle();
unset($GLOBALS['tya_scheduled']['tya_daily_cleanup']); $lifecycle->boot();
assertLifecycle(isset($GLOBALS['tya_scheduled']['tya_daily_cleanup']) && isset($GLOBALS['tya_actions']['tya_daily_cleanup']) && isset($GLOBALS['tya_actions']['admin_post_tya_export']),'Lifecycle scheduling and export hooks failed.');
$crypto=new Tenyen\Analytics\Crypto(AUTH_KEY . '|tenyen-ip', SECURE_AUTH_SALT . '|tenyen-hmac');
$eventRow=new ReflectionMethod($lifecycle,'eventExportRow'); $eventRow->setAccessible(true);
$encrypted=$crypto->encryptIp('192.0.2.129');
$maskedRow=$eventRow->invoke($lifecycle,['ip_encrypted'=>$encrypted],'masked');
$rawRow=$eventRow->invoke($lifecycle,['ip_encrypted'=>$encrypted],'raw');
$omittedRow=$eventRow->invoke($lifecycle,['ip_encrypted'=>$encrypted],'omit');
assertLifecycle($maskedRow['ip']==='192.0.2.0' && $rawRow['ip']==='192.0.2.129' && !array_key_exists('ip',$omittedRow),'IP export privacy modes failed.');
$preset=$lifecycle->retentionRest(new WP_REST_Request(['mode'=>'preset','days'=>30]));
assertLifecycle($preset->status===200 && get_option('tya_retention_days')===30,'Retention preset save failed.');
$custom=$lifecycle->retentionRest(new WP_REST_Request(['mode'=>'custom','days'=>7]));
assertLifecycle($custom->status===200 && get_option('tya_retention_days')===7,'Custom retention save failed.');
$invalidCustom=$lifecycle->retentionRest(new WP_REST_Request(['mode'=>'custom','days'=>0]));
assertLifecycle($invalidCustom->status===400,'Invalid custom retention was accepted.');
$invalidTypedCustom=$lifecycle->retentionRest(new WP_REST_Request(['mode'=>'custom','days'=>'7days']));
assertLifecycle($invalidTypedCustom->status===400,'Malformed custom retention was accepted.');
$unlimitedSave=$lifecycle->retentionRest(new WP_REST_Request(['mode'=>'unlimited']));
assertLifecycle($unlimitedSave->status===200 && get_option('tya_retention_days')===0,'Unlimited retention save failed.');
$GLOBALS['tya_options']['tya_retention_days']=90;
$GLOBALS['wpdb']->expired=range(1,1500);
$preview=$lifecycle->preview();
assertLifecycle($preview['events']===1500 && $preview['sessions']===3 && $preview['cutoff']!==null && $preview['aggregate_coverage']['complete'],'Cleanup preview failed.');
$GLOBALS['tya_coverage_complete']=false;
$blockedCoverage=$lifecycle->runBatch();
assertLifecycle($blockedCoverage['status']==='blocked' && count($GLOBALS['wpdb']->expired)===1500,'Cleanup was not blocked on incomplete aggregate coverage.');
$GLOBALS['tya_coverage_complete']=true;
$GLOBALS['tya_options']['tya_aggregation_state']=['status'=>'running'];
$blockedAggregation=$lifecycle->runBatch();
assertLifecycle($blockedAggregation['status']==='blocked' && count($GLOBALS['wpdb']->expired)===1500,'Cleanup ran between resumable aggregation batches.');
unset($GLOBALS['tya_options']['tya_aggregation_state']);
$first=$lifecycle->runBatch();
assertLifecycle($first['status']==='running' && $first['deleted_total']===1000 && $first['remaining']===500,'First cleanup batch failed.');
assertLifecycle(isset($GLOBALS['tya_scheduled']['tya_cleanup_continue']),'Cleanup continuation was not scheduled.');
$GLOBALS['tya_coverage_complete']=false;
$second=$lifecycle->runBatch();
assertLifecycle($second['status']==='complete' && $second['deleted_total']===1500 && $second['remaining']===0,'Cleanup resume failed.');
assertLifecycle(get_option('tya_aggregate_frozen_before','')===$second['cutoff'],'Completed cleanup did not freeze its preserved aggregate boundary.');
$GLOBALS['tya_coverage_complete']=true;
assertLifecycle($GLOBALS['tya_options']['tya_annotation_sentinel']==='preserve','Cleanup changed unrelated metadata.');

$GLOBALS['wpdb']->expired=[2001];
$GLOBALS['tya_options']['tya_cleanup_lock']=['token'=>'other','expires'=>time()+60];
$locked=$lifecycle->runBatch();
assertLifecycle($locked['status']==='locked' && $GLOBALS['wpdb']->expired===[2001],'Overlap lock failed.');
unset($GLOBALS['tya_options']['tya_cleanup_lock']);
$GLOBALS['tya_options']['tya_cleanup_state']=['status'=>'running','cutoff'=>'2020-01-01 00:00:00','deleted_total'=>12];
$resumed=$lifecycle->runBatch();
assertLifecycle($resumed['status']==='complete' && $resumed['deleted_total']===13,'Interrupted cleanup state did not resume.');

$GLOBALS['wpdb']->expired=[3001];
$GLOBALS['wpdb']->failDelete=true;
$failed=$lifecycle->runBatch();
assertLifecycle($failed['status']==='failed' && $failed['error']==='Cleanup failed. Review database health and retry.' && !isset($GLOBALS['tya_options']['tya_cleanup_lock']),'Cleanup failure state or lock release failed.');
$GLOBALS['wpdb']->failDelete=false;

$GLOBALS['tya_options']['tya_retention_days']=0;
$unlimited=$lifecycle->preview();
assertLifecycle($unlimited['cutoff']===null && $unlimited['events']===0,'Unlimited preview must not select rows.');
$diagnostics=$lifecycle->diagnostics();
assertLifecycle($diagnostics['table_bytes']===1536 && $diagnostics['database_bytes']===0 && $diagnostics['monthly'][0]['month']==='2026-08','Storage diagnostics failed.');

$GLOBALS['wpdb']->exportRows=[['event_id'=>1,'occurred_at'=>'2026-08-25 00:00:00','event_type'=>'pageview','path'=>'=HYPERLINK("bad")','is_bot'=>0]];
$filters=['from'=>'','to'=>'','actor'=>'all','traffic_channel'=>'','referrer_host'=>'','utm_source'=>'','utm_medium'=>'','utm_campaign'=>'','event_type'=>'','event_name'=>'','path'=>'','country_code'=>'','region'=>'','asn'=>'','asn_org'=>'','watched'=>false,'tag_id'=>0];
$stream=new ReflectionMethod($lifecycle,'streamExport'); $stream->setAccessible(true);
$filterMethod=new ReflectionMethod($lifecycle,'exportFilters'); $filterMethod->setAccessible(true);
$normalized=$filterMethod->invoke($lifecycle,['from'=>'2026-99-99','to'=>'2026-08-01','actor'=>'invalid']);
assertLifecycle($normalized['from']==='' && $normalized['to']==='2026-08-01' && $normalized['actor']==='all','Export filter validation failed.');
$malformed=$filterMethod->invoke($lifecycle,['actor'=>['bot'],'asn'=>['1'],'tag_id'=>['5'],'watched'=>['1']]);
assertLifecycle($malformed['actor']==='all' && $malformed['asn']==='' && $malformed['tag_id']===0 && $malformed['watched']===false,'Malformed export filters were accepted.');
ob_start(); $stream->invoke($lifecycle,'events','json','omit',$filters); $json=ob_get_clean();
$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
assertLifecycle($decoded['schema']==='tenyen-analytics.export.v1' && $decoded['dataset']==='events' && count($decoded['rows'])===1,'Stable JSON export failed.');
assertLifecycle(str_contains($GLOBALS['wpdb']->lastQuery,'NOT (e.is_bot=1)'),'Analysis exclusions were not applied to export.');
$filtered=$filters; $filtered['event_type']='pageview'; $filtered['utm_campaign']='launch'; $filtered['country_code']='JP'; $filtered['watched']=true; $filtered['tag_id']=5;
ob_start(); $stream->invoke($lifecycle,'events','json','omit',$filtered); ob_end_clean();
assertLifecycle(str_contains($GLOBALS['wpdb']->lastQuery,"e.event_type='pageview'") && str_contains($GLOBALS['wpdb']->lastQuery,"e.utm_campaign='launch'") && str_contains($GLOBALS['wpdb']->lastQuery,'wp_tya_annotations') && str_contains($GLOBALS['wpdb']->lastQuery,'r.tag_id=5'),'Filtered export query failed.');
$GLOBALS['tya_aggregate_dimensions']=[['dimension_key'=>'newsletter'.chr(31).'email'.chr(31).'launch','dimension_label'=>'launch','events'=>9,'pageviews'=>7,'sessions'=>4,'visitors'=>3,'last_seen'=>'2026-08-01 12:00:00']];
$aggregateExport=new ReflectionMethod($lifecycle,'aggregateExportChunk');$aggregateExport->setAccessible(true);
$campaignExport=$aggregateExport->invoke($lifecycle,'campaigns',$filters,0);
assertLifecycle($campaignExport[0]['utm_source']==='newsletter'&&$campaignExport[0]['events']===9,'Aggregate-backed summary export failed.');
ob_start(); $stream->invoke($lifecycle,'events','csv','omit',$filters); $csv=ob_get_clean();
assertLifecycle(str_contains($csv,"'=HYPERLINK"),'CSV export did not neutralize a formula field.');
$GLOBALS['wpdb']->exportRows=[];
ob_start(); $stream->invoke($lifecycle,'events','json','omit',$filters); $emptyJson=ob_get_clean();
assertLifecycle(json_decode($emptyJson,true,512,JSON_THROW_ON_ERROR)['rows']===[],'Empty JSON export failed.');

echo "lifecycle: ok\n";
