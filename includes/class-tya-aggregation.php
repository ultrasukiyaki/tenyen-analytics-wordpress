<?php

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * Retention-safe UTC daily aggregates.
 *
 * Rows are rebuilt transactionally and carry the active analysis-exclusion
 * signature plus raw source counts. Cleanup may only cross days whose source
 * facts still match a complete aggregate row.
 */
final class TYA_Aggregation
{
    private const STATE_OPTION = 'tya_aggregation_state';
    private const CHECKPOINT_OPTION = 'tya_aggregation_checkpoint';
    private const LOCK_OPTION = 'tya_aggregation_lock';
    private const LOCK_SECONDS = 600;
    private const MAX_RANGE_DAYS = 730;
    private const SKETCH_REGISTERS = 1024;
    private const ACTORS = ['human', 'bot', 'all'];
    private const DIMENSION_CAPS = [
        'content' => 500, 'organization' => 100, 'referrer' => 200,
        'campaign' => 200, 'event' => 200, 'traffic_channel' => 32, 'traffic_source' => 300,
        'country' => 250, 'browser' => 100, 'os' => 100, 'device' => 32,
    ];

    public function boot(): void
    {
        if (!wp_next_scheduled('tya_daily_aggregation')) wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'tya_daily_aggregation');
        add_action('tya_daily_aggregation', [$this, 'scheduledAggregation']);
        add_action('tya_aggregation_continue', [$this, 'continueAggregation']);
    }

    public function registerRoutes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('tenyen-analytics/v1', '/admin/aggregation/status', [
            'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'statusRest'], 'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/aggregation/rebuild', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'rebuildRest'], 'permission_callback' => $permission,
        ]);
    }

    public function statusRest(): WP_REST_Response
    {
        return $this->response(['ok' => true, 'aggregation' => $this->status()]);
    }

    public function rebuildRest(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) return $this->error(__('Invalid request body.', 'tenyen-analytics'), 400);
        $from = self::validDay($input['from'] ?? '');
        $to = self::validDay($input['to'] ?? '');
        if ($from === '' || $to === '') return $this->error(__('Enter a valid aggregate date range.', 'tenyen-analytics'), 400);
        if ($from > $to) [$from, $to] = [$to, $from];
        $yesterday = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
        if ($to > $yesterday) return $this->error(__('Only completed UTC days can be aggregated.', 'tenyen-analytics'), 400);
        if (self::daysBetween($from, $to) + 1 > self::MAX_RANGE_DAYS) return $this->error(__('Aggregate rebuilds are limited to 730 days.', 'tenyen-analytics'), 400);
        $current=get_option(self::STATE_OPTION,[]);$lock=get_option(self::LOCK_OPTION,null);
        if((is_array($current)&&($current['status']??'')==='running')||(is_array($lock)&&(int)($lock['expires']??0)>time())||$this->otherJobLocked())return $this->error(__('Aggregation or cleanup is already running.', 'tenyen-analytics'),409);
        $this->queue($from, $to, 'manual');
        $state = $this->runBatch();
        return $this->response(['ok' => $state['status'] !== 'failed', 'aggregation' => $this->status(), 'message' => (string)($state['error'] ?? '')], $state['status'] === 'failed' ? 500 : 200);
    }

    public function scheduledAggregation(): void
    {
        $state = get_option(self::STATE_OPTION, []);
        if (is_array($state) && ($state['status'] ?? '') === 'running') {
            $this->runBatch();
            return;
        }
        global $wpdb;
        $yesterday = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
        $checkpoint = self::validDay(get_option(self::CHECKPOINT_OPTION, ''));
        if ($checkpoint !== '' && $checkpoint < $yesterday) {
            $from = gmdate('Y-m-d', strtotime($checkpoint . ' 00:00:00 UTC') + DAY_IN_SECONDS);
        } elseif ($checkpoint !== '') {
            $from = gmdate('Y-m-d', strtotime($yesterday . ' 00:00:00 UTC') - 2 * DAY_IN_SECONDS);
            $oldestRaw=self::validDay((string)$wpdb->get_var('SELECT DATE(MIN(occurred_at)) FROM '.TYA_Installer::tableName()));
            if($oldestRaw==='')return;
            if($oldestRaw>$from)$from=$oldestRaw;
        } else {
            $oldest = (string)$wpdb->get_var('SELECT DATE(MIN(occurred_at)) FROM ' . TYA_Installer::tableName());
            $from = self::validDay($oldest) ?: $yesterday;
        }
        if ($from > $yesterday) return;
        $this->queue($from, $yesterday, 'scheduled');
        $this->runBatch();
    }

    public function continueAggregation(): void
    {
        $this->runBatch();
    }

    /** @return array<string,mixed> */
    public function runBatch(): array
    {
        if ($this->otherJobLocked()) return $this->state('locked', '', '', '', 0, __('Cleanup is currently running.', 'tenyen-analytics'));
        $token = $this->acquireLock();
        if ($token === '') return $this->state('locked', '', '', '', 0, '');
        if ($this->otherJobLocked()) {
            $this->releaseLock($token);
            return $this->state('locked', '', '', '', 0, __('Cleanup is currently running.', 'tenyen-analytics'));
        }
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') {
            $this->releaseLock($token);
            return $this->state('idle', '', '', '', 0, '');
        }
        $day = self::validDay($state['current_day'] ?? '');
        $to = self::validDay($state['to'] ?? '');
        $from = self::validDay($state['from'] ?? '');
        $completed = max(0, (int)($state['completed_days'] ?? 0));
        try {
            if ($day === '' || $to === '' || $from === '' || $day > $to) throw new RuntimeException('invalid_aggregation_state');
            $this->aggregateDay($day);
            $completed++;
            $checkpoint = self::validDay(get_option(self::CHECKPOINT_OPTION, ''));
            if ($checkpoint === '' || $day > $checkpoint) update_option(self::CHECKPOINT_OPTION, $day, false);
            $next = gmdate('Y-m-d', strtotime($day . ' 00:00:00 UTC') + DAY_IN_SECONDS);
            if ($next <= $to) {
                $result = $this->state('running', $from, $to, $next, $completed, '');
                update_option(self::STATE_OPTION, $result, false);
                if (!wp_next_scheduled('tya_aggregation_continue')) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'tya_aggregation_continue');
            } else {
                $result = $this->state('complete', $from, $to, '', $completed, '');
                update_option(self::STATE_OPTION, $result, false);
            }
        } catch (Throwable) {
            $result = $this->state('failed', $from, $to, $day, $completed, __('Aggregation failed. Review database health and retry.', 'tenyen-analytics'));
            update_option(self::STATE_OPTION, $result, false);
        } finally {
            $this->releaseLock($token);
        }
        return $result;
    }

    public function aggregateDay(string $day): void
    {
        global $wpdb;
        $day = self::validDay($day);
        $yesterday = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
        if ($day === '' || $day > $yesterday) throw new InvalidArgumentException('invalid_aggregate_day');
        $start = $day . ' 00:00:00';
        $end = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + DAY_IN_SECONDS);
        $daily = TYA_Installer::dailyAggregatesTable();
        $dimensions = TYA_Installer::dailyDimensionsTable();
        $signature = $this->ruleSignature();
        $now = gmdate('Y-m-d H:i:s');
        $physicalRows=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.TYA_Installer::tableName().' WHERE occurred_at>=%s AND occurred_at<%s',$start,$end));
        $preservedRows=(int)$wpdb->get_var($wpdb->prepare("SELECT source_events FROM {$daily} WHERE aggregate_day=%s AND actor='all'",$day));
        if($physicalRows===0&&$preservedRows>0)throw new RuntimeException('raw_day_no_longer_rebuildable');
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->query($wpdb->prepare("DELETE FROM {$dimensions} WHERE aggregate_day=%s", $day)) === false) throw new RuntimeException('dimension_delete_failed');
            if ($wpdb->query($wpdb->prepare("DELETE FROM {$daily} WHERE aggregate_day=%s", $day)) === false) throw new RuntimeException('daily_delete_failed');
            $visitorSketchByActor=[];$sessionSketchByActor=[];
            foreach (self::ACTORS as $actor) {
                $metrics = $this->rawMetrics($start, $end, $actor);
                $visitorSketch = $actor==='all'?self::mergeSketch($visitorSketchByActor['human'],$visitorSketchByActor['bot']):$this->rawSketch($start, $end, $actor, 'visitor_id');
                $sessionSketch = $actor==='all'?self::mergeSketch($sessionSketchByActor['human'],$sessionSketchByActor['bot']):$this->rawSketch($start, $end, $actor, 'session_id');
                $visitorSketchByActor[$actor]=$visitorSketch;$sessionSketchByActor[$actor]=$sessionSketch;
                $row = [
                    'aggregate_day'=>$day,'actor'=>$actor,'pageviews'=>$metrics['pageviews'],'events'=>$metrics['events'],
                    'visitors'=>self::estimateSketch($visitorSketch),'sessions'=>self::estimateSketch($sessionSketch),
                    'bounces'=>$metrics['bounces'],'entries'=>$metrics['entries'],'exits'=>$metrics['exits'],
                    'engaged_ms'=>$metrics['engaged_ms'],'engagement_samples'=>$metrics['engagement_samples'],
                    'scroll_sum'=>$metrics['scroll_sum'],'scroll_samples'=>$metrics['scroll_samples'],
                    'bot_events'=>$metrics['bot_events'],'visitor_sketch'=>$visitorSketch,'session_sketch'=>$sessionSketch,
                    'source_events'=>$metrics['source_events'],'source_max_event_id'=>$metrics['source_max_event_id'],
                    'rule_signature'=>$signature,'generated_at'=>$now,
                ];
                if ($wpdb->insert($daily, $row) === false) throw new RuntimeException('daily_insert_failed');
                foreach ($this->dimensionRows($start, $end, $actor) as $dimension) {
                    $dimension['aggregate_day'] = $day;
                    $dimension['actor'] = $actor;
                    $dimension['rule_signature'] = $signature;
                    $dimension['generated_at'] = $now;
                    if ($wpdb->insert($dimensions, $dimension) === false) throw new RuntimeException('dimension_insert_failed');
                }
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('aggregate_commit_failed');
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        global $wpdb;
        $daily = TYA_Installer::dailyAggregatesTable();
        $summary = $wpdb->get_row("SELECT MIN(aggregate_day) oldest,MAX(aggregate_day) newest,COUNT(DISTINCT aggregate_day) days,COUNT(*) rows_count FROM {$daily}", ARRAY_A) ?: [];
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state)) $state = [];
        $next = wp_next_scheduled('tya_daily_aggregation');
        return [
            'oldest'=>($summary['oldest']??'')?:null,'newest'=>($summary['newest']??'')?:null,
            'days'=>(int)($summary['days']??0),'rows'=>(int)($summary['rows_count']??0),
            'checkpoint'=>self::validDay(get_option(self::CHECKPOINT_OPTION, '')) ?: null,
            'rule_signature'=>$this->ruleSignature(),'state'=>$state,
            'verification'=>$this->verifySample(),
            'next_run'=>$next ? gmdate('c', (int)$next) : null,
        ];
    }

    /** @return array{checked:int,mismatched:int,status:string} */
    public function verifySample(int $limit=7): array
    {
        global $wpdb;
        $limit=max(1,min(31,$limit));$events=TYA_Installer::tableName();$daily=TYA_Installer::dailyAggregatesTable();$excluded=TYA_Plugin::instance()->analysisWhere('e');
        $sql="SELECT a.aggregate_day,a.source_events,a.source_max_event_id,COALESCE(r.source_events,0) raw_events,COALESCE(r.source_max_event_id,0) raw_max_event_id
              FROM {$daily} a INNER JOIN (SELECT DATE(e.occurred_at) aggregate_day,COUNT(*) source_events,MAX(e.event_id) source_max_event_id FROM {$events} e WHERE e.occurred_at>=(SELECT DATE_SUB(MAX(aggregate_day),INTERVAL %d DAY) FROM {$daily}){$excluded} GROUP BY DATE(e.occurred_at)) r ON r.aggregate_day=a.aggregate_day
              WHERE a.actor='all' AND a.rule_signature=%s ORDER BY a.aggregate_day DESC LIMIT %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,$limit+2,$this->ruleSignature(),$limit),ARRAY_A)?:[];$mismatched=0;
        foreach($rows as $row)if((int)$row['source_events']!==(int)$row['raw_events']||(int)$row['source_max_event_id']!==(int)$row['raw_max_event_id'])$mismatched++;
        return ['checked'=>count($rows),'mismatched'=>$mismatched,'status'=>$mismatched===0?'ok':'stale'];
    }

    /** @return array{complete:bool,cutoff:string,missing_days:int,message:string} */
    public function coverageBefore(string $cutoff): array
    {
        global $wpdb;
        $cutoffDay = self::validDay(substr($cutoff, 0, 10));
        if ($cutoffDay === '') return ['complete'=>false,'cutoff'=>'','missing_days'=>1,'message'=>__('Invalid cleanup cutoff.', 'tenyen-analytics')];
        $cutoff = $cutoffDay . ' 00:00:00';
        $events = TYA_Installer::tableName();
        $daily = TYA_Installer::dailyAggregatesTable();
        $excluded = TYA_Plugin::instance()->analysisWhere('e');
        $signature = $this->ruleSignature();
        $sql = "SELECT COUNT(*) FROM (
                    SELECT DATE(e.occurred_at) aggregate_day,COUNT(*) source_events,MAX(e.event_id) source_max_event_id
                    FROM {$events} e WHERE e.occurred_at<%s{$excluded} GROUP BY DATE(e.occurred_at)
                ) r LEFT JOIN {$daily} a ON a.aggregate_day=r.aggregate_day AND a.actor='all'
                WHERE a.aggregate_day IS NULL OR a.rule_signature<>%s OR a.source_events<>r.source_events OR a.source_max_event_id<>r.source_max_event_id";
        $missing = (int)$wpdb->get_var($wpdb->prepare($sql, $cutoff, $signature));
        return [
            'complete'=>$missing===0,'cutoff'=>$cutoff,'missing_days'=>$missing,
            'message'=>$missing===0?'':__('Cleanup is blocked until every affected UTC day has a current aggregate.', 'tenyen-analytics'),
        ];
    }

    /**
     * Split a report range into complete aggregate days and raw ranges. Every
     * timestamp belongs to exactly one side, preventing raw/aggregate overlap.
     * @return array{aggregate_days:array<int,string>,raw_ranges:array<int,array{0:string,1:string}>,source:string}
     */
    public function boundary(string $start, string $end, string $actor = 'all'): array
    {
        global $wpdb;
        if (!in_array($actor, self::ACTORS, true) || strtotime($start . ' UTC') === false || strtotime($end . ' UTC') === false || $start >= $end) {
            return ['aggregate_days'=>[],'raw_ranges'=>[[$start,$end]],'source'=>'raw'];
        }
        $firstFull = str_ends_with($start, '00:00:00') ? substr($start,0,10) : gmdate('Y-m-d', strtotime($start . ' UTC') + DAY_IN_SECONDS);
        $lastExclusive = substr($end, 11) === '00:00:00' ? substr($end,0,10) : substr($end,0,10);
        $rows = [];
        if ($firstFull < $lastExclusive) {
            $frozen=self::validDay(substr((string)get_option('tya_aggregate_frozen_before',''),0,10));
            $signatureWhere=$frozen!==''?'(rule_signature=%s OR aggregate_day<%s)':'rule_signature=%s';
            $params=$frozen!==''?[$actor,$this->ruleSignature(),$frozen,$firstFull,$lastExclusive]:[$actor,$this->ruleSignature(),$firstFull,$lastExclusive];
            $rows = $wpdb->get_col($wpdb->prepare(
                'SELECT aggregate_day FROM ' . TYA_Installer::dailyAggregatesTable() . " WHERE actor=%s AND {$signatureWhere} AND aggregate_day>=%s AND aggregate_day<%s ORDER BY aggregate_day",
                ...$params
            )) ?: [];
        }
        $covered = array_fill_keys(array_map('strval',$rows), true);
        $aggregateDays = [];
        $rawRanges = [];
        $cursor = $start;
        while ($cursor < $end) {
            $day = substr($cursor,0,10);
            $dayStart = $day . ' 00:00:00';
            $dayEnd = gmdate('Y-m-d H:i:s', strtotime($dayStart . ' UTC') + DAY_IN_SECONDS);
            $segmentEnd = min($end, $dayEnd);
            $full = $cursor === $dayStart && $segmentEnd === $dayEnd;
            if ($full && isset($covered[$day])) {
                $aggregateDays[] = $day;
            } else {
                $last = count($rawRanges)-1;
                if ($last >= 0 && $rawRanges[$last][1] === $cursor) $rawRanges[$last][1] = $segmentEnd;
                else $rawRanges[] = [$cursor,$segmentEnd];
            }
            $cursor = $segmentEnd;
        }
        $source = $aggregateDays === [] ? 'raw' : ($rawRanges === [] ? 'aggregate' : 'mixed');
        return ['aggregate_days'=>$aggregateDays,'raw_ranges'=>$rawRanges,'source'=>$source];
    }

    /** @return array<string,int|float|string> */
    public function summary(string $start, string $end, string $actor): array
    {
        global $wpdb;
        $plan = $this->boundary($start,$end,$actor);
        $totals = ['pageviews'=>0,'events'=>0,'bounces'=>0,'entries'=>0,'exits'=>0,'engaged_ms'=>0,'engagement_samples'=>0,'scroll_sum'=>0,'scroll_samples'=>0,'bot_events'=>0];
        $visitorSketch = self::emptySketch();
        $sessionSketch = self::emptySketch();
        if ($plan['aggregate_days'] !== []) {
            $placeholders = implode(',', array_fill(0,count($plan['aggregate_days']),'%s'));
            $sql = 'SELECT * FROM ' . TYA_Installer::dailyAggregatesTable() . " WHERE actor=%s AND aggregate_day IN ({$placeholders})";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...[$actor,...$plan['aggregate_days']]), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                foreach (array_keys($totals) as $metric) $totals[$metric] += (int)($row[$metric]??0);
                $visitorSketch = self::mergeSketch($visitorSketch,(string)($row['visitor_sketch']??''));
                $sessionSketch = self::mergeSketch($sessionSketch,(string)($row['session_sketch']??''));
            }
        }
        foreach ($plan['raw_ranges'] as [$rawStart,$rawEnd]) {
            $row = $this->rawMetrics($rawStart,$rawEnd,$actor);
            foreach (array_keys($totals) as $metric) $totals[$metric] += (int)($row[$metric]??0);
            $visitorSketch = self::mergeSketch($visitorSketch,$this->rawSketch($rawStart,$rawEnd,$actor,'visitor_id'));
            $sessionSketch = self::mergeSketch($sessionSketch,$this->rawSketch($rawStart,$rawEnd,$actor,'session_id'));
        }
        $totals['visitors'] = self::estimateSketch($visitorSketch);
        $totals['sessions'] = self::estimateSketch($sessionSketch);
        $totals['avg_duration_ms'] = $totals['engagement_samples'] > 0 ? $totals['engaged_ms'] / $totals['engagement_samples'] : 0.0;
        $totals['avg_scroll'] = $totals['scroll_samples'] > 0 ? $totals['scroll_sum'] / $totals['scroll_samples'] : 0.0;
        $totals['source'] = $plan['source'];
        return $totals;
    }

    /** @return array<int,array<string,mixed>> */
    public function dimensions(string $type,string $start,string $end,string $actor,int $limit=100,array $filters=[]): array
    {
        global $wpdb;
        if(!array_key_exists($type,self::DIMENSION_CAPS))return [];
        if(!in_array($actor,self::ACTORS,true))$actor='all';
        $limit=max(1,min(self::DIMENSION_CAPS[$type],$limit));$fetchLimit=self::DIMENSION_CAPS[$type];$plan=$this->boundary($start,$end,$actor);$merged=[];
        if($plan['aggregate_days']!==[]){
            $placeholders=implode(',',array_fill(0,count($plan['aggregate_days']),'%s'));
            $sql='SELECT HEX(dimension_hash) row_hash,MAX(dimension_key) dimension_key,MAX(dimension_label) dimension_label,SUM(pageviews) pageviews,SUM(events) events,SUM(visitors) visitors,SUM(sessions) sessions,SUM(bounces) bounces,SUM(entries) entries,SUM(exits) exits,SUM(engaged_ms) engaged_ms,SUM(engagement_samples) engagement_samples,SUM(scroll_sum) scroll_sum,SUM(scroll_samples) scroll_samples,MAX(last_seen) last_seen FROM '.TYA_Installer::dailyDimensionsTable()." WHERE actor=%s AND dimension_type=%s AND aggregate_day IN ({$placeholders}) GROUP BY dimension_hash ORDER BY pageviews DESC,events DESC LIMIT %d";
            $rows=$wpdb->get_results($wpdb->prepare($sql,...[$actor,$type,...$plan['aggregate_days'],$fetchLimit]),ARRAY_A)?:[];
            foreach($rows as $row)$this->mergeDimension($merged,$row);
        }
        foreach($plan['raw_ranges'] as [$rawStart,$rawEnd])foreach($this->dimensionRows($rawStart,$rawEnd,$actor,$type) as $row)$this->mergeDimension($merged,$row);
        $rows=array_values($merged);
        $rows=$this->filterMetadataDimensions($rows,$type,$filters);
        foreach($rows as &$row){
            $row['bounce_rate']=$row['entries']>0?$row['bounces']/$row['entries']*100:0.0;
            $row['exit_rate']=$row['pageviews']>0?$row['exits']/$row['pageviews']*100:0.0;
            $row['pageviews_per_session']=$row['sessions']>0?$row['pageviews']/$row['sessions']:0.0;
            $row['avg_duration']=$row['engagement_samples']>0?$row['engaged_ms']/$row['engagement_samples']:0.0;
            $row['avg_scroll']=$row['scroll_samples']>0?$row['scroll_sum']/$row['scroll_samples']:0.0;
            $row['source']=$plan['source'];
        }
        unset($row);
        usort($rows,static fn(array $a,array $b):int=>[(int)$b['pageviews'],(int)$b['events'],(string)$a['dimension_key']]<=>[(int)$a['pageviews'],(int)$a['events'],(string)$b['dimension_key']]);
        return array_slice($rows,0,$limit);
    }

    /** @return array<int,array{bucket:string,pageviews:int,visitors:int,sessions:int}> */
    public function dailyTimeline(string $start,string $end,string $actor): array
    {
        global $wpdb;
        $plan=$this->boundary($start,$end,$actor);$rows=[];
        if($plan['aggregate_days']!==[]){
            $placeholders=implode(',',array_fill(0,count($plan['aggregate_days']),'%s'));
            $sql='SELECT aggregate_day bucket,pageviews,visitors,sessions FROM '.TYA_Installer::dailyAggregatesTable()." WHERE actor=%s AND aggregate_day IN ({$placeholders})";
            foreach($wpdb->get_results($wpdb->prepare($sql,...[$actor,...$plan['aggregate_days']]),ARRAY_A)?:[] as $row)$rows[(string)$row['bucket']]=['bucket'=>(string)$row['bucket'],'pageviews'=>(int)$row['pageviews'],'visitors'=>(int)$row['visitors'],'sessions'=>(int)$row['sessions']];
        }
        $table=TYA_Installer::tableName();$where=$this->rawWhere($actor,'e');
        foreach($plan['raw_ranges'] as [$rawStart,$rawEnd]){
            $sql="SELECT DATE(e.occurred_at) bucket,COUNT(*) pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions FROM {$table} e WHERE e.event_type='pageview' AND e.occurred_at>=%s AND e.occurred_at<%s{$where} GROUP BY DATE(e.occurred_at)";
            foreach($wpdb->get_results($wpdb->prepare($sql,$rawStart,$rawEnd),ARRAY_A)?:[] as $row){$key=(string)$row['bucket'];if(!isset($rows[$key]))$rows[$key]=['bucket'=>$key,'pageviews'=>0,'visitors'=>0,'sessions'=>0];foreach(['pageviews','visitors','sessions'] as $metric)$rows[$key][$metric]+=(int)$row[$metric];}
        }
        ksort($rows);return array_values($rows);
    }

    public function ruleSignature(): string
    {
        return hash('sha256', TYA_Plugin::instance()->analysisWhere('e'));
    }

    public static function emptySketch(): string { return str_repeat("\0", self::SKETCH_REGISTERS); }

    /** @param array<int,string> $values */
    public static function sketch(array $values): string
    {
        $sketch = self::emptySketch();
        foreach ($values as $value) if ($value !== '') self::addSketchValue($sketch,$value);
        return $sketch;
    }

    public static function mergeSketch(string $left, string $right): string
    {
        $left = str_pad(substr($left,0,self::SKETCH_REGISTERS),self::SKETCH_REGISTERS,"\0");
        $right = str_pad(substr($right,0,self::SKETCH_REGISTERS),self::SKETCH_REGISTERS,"\0");
        for ($i=0;$i<self::SKETCH_REGISTERS;$i++) if (ord($right[$i]) > ord($left[$i])) $left[$i]=$right[$i];
        return $left;
    }

    public static function estimateSketch(string $sketch): int
    {
        $sketch = str_pad(substr($sketch,0,self::SKETCH_REGISTERS),self::SKETCH_REGISTERS,"\0");
        $sum=0.0;$zeros=0;
        for($i=0;$i<self::SKETCH_REGISTERS;$i++){ $rank=ord($sketch[$i]);$sum+=2**(-$rank);if($rank===0)$zeros++; }
        $m=self::SKETCH_REGISTERS;$estimate=0.7209/(1+1.079/$m)*$m*$m/$sum;
        if($estimate<=2.5*$m && $zeros>0)$estimate=$m*log($m/$zeros);
        return max(0,(int)round($estimate));
    }

    /** @return array<string,int> */
    private function rawMetrics(string $start, string $end, string $actor): array
    {
        global $wpdb;
        $table=TYA_Installer::tableName();$where=$this->rawWhere($actor,'e');
        $base=$wpdb->get_row($wpdb->prepare("SELECT SUM(e.event_type='pageview') pageviews,COUNT(*) events,SUM(e.is_bot=1) bot_events,MAX(e.event_id) source_max_event_id FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s{$where}",$start,$end),ARRAY_A)?:[];
        $sessions=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) sessions,SUM(s.pageviews=1) bounces,SUM(s.pageviews>0) entries,SUM(s.pageviews>0) exits FROM (SELECT e.session_id,SUM(e.event_type='pageview') pageviews FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.session_id<>''{$where} GROUP BY e.session_id) s",$start,$end),ARRAY_A)?:[];
        $engagement=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(x.duration_ms),0) engaged_ms,SUM(x.duration_ms>0) engagement_samples,COALESCE(SUM(x.scroll_depth),0) scroll_sum,SUM(x.scroll_depth>0) scroll_samples FROM (SELECT e.session_id,e.path,MAX(e.duration_ms) duration_ms,MAX(CASE WHEN e.scroll_depth BETWEEN 1 AND 100 THEN e.scroll_depth ELSE 0 END) scroll_depth FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_type='engagement'{$where} GROUP BY e.session_id,e.path) x",$start,$end),ARRAY_A)?:[];
        return [
            'pageviews'=>(int)($base['pageviews']??0),'events'=>(int)($base['events']??0),'source_events'=>(int)($base['events']??0),
            'source_max_event_id'=>(int)($base['source_max_event_id']??0),'bot_events'=>(int)($base['bot_events']??0),
            'sessions'=>(int)($sessions['sessions']??0),'bounces'=>(int)($sessions['bounces']??0),'entries'=>(int)($sessions['entries']??0),'exits'=>(int)($sessions['exits']??0),
            'engaged_ms'=>(int)($engagement['engaged_ms']??0),'engagement_samples'=>(int)($engagement['engagement_samples']??0),
            'scroll_sum'=>(int)($engagement['scroll_sum']??0),'scroll_samples'=>(int)($engagement['scroll_samples']??0),
        ];
    }

    private function rawSketch(string $start,string $end,string $actor,string $column): string
    {
        global $wpdb;
        if(!in_array($column,['visitor_id','session_id'],true))return self::emptySketch();
        $table=TYA_Installer::tableName();$where=$this->rawWhere($actor,'e');$cursor='';$sketch=self::emptySketch();
        do {
            $rows=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT e.{$column} FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_type='pageview' AND e.{$column}<>'' AND e.{$column}>%s{$where} ORDER BY e.{$column} LIMIT 1000",$start,$end,$cursor))?:[];
            foreach($rows as $value){$cursor=(string)$value;self::addSketchValue($sketch,$cursor);}
        } while(count($rows)===1000);
        return $sketch;
    }

    /** @return array<int,array<string,mixed>> */
    private function dimensionRows(string $start,string $end,string $actor,?string $onlyType=null): array
    {
        global $wpdb;
        $table=TYA_Installer::tableName();$where=$this->rawWhere($actor,'e');$result=[];
        $definitions=[
            'organization'=>["CONCAT(COALESCE(e.asn,0),CHAR(31),e.asn_org)","MAX(e.asn_org)",'1=1','e.asn,e.asn_org'],
            'referrer'=>["COALESCE(NULLIF(e.referrer_host,''),'Direct')","COALESCE(NULLIF(e.referrer_host,''),'Direct')","e.event_type='pageview'",'e.referrer_host'],
            'traffic_channel'=>["COALESCE(NULLIF(e.traffic_channel,''),'Unknown')","COALESCE(NULLIF(e.traffic_channel,''),'Unknown')",'1=1','e.traffic_channel'],
            'traffic_source'=>["CONCAT(COALESCE(NULLIF(e.traffic_channel,''),'Unknown'),CHAR(31),COALESCE(NULLIF(e.referrer_host,''),'Direct'))","COALESCE(NULLIF(e.referrer_host,''),'Direct')",'1=1','e.traffic_channel,e.referrer_host'],
            'campaign'=>["CONCAT(e.utm_source,CHAR(31),e.utm_medium,CHAR(31),e.utm_campaign)","MAX(e.utm_campaign)","(e.utm_source<>'' OR e.utm_medium<>'' OR e.utm_campaign<>'')",'e.utm_source,e.utm_medium,e.utm_campaign'],
            'event'=>["CONCAT(e.event_type,CHAR(31),e.event_name)","MAX(COALESCE(NULLIF(e.event_name,''),e.event_type))",'1=1','e.event_type,e.event_name'],
            'country'=>["COALESCE(NULLIF(e.country_code,''),'--')","MAX(COALESCE(NULLIF(e.country_name,''),'Unknown'))","e.event_type='pageview'",'e.country_code'],
            'browser'=>["COALESCE(NULLIF(e.browser,''),'Unknown')","COALESCE(NULLIF(e.browser,''),'Unknown')","e.event_type='pageview'",'e.browser'],
            'os'=>["COALESCE(NULLIF(e.os,''),'Unknown')","COALESCE(NULLIF(e.os,''),'Unknown')","e.event_type='pageview'",'e.os'],
            'device'=>["COALESCE(NULLIF(e.device_type,''),'Unknown')","COALESCE(NULLIF(e.device_type,''),'Unknown')","e.event_type='pageview'",'e.device_type'],
        ];
        foreach($definitions as $type=>[$key,$label,$condition,$group]){
            if($onlyType!==null&&$onlyType!==$type)continue;
            $limit=self::DIMENSION_CAPS[$type];
            $sql="SELECT {$key} dimension_key,{$label} dimension_label,SUM(e.event_type='pageview') pageviews,COUNT(*) events,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND {$condition}{$where} GROUP BY {$group} ORDER BY pageviews DESC,events DESC LIMIT %d";
            foreach($wpdb->get_results($wpdb->prepare($sql,$start,$end,$limit),ARRAY_A)?:[] as $row)$result[]=$this->dimensionRow($type,$row);
        }
        if($onlyType===null||$onlyType==='content'){
        $contentSql="SELECT e.path dimension_key,MAX(e.page_title) dimension_label,COUNT(*) pageviews,COUNT(*) events,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_type='pageview'{$where} GROUP BY e.path ORDER BY pageviews DESC LIMIT %d";
        $contentParams=[$start,$end,self::DIMENSION_CAPS['content']];
        $content=[];
        foreach($wpdb->get_results($wpdb->prepare($contentSql,...$contentParams),ARRAY_A)?:[] as $row){$content[(string)$row['dimension_key']]=$this->dimensionRow('content',$row);}
        $sessionFacts="SELECT e.session_id,COUNT(*) pageviews,SUBSTRING(MIN(CONCAT(DATE_FORMAT(e.occurred_at,'%Y%m%d%H%i%s'),LPAD(e.event_id,20,'0'),e.path)),35) entry_path,SUBSTRING(MAX(CONCAT(DATE_FORMAT(e.occurred_at,'%Y%m%d%H%i%s'),LPAD(e.event_id,20,'0'),e.path)),35) exit_path FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_type='pageview' AND e.session_id<>''{$where} GROUP BY e.session_id";
        $entriesSql="SELECT s.entry_path path,COUNT(*) entries,SUM(s.pageviews=1) bounces FROM ({$sessionFacts}) s GROUP BY s.entry_path";
        foreach($wpdb->get_results($wpdb->prepare($entriesSql,$start,$end),ARRAY_A)?:[] as $row){$key=(string)$row['path'];if(!isset($content[$key]))continue;$content[$key]['entries']=(int)$row['entries'];$content[$key]['bounces']=(int)$row['bounces'];}
        $exitsSql="SELECT s.exit_path path,COUNT(*) exits FROM ({$sessionFacts}) s GROUP BY s.exit_path";
        foreach($wpdb->get_results($wpdb->prepare($exitsSql,$start,$end),ARRAY_A)?:[] as $row){$key=(string)$row['path'];if(isset($content[$key]))$content[$key]['exits']=(int)$row['exits'];}
        $engageSql="SELECT x.path,COALESCE(SUM(x.duration_ms),0) engaged_ms,SUM(x.duration_ms>0) engagement_samples,COALESCE(SUM(x.scroll_depth),0) scroll_sum,SUM(x.scroll_depth>0) scroll_samples FROM (SELECT e.session_id,e.path,MAX(e.duration_ms) duration_ms,MAX(CASE WHEN e.scroll_depth BETWEEN 1 AND 100 THEN e.scroll_depth ELSE 0 END) scroll_depth FROM {$table} e WHERE e.occurred_at>=%s AND e.occurred_at<%s AND e.event_type='engagement'{$where} GROUP BY e.session_id,e.path) x GROUP BY x.path";
        foreach($wpdb->get_results($wpdb->prepare($engageSql,$start,$end),ARRAY_A)?:[] as $row){$key=(string)$row['path'];if(!isset($content[$key]))continue;foreach(['engaged_ms','engagement_samples','scroll_sum','scroll_samples'] as $metric)$content[$key][$metric]=(int)($row[$metric]??0);}
        array_push($result,...array_values($content));
        }
        return $result;
    }

    /** @param array<string,array<string,mixed>> $merged @param array<string,mixed> $row */
    private function mergeDimension(array &$merged,array $row): void
    {
        $key=isset($row['row_hash'])?(string)$row['row_hash']:(isset($row['dimension_hash'])?strtoupper(bin2hex((string)$row['dimension_hash'])):strtoupper(hash('sha256',(string)($row['dimension_key']??''))));
        if(!isset($merged[$key]))$merged[$key]=['dimension_key'=>(string)($row['dimension_key']??''),'dimension_label'=>(string)($row['dimension_label']??''),'pageviews'=>0,'events'=>0,'visitors'=>0,'sessions'=>0,'bounces'=>0,'entries'=>0,'exits'=>0,'engaged_ms'=>0,'engagement_samples'=>0,'scroll_sum'=>0,'scroll_samples'=>0,'last_seen'=>null];
        foreach(['pageviews','events','visitors','sessions','bounces','entries','exits','engaged_ms','engagement_samples','scroll_sum','scroll_samples'] as $metric)$merged[$key][$metric]+=(int)($row[$metric]??0);
        if((string)($row['dimension_label']??'')!=='')$merged[$key]['dimension_label']=(string)$row['dimension_label'];
        if((string)($row['last_seen']??'')>(string)($merged[$key]['last_seen']??''))$merged[$key]['last_seen']=$row['last_seen'];
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    private function filterMetadataDimensions(array $rows,string $type,array $filters): array
    {
        global $wpdb;
        $watched=!empty($filters['watched']);$tagId=max(0,(int)($filters['tag_id']??0));
        if(!$watched&&$tagId===0)return $rows;
        $entityType=match($type){'organization'=>'organization','content'=>'content','referrer','traffic_source'=>'referrer',default=>''};
        if($entityType==='')return [];
        $annotations=TYA_Installer::annotationsTable();$params=[$entityType];
        $sql="SELECT CONVERT(a.entity_key USING utf8mb4) entity_key FROM {$annotations} a";
        if($tagId>0){$sql.=' JOIN '.TYA_Installer::entityTagsTable().' r ON r.annotation_id=a.annotation_id';$params[]=$tagId;}
        $where=['a.entity_type=%s'];
        if($watched)$where[]='a.watched=1';
        if($tagId>0)$where[]='r.tag_id=%d';
        $keys=array_fill_keys(array_map('strval',$wpdb->get_col($wpdb->prepare($sql.' WHERE '.implode(' AND ',$where),...$params))?:[]),true);
        return array_values(array_filter($rows,static function(array $row)use($type,$keys):bool{$key=(string)$row['dimension_key'];if($type==='organization')$key=explode(chr(31),$key,2)[0];elseif($type==='content')$key=explode('?',$key,2)[0];elseif($type==='traffic_source')$key=explode(chr(31),$key,2)[1]??'';return isset($keys[$key]);}));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dimensionRow(string $type,array $row): array
    {
        $key=(string)($row['dimension_key']??'');
        return [
            'dimension_type'=>$type,'dimension_hash'=>hash('sha256',$key,true),'dimension_key'=>self::truncate($key,512),'dimension_label'=>self::truncate((string)($row['dimension_label']??''),512),
            'pageviews'=>(int)($row['pageviews']??0),'events'=>(int)($row['events']??0),'visitors'=>(int)($row['visitors']??0),'sessions'=>(int)($row['sessions']??0),
            'bounces'=>(int)($row['bounces']??0),'entries'=>(int)($row['entries']??0),'exits'=>(int)($row['exits']??0),
            'engaged_ms'=>(int)($row['engaged_ms']??0),'engagement_samples'=>(int)($row['engagement_samples']??0),
            'scroll_sum'=>(int)($row['scroll_sum']??0),'scroll_samples'=>(int)($row['scroll_samples']??0),'last_seen'=>$row['last_seen']??null,
        ];
    }

    private function rawWhere(string $actor,string $alias): string
    {
        $actorSql=$actor==='human'?" AND {$alias}.is_bot=0":($actor==='bot'?" AND {$alias}.is_bot=1":'');
        return $actorSql.TYA_Plugin::instance()->analysisWhere($alias);
    }

    private static function addSketchValue(string &$sketch,string $value): void
    {
        $hash=hash('sha256',$value,true);$index=(unpack('n',substr($hash,0,2))[1]??0)&(self::SKETCH_REGISTERS-1);$rank=1;
        for($i=2;$i<strlen($hash);$i++){ $byte=ord($hash[$i]);if($byte===0){$rank+=8;continue;}for($bit=7;$bit>=0;$bit--){if(($byte&(1<<$bit))!==0)break;$rank++;}break; }
        if($rank>ord($sketch[$index]))$sketch[$index]=chr(min(255,$rank));
    }

    private function queue(string $from,string $to,string $source): void
    {
        $state=$this->state('running',$from,$to,$from,0,'');$state['source']=$source;update_option(self::STATE_OPTION,$state,false);
    }

    private function acquireLock(): string
    {
        $now=time();$lock=get_option(self::LOCK_OPTION,null);
        if(is_array($lock)&&(int)($lock['expires']??0)>$now)return '';
        if($lock!==null)delete_option(self::LOCK_OPTION);
        $token=wp_generate_uuid4();
        return add_option(self::LOCK_OPTION,['token'=>$token,'expires'=>$now+self::LOCK_SECONDS],'',false)?$token:'';
    }

    private function releaseLock(string $token): void
    {
        $lock=get_option(self::LOCK_OPTION,[]);if(is_array($lock)&&hash_equals($token,(string)($lock['token']??'')))delete_option(self::LOCK_OPTION);
    }

    private function otherJobLocked(): bool
    {
        $lock=get_option('tya_cleanup_lock',null);$state=get_option('tya_cleanup_state',[]);
        return (is_array($lock)&&(int)($lock['expires']??0)>time())||(is_array($state)&&($state['status']??'')==='running');
    }

    /** @return array<string,mixed> */
    private function state(string $status,string $from,string $to,string $current,int $completed,string $error): array
    {
        return ['status'=>$status,'from'=>$from?:null,'to'=>$to?:null,'current_day'=>$current?:null,'completed_days'=>$completed,'last_run'=>gmdate('c'),'error'=>$error];
    }

    private static function validDay(mixed $value): string
    {
        if(!is_scalar($value))return '';$value=(string)$value;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));return $date!==false&&$date->format('Y-m-d')===$value?$value:'';
    }

    private static function daysBetween(string $from,string $to): int
    {
        return (int)((strtotime($to.' 00:00:00 UTC')-strtotime($from.' 00:00:00 UTC'))/DAY_IN_SECONDS);
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }

    private function response(array $data,int $status=200): WP_REST_Response{$response=new WP_REST_Response($data,$status);$response->header('Cache-Control','no-store, private');return $response;}
    private function error(string $message,int $status): WP_REST_Response{return $this->response(['ok'=>false,'message'=>$message],$status);}
}
