<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['agg_options']=[];$GLOBALS['agg_scheduled']=[];$GLOBALS['agg_actions']=[];
function __(string $value):string{return $value;}
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['agg_options'][$key]??$default;}
function update_option(string $key,mixed $value):bool{$GLOBALS['agg_options'][$key]=$value;return true;}
function add_option(string $key,mixed $value):bool{if(array_key_exists($key,$GLOBALS['agg_options']))return false;$GLOBALS['agg_options'][$key]=$value;return true;}
function delete_option(string $key):bool{unset($GLOBALS['agg_options'][$key]);return true;}
function wp_generate_uuid4():string{static $i=0;return 'aggregate-token-'.++$i;}
function wp_next_scheduled(string $hook):int|false{return $GLOBALS['agg_scheduled'][$hook]??false;}
function wp_schedule_event(int $time,string $recurrence,string $hook):bool{$GLOBALS['agg_scheduled'][$hook]=$time;return true;}
function wp_schedule_single_event(int $time,string $hook):bool{$GLOBALS['agg_scheduled'][$hook]=$time;return true;}
function add_action(string $hook,callable $callback):void{$GLOBALS['agg_actions'][$hook][]=$callback;}

class WP_REST_Request{public function __construct(private array $json=[]){}public function get_json_params():array{return $this->json;}}
class WP_REST_Response{public function __construct(public mixed $data,public int $status=200){}public function header(string $name,string $value):void{}}

final class TYA_Installer{
    public static function tableName():string{return 'wp_events';}
    public static function dailyAggregatesTable():string{return 'wp_daily';}
    public static function dailyDimensionsTable():string{return 'wp_dimensions';}
    public static function annotationsTable():string{return 'wp_annotations';}
    public static function entityTagsTable():string{return 'wp_entity_tags';}
}
final class TYA_Plugin{
    public static string $exclusion='';
    public static function instance():self{static $instance;return $instance??=new self();}
    public function analysisWhere(string $alias=''):string{return self::$exclusion;}
}

final class AggregationWpdb{
    public array $dailyRows=[];
    public array $coveredDays=[];
    public array $dimensionRows=[];
    public array $metadataKeys=[];
    public array $verificationRows=[];
    public int $coverageMissing=0;
    public int $sourceMax=9;
    public int $physicalRows=1;
    public int $preservedRows=0;
    public int $queries=0;
    public string $lastQuery='';
    public function prepare(string $query,mixed ...$args):string{foreach($args as $arg){$replacement=is_int($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'";$query=preg_replace('/%[sd]/',$replacement,$query,1)??$query;}return $query;}
    public function get_var(string $query):mixed{$this->queries++;if(str_contains($query,'LEFT JOIN wp_daily'))return $this->coverageMissing;if(str_contains($query,'DATE(MIN(occurred_at))'))return '2026-01-01';if(str_contains($query,'SELECT COUNT(*) FROM wp_events WHERE occurred_at'))return $this->physicalRows;if(str_contains($query,'SELECT source_events FROM wp_daily'))return $this->preservedRows;return 0;}
    public function get_col(string $query):array{
        $this->queries++;$this->lastQuery=$query;
        if(str_contains($query,'SELECT aggregate_day FROM wp_daily'))return $this->coveredDays;
        if(str_contains($query,'wp_annotations'))return $this->metadataKeys;
        if(str_contains($query,'DISTINCT e.visitor_id'))return ['visitor-a','visitor-b'];
        if(str_contains($query,'DISTINCT e.session_id'))return ['session-a','session-b'];
        return [];
    }
    public function get_row(string $query,string $output):array{
        $this->queries++;
        if(str_contains($query,'MIN(aggregate_day)'))return ['oldest'=>'2026-01-01','newest'=>'2026-08-20','days'=>2,'rows_count'=>6];
        if(str_contains($query,'SUM(s.pageviews=1)'))return ['sessions'=>2,'bounces'=>1,'entries'=>2,'exits'=>2];
        if(str_contains($query,'COALESCE(SUM(x.duration_ms)'))return ['engaged_ms'=>40000,'engagement_samples'=>2,'scroll_sum'=>130,'scroll_samples'=>2];
        if(str_contains($query,'SUM(e.event_type'))return ['pageviews'=>4,'events'=>6,'bot_events'=>1,'source_max_event_id'=>$this->sourceMax];
        return [];
    }
    public function get_results(string $query,string $output):array{
        $this->queries++;
        if(str_contains($query,'SELECT * FROM wp_daily'))return array_values(array_filter($this->dailyRows,fn(array $row):bool=>in_array($row['aggregate_day'],$this->coveredDays,true)));
        if(str_contains($query,'COALESCE(r.source_events'))return $this->verificationRows;
        if(str_contains($query,'SELECT aggregate_day bucket'))return array_map(fn(array $row):array=>['bucket'=>$row['aggregate_day'],'pageviews'=>$row['pageviews'],'visitors'=>$row['visitors'],'sessions'=>$row['sessions']],array_values(array_filter($this->dailyRows,fn(array $row):bool=>$row['actor']==='all'&&in_array($row['aggregate_day'],$this->coveredDays,true))));
        if(str_contains($query,'FROM wp_dimensions'))return $this->dimensionRows;
        return [];
    }
    public function query(string $query):int|false{
        $this->queries++;
        if(str_starts_with($query,'DELETE FROM wp_daily')&&preg_match("/'(\d{4}-\d{2}-\d{2})'/",$query,$match))$this->dailyRows=array_values(array_filter($this->dailyRows,fn(array $row):bool=>$row['aggregate_day']!==$match[1]));
        return 1;
    }
    public function insert(string $table,array $row):int|false{
        if($table==='wp_daily'){$this->dailyRows=array_values(array_filter($this->dailyRows,fn(array $old):bool=>!($old['aggregate_day']===$row['aggregate_day']&&$old['actor']===$row['actor'])));$this->dailyRows[]=$row;}
        return 1;
    }
}

function assertAggregation(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$GLOBALS['wpdb']=new AggregationWpdb();
require dirname(__DIR__).'/includes/class-tya-aggregation.php';

$aggregation=new TYA_Aggregation();
$aggregation->boot();
assertAggregation(isset($GLOBALS['agg_actions']['tya_daily_aggregation'],$GLOBALS['agg_actions']['tya_aggregation_continue']),'Aggregation Cron hooks were not registered.');

$sketch=TYA_Aggregation::sketch(['a','b','a']);
assertAggregation(TYA_Aggregation::estimateSketch($sketch)===2,'Visitor sketch duplicate prevention failed.');
$merged=TYA_Aggregation::mergeSketch(TYA_Aggregation::sketch(['a','b']),TYA_Aggregation::sketch(['b','c']));
assertAggregation(TYA_Aggregation::estimateSketch($merged)===3,'Mergeable visitor/session estimate double-counted an identifier.');

$yesterday=gmdate('Y-m-d',time()-DAY_IN_SECONDS);
$aggregation->aggregateDay($yesterday);
assertAggregation(count($GLOBALS['wpdb']->dailyRows)===3,'First daily aggregate did not create all actor rows.');
$firstAll=array_values(array_filter($GLOBALS['wpdb']->dailyRows,fn(array $row):bool=>$row['actor']==='all'))[0];
assertAggregation($firstAll['pageviews']===4&&$firstAll['bounces']===1&&$firstAll['entries']===2&&$firstAll['exits']===2&&$firstAll['engaged_ms']===40000&&$firstAll['scroll_sum']===130,'Daily formulas were not preserved as additive numerators.');
$aggregation->aggregateDay($yesterday);
assertAggregation(count($GLOBALS['wpdb']->dailyRows)===3,'Idempotent rerun created duplicate aggregate rows.');
$GLOBALS['wpdb']->sourceMax=12;$aggregation->aggregateDay($yesterday);
$lateAll=array_values(array_filter($GLOBALS['wpdb']->dailyRows,fn(array $row):bool=>$row['actor']==='all'))[0];
assertAggregation($lateAll['source_max_event_id']===12,'Late-event rerun did not refresh the source checkpoint.');
$GLOBALS['wpdb']->physicalRows=0;$GLOBALS['wpdb']->preservedRows=6;$protected=false;try{$aggregation->aggregateDay($yesterday);}catch(RuntimeException){$protected=true;}
assertAggregation($protected&&count($GLOBALS['wpdb']->dailyRows)===3,'Rebuild overwrote aggregates after their raw source was cleaned.');
$GLOBALS['wpdb']->physicalRows=1;$GLOBALS['wpdb']->preservedRows=0;

$from=gmdate('Y-m-d',time()-2*DAY_IN_SECONDS);
$response=$aggregation->rebuildRest(new WP_REST_Request(['from'=>$from,'to'=>$yesterday]));
assertAggregation($response->status===200&&$response->data['aggregation']['state']['status']==='running','Range rebuild did not stop at its resumable day boundary.');
$busy=$aggregation->rebuildRest(new WP_REST_Request(['from'=>$from,'to'=>$yesterday]));
assertAggregation($busy->status===409,'Overlapping manual rebuild replaced active checkpoint state.');
$finished=$aggregation->runBatch();
assertAggregation($finished['status']==='complete'&&$finished['completed_days']===2&&get_option('tya_aggregation_checkpoint')===$yesterday,'Interrupted range rebuild did not resume to completion.');
$GLOBALS['agg_options']['tya_cleanup_lock']=['token'=>'cleanup','expires'=>time()+60];
assertAggregation($aggregation->runBatch()['status']==='locked','Aggregation raced an active cleanup lock.');
unset($GLOBALS['agg_options']['tya_cleanup_lock']);
$GLOBALS['agg_options']['tya_cleanup_state']=['status'=>'running'];
assertAggregation($aggregation->runBatch()['status']==='locked','Aggregation ran between resumable cleanup batches.');
unset($GLOBALS['agg_options']['tya_cleanup_state']);
$GLOBALS['agg_options']['tya_aggregation_lock']=['token'=>'other','expires'=>time()+60];
assertAggregation($aggregation->runBatch()['status']==='locked','Aggregation overlap lock failed.');
unset($GLOBALS['agg_options']['tya_aggregation_lock']);
$invalid=$aggregation->rebuildRest(new WP_REST_Request(['from'=>'bad','to'=>$yesterday]));
assertAggregation($invalid->status===400,'Invalid rebuild date was accepted.');

$GLOBALS['wpdb']->coverageMissing=1;$coverage=$aggregation->coverageBefore($yesterday.' 12:00:00');
assertAggregation(!$coverage['complete']&&$coverage['cutoff']===$yesterday.' 00:00:00','Incomplete cleanup coverage was not detected at a whole-day boundary.');
$GLOBALS['wpdb']->coverageMissing=0;assertAggregation($aggregation->coverageBefore($yesterday.' 00:00:00')['complete'],'Complete aggregate coverage was rejected.');
$GLOBALS['wpdb']->verificationRows=[['source_events'=>6,'source_max_event_id'=>12,'raw_events'=>6,'raw_max_event_id'=>12],['source_events'=>6,'source_max_event_id'=>9,'raw_events'=>7,'raw_max_event_id'=>13]];
$verification=$aggregation->verifySample();
assertAggregation($verification['checked']===2&&$verification['mismatched']===1&&$verification['status']==='stale','Sampled raw-vs-aggregate verification failed.');
$status=$aggregation->status();
assertAggregation($status['days']===2&&$status['verification']['mismatched']===1,'Observable aggregate status failed.');

$day1='2026-08-20';$day2='2026-08-21';
$GLOBALS['wpdb']->coveredDays=[$day1];
$plan=$aggregation->boundary($day1.' 00:00:00',$day2.' 12:00:00','all');
assertAggregation($plan['source']==='mixed'&&$plan['aggregate_days']===[$day1]&&$plan['raw_ranges']===[[$day2.' 00:00:00',$day2.' 12:00:00']],'Raw/aggregate boundary overlapped or skipped time.');
$GLOBALS['wpdb']->dailyRows=[[...$firstAll,'aggregate_day'=>$day1,'actor'=>'all']];
$summary=$aggregation->summary($day1.' 00:00:00',$day2.' 12:00:00','all');
assertAggregation($summary['source']==='mixed'&&$summary['pageviews']===8&&$summary['events']===12&&$summary['bounces']===2&&$summary['visitors']===2&&$summary['sessions']===2,'Mixed report double-counted or lost metrics.');
$GLOBALS['wpdb']->coveredDays=[];
$rawOnly=$aggregation->summary($day1.' 00:00:00',$day2.' 00:00:00','all');
assertAggregation($rawOnly['source']==='raw'&&$rawOnly['pageviews']===4&&$rawOnly['events']===6,'Raw-only report failed.');

$GLOBALS['wpdb']->coveredDays=[$day1];
$GLOBALS['wpdb']->dailyRows=[[...$firstAll,'aggregate_day'=>$day1,'actor'=>'all']];
$afterCleanup=$aggregation->summary($day1.' 00:00:00',$day2.' 00:00:00','all');
assertAggregation($afterCleanup['source']==='aggregate'&&$afterCleanup['pageviews']===4,'Historical totals did not survive a raw-only cleanup boundary.');

$GLOBALS['wpdb']->dimensionRows=[
    ['row_hash'=>'A','dimension_key'=>'newsletter'.chr(31).'email'.chr(31).'launch','dimension_label'=>'launch','pageviews'=>7,'events'=>9,'visitors'=>3,'sessions'=>4,'bounces'=>1,'entries'=>2,'exits'=>2,'engaged_ms'=>0,'engagement_samples'=>0,'scroll_sum'=>0,'scroll_samples'=>0,'last_seen'=>'2026-08-20 10:00:00'],
];
$campaigns=$aggregation->dimensions('campaign',$day1.' 00:00:00',$day2.' 00:00:00','all',10);
assertAggregation($campaigns[0]['events']===9&&$campaigns[0]['pageviews']===7,'Campaign aggregate totals failed.');
$GLOBALS['wpdb']->dimensionRows=[['row_hash'=>'E','dimension_key'=>'custom'.chr(31).'signup','dimension_label'=>'signup','pageviews'=>0,'events'=>11,'visitors'=>3,'sessions'=>4,'bounces'=>0,'entries'=>0,'exits'=>0,'engaged_ms'=>0,'engagement_samples'=>0,'scroll_sum'=>0,'scroll_samples'=>0,'last_seen'=>'2026-08-20 10:30:00']];
$events=$aggregation->dimensions('event',$day1.' 00:00:00',$day2.' 00:00:00','all',10);
assertAggregation($events[0]['events']===11&&$events[0]['sessions']===4,'Event aggregate totals failed.');
$GLOBALS['wpdb']->dimensionRows=[['row_hash'=>'B','dimension_key'=>'2516'.chr(31).'KDDI','dimension_label'=>'KDDI','pageviews'=>5,'events'=>5,'visitors'=>2,'sessions'=>2,'bounces'=>0,'entries'=>0,'exits'=>0,'engaged_ms'=>0,'engagement_samples'=>0,'scroll_sum'=>0,'scroll_samples'=>0,'last_seen'=>'2026-08-20 11:00:00']];
$GLOBALS['wpdb']->metadataKeys=['2516'];
$watched=$aggregation->dimensions('organization',$day1.' 00:00:00',$day2.' 00:00:00','all',10,['watched'=>true,'tag_id'=>5]);
assertAggregation(count($watched)===1&&$watched[0]['visitors']===2,'Tag/watchlist filtering did not survive aggregate reporting.');

TYA_Plugin::$exclusion=' AND e.is_bot=0';$signature=$aggregation->ruleSignature();
assertAggregation($signature===hash('sha256',' AND e.is_bot=0'),'Analysis-exclusion signature was not stable.');
$GLOBALS['agg_options']['tya_aggregate_frozen_before']=$day2;
$GLOBALS['wpdb']->coveredDays=[$day1];$aggregation->boundary($day1.' 00:00:00',$day2.' 00:00:00','all');
assertAggregation(str_contains($GLOBALS['wpdb']->lastQuery,"aggregate_day<'{$day2}'"),'Frozen post-cleanup aggregates were invalidated by a later exclusion change.');
TYA_Plugin::$exclusion='';

$GLOBALS['wpdb']->queries=0;$GLOBALS['wpdb']->coveredDays=[];$GLOBALS['wpdb']->dailyRows=[];
for($i=0;$i<365;$i++){$day=gmdate('Y-m-d',strtotime('2025-01-01 UTC')+$i*DAY_IN_SECONDS);$GLOBALS['wpdb']->coveredDays[]=$day;$GLOBALS['wpdb']->dailyRows[]=[...$firstAll,'aggregate_day'=>$day,'actor'=>'all','pageviews'=>1,'events'=>1];}
$long=$aggregation->summary('2025-01-01 00:00:00','2026-01-01 00:00:00','all');
assertAggregation($long['source']==='aggregate'&&$long['pageviews']===365&&$GLOBALS['wpdb']->queries<=2,'Long-range aggregate report regressed to per-day/raw queries.');

echo "aggregation: ok\n";
