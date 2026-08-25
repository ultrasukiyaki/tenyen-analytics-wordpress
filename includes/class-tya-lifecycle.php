<?php

declare(strict_types=1);

use Tenyen\Analytics\Crypto;

if (!defined('ABSPATH')) exit;

final class TYA_Lifecycle
{
    private const STATE_OPTION = 'tya_cleanup_state';
    private const LOCK_OPTION = 'tya_cleanup_lock';
    private const BATCH_SIZE = 1000;
    private const EXPORT_CHUNK = 500;
    private const DATASETS = ['events', 'sessions', 'content', 'organizations', 'traffic_sources', 'campaigns', 'event_summary'];
    private const IP_MODES = ['omit', 'masked', 'raw'];

    public function boot(): void
    {
        if (!wp_next_scheduled('tya_daily_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tya_daily_cleanup');
        add_action('tya_daily_cleanup', [$this, 'scheduledCleanup']);
        add_action('tya_cleanup_continue', [$this, 'scheduledCleanup']);
        add_action('admin_post_tya_export', [$this, 'export']);
    }

    public function registerRoutes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('tenyen-analytics/v1', '/admin/lifecycle/diagnostics', [
            'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'diagnosticsRest'], 'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/lifecycle/retention', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'retentionRest'], 'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/lifecycle/cleanup/preview', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'previewRest'], 'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/lifecycle/cleanup/run', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'cleanupRest'], 'permission_callback' => $permission,
        ]);
    }

    public function diagnosticsRest(): WP_REST_Response
    {
        return $this->response(['ok' => true, 'diagnostics' => $this->diagnostics()]);
    }

    public function retentionRest(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) return $this->error(__('Invalid request body.', 'tenyen-analytics'), 400);
        $modeValue = $input['mode'] ?? '';
        $mode = sanitize_key(is_scalar($modeValue) ? (string)$modeValue : '');
        $rawDays = $input['days'] ?? null;
        $validDays = is_int($rawDays) || (is_string($rawDays) && preg_match('/^\d{1,4}$/', $rawDays));
        $parsedDays = $validDays ? (int)$rawDays : 0;
        if ($mode === 'unlimited') {
            $days = 0;
        } elseif ($mode === 'preset') {
            $days = $parsedDays;
            if (!in_array($days, [30, 90, 180, 365], true)) return $this->error(__('Invalid retention preset.', 'tenyen-analytics'), 400);
        } elseif ($mode === 'custom') {
            $days = $parsedDays;
            if (!$validDays || $days < 1 || $days > 3650) return $this->error(__('Custom retention must be between 1 and 3650 days.', 'tenyen-analytics'), 400);
        } else {
            return $this->error(__('Invalid retention mode.', 'tenyen-analytics'), 400);
        }
        update_option('tya_retention_days', $days, false);
        update_option(self::STATE_OPTION, $this->state('idle', null, 0, 0, ''), false);
        return $this->response(['ok' => true, 'retention_days' => $days, 'warning' => $days > 0 ? __('Raw cleanup requires complete daily aggregates. Detailed session and visitor drill-down is still permanently removed.', 'tenyen-analytics') : '']);
    }

    public function previewRest(): WP_REST_Response
    {
        return $this->response(['ok' => true, 'preview' => $this->preview()]);
    }

    public function cleanupRest(): WP_REST_Response
    {
        $result = $this->runBatch();
        $ok = !in_array($result['status'], ['failed', 'blocked'], true);
        return $this->response(['ok' => $ok, 'cleanup' => $result, 'message' => (string)($result['error'] ?? '')], $result['status'] === 'failed' ? 500 : ($result['status'] === 'blocked' ? 409 : 200));
    }

    public function scheduledCleanup(): void
    {
        $this->runBatch();
    }

    /** @return array<string,mixed> */
    public function preview(): array
    {
        global $wpdb;
        $days = self::sanitizeRetention(get_option('tya_retention_days', 90));
        if ($days === 0) return ['retention_days' => 0, 'cutoff' => null, 'events' => 0, 'sessions' => 0];
        $cutoff = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS) . ' 00:00:00';
        $table = TYA_Installer::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions FROM {$table} WHERE occurred_at<%s",
            $cutoff
        ), ARRAY_A) ?: [];
        $coverage = (new TYA_Aggregation())->coverageBefore($cutoff);
        return ['retention_days' => $days, 'cutoff' => $cutoff, 'events' => (int)($row['events'] ?? 0), 'sessions' => (int)($row['sessions'] ?? 0), 'aggregate_coverage' => $coverage];
    }

    /** @return array<string,mixed> */
    public function runBatch(): array
    {
        global $wpdb;
        $days = self::sanitizeRetention(get_option('tya_retention_days', 90));
        $now = time();
        if ($days === 0) {
            $state = $this->state('idle', null, 0, 0, '');
            update_option(self::STATE_OPTION, $state, false);
            return $state;
        }
        $token = wp_generate_uuid4();
        $lock = get_option(self::LOCK_OPTION, null);
        if (is_array($lock) && (int)($lock['expires'] ?? 0) > $now) return $this->state('locked', null, 0, 0, '');
        if ($lock !== null) delete_option(self::LOCK_OPTION);
        if (!add_option(self::LOCK_OPTION, ['token' => $token, 'expires' => $now + 300], '', false)) return $this->state('locked', null, 0, 0, '');

        $previous = get_option(self::STATE_OPTION, []);
        $resuming = is_array($previous) && ($previous['status'] ?? '') === 'running' && !empty($previous['cutoff']);
        $cutoff = $resuming
            ? (string)$previous['cutoff'] : gmdate('Y-m-d', $now - $days * DAY_IN_SECONDS) . ' 00:00:00';
        $deletedTotal = $resuming && ($previous['cutoff'] ?? '') === $cutoff
            ? (int)($previous['deleted_total'] ?? 0) : 0;
        try {
            $aggregationLock = get_option('tya_aggregation_lock', null);
            $aggregationState = get_option('tya_aggregation_state', []);
            if ((is_array($aggregationLock) && (int)($aggregationLock['expires'] ?? 0) > $now) || (is_array($aggregationState) && ($aggregationState['status'] ?? '') === 'running')) {
                $state = $this->state('blocked', $cutoff, $deletedTotal, 0, __('Cleanup is blocked while aggregation is running.', 'tenyen-analytics'));
                update_option(self::STATE_OPTION, $state, false);
                return $state;
            }
            if (!$resuming) {
                $coverage = (new TYA_Aggregation())->coverageBefore($cutoff);
                if (!$coverage['complete']) {
                    $state = $this->state('blocked', $cutoff, $deletedTotal, 0, (string)$coverage['message']);
                    update_option(self::STATE_OPTION, $state, false);
                    return $state;
                }
                update_option(self::STATE_OPTION, $this->state('running', $cutoff, $deletedTotal, 0, ''), false);
            }
            $table = TYA_Installer::tableName();
            $ids = $wpdb->get_col($wpdb->prepare("SELECT event_id FROM {$table} WHERE occurred_at<%s ORDER BY event_id ASC LIMIT %d", $cutoff, self::BATCH_SIZE)) ?: [];
            $deleted = 0;
            if ($ids !== []) {
                $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
                if ($ids !== []) {
                    $deleteResult = $wpdb->query("DELETE FROM {$table} WHERE event_id IN (" . implode(',', $ids) . ')');
                    if ($deleteResult === false) throw new RuntimeException('cleanup_delete_failed');
                    $deleted = (int)$deleteResult;
                }
            }
            $deletedTotal += max(0, $deleted);
            $remaining = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE occurred_at<%s", $cutoff));
            $status = $remaining > 0 ? 'running' : 'complete';
            $state = $this->state($status, $cutoff, $deletedTotal, $remaining, '');
            update_option(self::STATE_OPTION, $state, false);
            if ($status === 'complete') {
                $frozen = (string)get_option('tya_aggregate_frozen_before', '');
                if ($frozen === '' || $cutoff > $frozen) update_option('tya_aggregate_frozen_before', $cutoff, false);
            }
            if ($remaining > 0 && !wp_next_scheduled('tya_cleanup_continue')) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'tya_cleanup_continue');
        } catch (Throwable) {
            $state = $this->state('failed', $cutoff, $deletedTotal, 0, __('Cleanup failed. Review database health and retry.', 'tenyen-analytics'));
            update_option(self::STATE_OPTION, $state, false);
        } finally {
            $current = get_option(self::LOCK_OPTION, []);
            if (is_array($current) && hash_equals($token, (string)($current['token'] ?? ''))) delete_option(self::LOCK_OPTION);
        }
        return $state;
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        global $wpdb;
        $table = TYA_Installer::tableName();
        $summary = $wpdb->get_row("SELECT COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions,MIN(occurred_at) oldest,MAX(occurred_at) newest FROM {$table}", ARRAY_A) ?: [];
        $months = $wpdb->get_results("SELECT DATE_FORMAT(occurred_at,'%Y-%m') month,COUNT(*) events,COUNT(DISTINCT NULLIF(session_id,'')) sessions FROM {$table} GROUP BY DATE_FORMAT(occurred_at,'%Y-%m') ORDER BY month DESC LIMIT 24", ARRAY_A) ?: [];
        $tableBytes = 0;
        $databaseBytes = 0;
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A) ?: [];
        $tableBytes = (int)($status['Data_length'] ?? 0) + (int)($status['Index_length'] ?? 0);
        if (defined('DB_NAME')) {
            $databaseBytes = (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=%s', (string)DB_NAME));
        }
        $cleanup = get_option(self::STATE_OPTION, []);
        if (!is_array($cleanup)) $cleanup = [];
        $next = wp_next_scheduled('tya_daily_cleanup');
        return [
            'table_bytes' => $tableBytes, 'database_bytes' => $databaseBytes,
            'events' => (int)($summary['events'] ?? 0), 'sessions' => (int)($summary['sessions'] ?? 0),
            'oldest' => ($summary['oldest'] ?? '') ?: null, 'newest' => ($summary['newest'] ?? '') ?: null,
            'monthly' => array_map(static fn(array $row): array => ['month'=>(string)$row['month'],'events'=>(int)$row['events'],'sessions'=>(int)$row['sessions']], $months),
            'retention_days' => self::sanitizeRetention(get_option('tya_retention_days', 90)),
            'cleanup' => $cleanup, 'next_run' => $next ? gmdate('c', $next) : null,
        ];
    }

    public function export(): void
    {
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission.', 'tenyen-analytics'), '', ['response' => 403]);
        check_admin_referer('tya_export');
        $queryValue = static fn(string $key, string $default): string => isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string)$_GET[$key] : $default;
        $dataset = sanitize_key($queryValue('dataset', 'events'));
        $format = sanitize_key($queryValue('format', 'csv'));
        $ipMode = sanitize_key($queryValue('ip_mode', 'omit'));
        $requestError = self::exportRequestError($dataset, $format, $ipMode, $queryValue('confirm_raw', '') === '1');
        if ($requestError === 'invalid') wp_die(__('Invalid export request.', 'tenyen-analytics'), '', ['response' => 400]);
        if ($requestError === 'raw_confirmation') wp_die(__('Raw IP export requires explicit confirmation.', 'tenyen-analytics'), '', ['response' => 400]);
        $filters = $this->exportFilters($_GET);
        $filename = 'tenyen-analytics-' . $dataset . '-' . gmdate('Ymd-His') . '.' . $format;
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8'));
        $this->streamExport($dataset, $format, $ipMode, $filters);
        exit;
    }

    /** @param array<string,mixed> $filters */
    private function streamExport(string $dataset, string $format, string $ipMode, array $filters): void
    {
        $columns = $this->columns($dataset, $ipMode);
        $csv = null;
        if ($format === 'csv') {
            $csv = fopen('php://output', 'wb');
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, $columns, ',', '"', '');
        } else {
            echo '{"schema":"tenyen-analytics.export.v1","dataset":' . wp_json_encode($dataset)
                . ',"generated_at":' . wp_json_encode(gmdate('c'))
                . ',"columns":' . wp_json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ',"rows":[';
        }
        $first = true;
        $offset = 0;
        $cursor = 0;
        while (true) {
            $rows = $this->exportChunk($dataset, $filters, $offset, $cursor);
            if ($rows === []) break;
            foreach ($rows as $row) {
                if ($dataset === 'events') {
                    $cursor = max($cursor, (int)$row['event_id']);
                    $row = $this->eventExportRow($row, $ipMode);
                }
                $values = [];
                foreach ($columns as $column) $values[$column] = $row[$column] ?? null;
                if ($format === 'csv') fputcsv($csv, array_map([self::class, 'csvCell'], array_values($values)), ',', '"', '');
                else { if (!$first) echo ','; echo wp_json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $first = false; }
            }
            if ($dataset !== 'events') $offset += count($rows);
            if (count($rows) < self::EXPORT_CHUNK) break;
            if (function_exists('flush')) flush();
        }
        if ($format === 'json') echo ']}' . "\n";
        if (is_resource($csv)) fclose($csv);
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    private function exportChunk(string $dataset, array $filters, int $offset, int $cursor): array
    {
        global $wpdb;
        $aggregateRows = $this->aggregateExportChunk($dataset, $filters, $offset);
        if ($aggregateRows !== null) return $aggregateRows;
        $table = TYA_Installer::tableName();
        [$where, $params] = $this->exportWhere($filters, 'e');
        if ($dataset === 'events') {
            $where[] = 'e.event_id>%d'; $params[] = $cursor;
            $sql = "SELECT e.* FROM {$table} e WHERE " . implode(' AND ', $where) . ' ORDER BY e.event_id ASC LIMIT %d';
            $params[] = self::EXPORT_CHUNK;
            return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        }
        $select = match ($dataset) {
            'sessions' => "e.session_id,MIN(e.occurred_at) started_at,MAX(e.occurred_at) ended_at,MAX(e.visitor_id) visitor_id,SUM(e.event_type='pageview') pageviews,COUNT(*) events,MAX(e.country_code) country_code,MAX(e.asn) asn,MAX(e.asn_org) organization,MAX(e.traffic_channel) traffic_channel,MAX(e.utm_campaign) utm_campaign",
            'content' => "e.path,MAX(e.page_title) page_title,COUNT(*) pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions",
            'organizations' => "e.asn,e.asn_org organization,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.visitor_id,'')) visitors,MAX(e.occurred_at) last_seen",
            'traffic_sources' => "e.traffic_channel,e.referrer_host,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions",
            'campaigns' => "e.utm_source,e.utm_medium,e.utm_campaign,COUNT(*) events,SUM(e.event_type='pageview') pageviews,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions",
            default => "e.event_type,e.event_name,COUNT(*) events,COUNT(DISTINCT NULLIF(e.session_id,'')) sessions,MAX(e.occurred_at) last_seen",
        };
        $group = match ($dataset) {
            'sessions' => 'e.session_id', 'content' => 'e.path', 'organizations' => 'e.asn,e.asn_org',
            'traffic_sources' => 'e.traffic_channel,e.referrer_host', 'campaigns' => 'e.utm_source,e.utm_medium,e.utm_campaign',
            default => 'e.event_type,e.event_name',
        };
        if ($dataset === 'sessions') $where[] = "e.session_id<>''";
        if ($dataset === 'content') $where[] = "e.event_type='pageview'";
        if ($dataset === 'campaigns') $where[] = "(e.utm_source<>'' OR e.utm_medium<>'' OR e.utm_campaign<>'')";
        $sql = "SELECT {$select} FROM {$table} e WHERE " . implode(' AND ', $where) . " GROUP BY {$group} ORDER BY {$group} LIMIT %d OFFSET %d";
        array_push($params, self::EXPORT_CHUNK, $offset);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>>|null */
    private function aggregateExportChunk(string $dataset,array $filters,int $offset): ?array
    {
        global $wpdb;
        $type=match($dataset){'content'=>'content','organizations'=>'organization','traffic_sources'=>'traffic_source','campaigns'=>'campaign','event_summary'=>'event',default=>''};
        if($type==='')return null;
        $allowed=match($type){
            'content'=>['path'],'organization'=>['asn','asn_org'],'traffic_source'=>['traffic_channel','referrer_host'],
            'campaign'=>['utm_source','utm_medium','utm_campaign'],'event'=>['event_type','event_name'],default=>[],
        };
        foreach(['traffic_channel','referrer_host','utm_source','utm_medium','utm_campaign','event_type','event_name','path','country_code','region','asn','asn_org'] as $key)if($filters[$key]!==''&&!in_array($key,$allowed,true))return null;
        if($filters['watched']&&$type!=='organization')return null;
        if($filters['tag_id']>0&&!in_array($type,['organization','content','traffic_source'],true))return null;
        $oldestAggregate=(string)$wpdb->get_var('SELECT MIN(aggregate_day) FROM '.TYA_Installer::dailyAggregatesTable());
        $oldestRaw=(string)$wpdb->get_var('SELECT DATE(MIN(occurred_at)) FROM '.TYA_Installer::tableName());
        $starts=array_values(array_filter([$filters['from'],$oldestAggregate,$oldestRaw],static fn(string $value):bool=>$value!==''));
        if($starts===[])return null;
        $start=($filters['from']!==''?$filters['from']:min($starts)).' 00:00:00';
        $endDay=$filters['to']!==''?$filters['to']:gmdate('Y-m-d');
        $end=gmdate('Y-m-d H:i:s',strtotime($endDay.' 00:00:00 UTC')+DAY_IN_SECONDS);
        $aggregation=new TYA_Aggregation();
        if($aggregation->boundary($start,$end,$filters['actor'])['source']==='raw')return null;
        $cap=match($type){'content'=>500,'organization'=>100,'traffic_source'=>300,'campaign','event'=>200,default=>100};
        $rows=$aggregation->dimensions($type,$start,$end,$filters['actor'],$cap,['watched'=>$filters['watched'],'tag_id'=>$filters['tag_id']]);
        $rows=array_values(array_filter($rows,function(array $row)use($type,$filters):bool{
            $key=(string)$row['dimension_key'];$parts=explode(chr(31),$key);
            return match($type){
                'content'=>$filters['path']===''||str_contains($key,$filters['path']),
                'organization'=>($filters['asn']===''||(int)($parts[0]??0)===(int)$filters['asn'])&&($filters['asn_org']===''||stripos((string)($parts[1]??''),$filters['asn_org'])!==false),
                'traffic_source'=>($filters['traffic_channel']===''||($parts[0]??'')===$filters['traffic_channel'])&&($filters['referrer_host']===''||($parts[1]??'')===$filters['referrer_host']),
                'campaign'=>($filters['utm_source']===''||($parts[0]??'')===$filters['utm_source'])&&($filters['utm_medium']===''||($parts[1]??'')===$filters['utm_medium'])&&($filters['utm_campaign']===''||($parts[2]??'')===$filters['utm_campaign']),
                'event'=>($filters['event_type']===''||($parts[0]??'')===$filters['event_type'])&&($filters['event_name']===''||($parts[1]??'')===$filters['event_name']),default=>true,
            };
        }));
        $rows=array_slice($rows,$offset,self::EXPORT_CHUNK);
        return array_map(static function(array $row)use($type):array{
            $parts=explode(chr(31),(string)$row['dimension_key']);
            return match($type){
                'content'=>['path'=>$row['dimension_key'],'page_title'=>$row['dimension_label'],'pageviews'=>$row['pageviews'],'visitors'=>$row['visitors'],'sessions'=>$row['sessions']],
                'organization'=>['asn'=>(int)($parts[0]??0),'organization'=>(string)($parts[1]??$row['dimension_label']),'events'=>$row['events'],'pageviews'=>$row['pageviews'],'visitors'=>$row['visitors'],'last_seen'=>$row['last_seen']],
                'traffic_source'=>['traffic_channel'=>(string)($parts[0]??''),'referrer_host'=>(string)($parts[1]??''),'events'=>$row['events'],'pageviews'=>$row['pageviews'],'sessions'=>$row['sessions']],
                'campaign'=>['utm_source'=>(string)($parts[0]??''),'utm_medium'=>(string)($parts[1]??''),'utm_campaign'=>(string)($parts[2]??''),'events'=>$row['events'],'pageviews'=>$row['pageviews'],'sessions'=>$row['sessions']],
                default=>['event_type'=>(string)($parts[0]??''),'event_name'=>(string)($parts[1]??''),'events'=>$row['events'],'sessions'=>$row['sessions'],'last_seen'=>$row['last_seen']],
            };
        },$rows);
    }

    /** @param array<string,mixed> $filters @return array{0:array<int,string>,1:array<int,mixed>} */
    private function exportWhere(array $filters, string $alias): array
    {
        global $wpdb;
        $where = ['1=1']; $params = [];
        if ($filters['from'] !== '') { $where[] = "{$alias}.occurred_at>=%s"; $params[] = $filters['from'] . ' 00:00:00'; }
        if ($filters['to'] !== '') { $where[] = "{$alias}.occurred_at<%s"; $params[] = gmdate('Y-m-d H:i:s', strtotime($filters['to'] . ' 00:00:00 UTC') + DAY_IN_SECONDS); }
        if ($filters['actor'] === 'human') $where[] = "{$alias}.is_bot=0";
        elseif ($filters['actor'] === 'bot') $where[] = "{$alias}.is_bot=1";
        foreach (['traffic_channel','referrer_host','utm_source','utm_medium','utm_campaign','event_type','event_name','path','country_code','region','asn','asn_org'] as $column) {
            if ($filters[$column] === '') continue;
            if ($column === 'asn') { $where[] = "{$alias}.asn=%d"; $params[] = (int)$filters[$column]; }
            elseif ($column === 'path') { $where[] = "{$alias}.path LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters[$column]) . '%'; }
            elseif ($column === 'asn_org') { $where[] = "{$alias}.asn_org LIKE %s"; $params[] = '%' . $wpdb->esc_like($filters[$column]) . '%'; }
            else { $where[] = "{$alias}.{$column}=%s"; $params[] = $filters[$column]; }
        }
        if ($filters['watched']) $where[] = "EXISTS (SELECT 1 FROM " . TYA_Installer::annotationsTable() . " a WHERE a.entity_type='organization' AND a.watched=1 AND CAST(a.entity_key AS UNSIGNED)={$alias}.asn)";
        if ($filters['tag_id'] > 0) {
            $where[] = "EXISTS (SELECT 1 FROM " . TYA_Installer::annotationsTable() . ' a JOIN ' . TYA_Installer::entityTagsTable() . " r ON r.annotation_id=a.annotation_id WHERE r.tag_id=%d AND ((a.entity_type='organization' AND CAST(a.entity_key AS UNSIGNED)={$alias}.asn) OR (a.entity_type='content' AND CONVERT(a.entity_key USING utf8mb4)=SUBSTRING_INDEX({$alias}.path,CHAR(63),1)) OR (a.entity_type='visitor' AND CONVERT(a.entity_key USING utf8mb4)={$alias}.visitor_id) OR (a.entity_type='referrer' AND CONVERT(a.entity_key USING utf8mb4)={$alias}.referrer_host)))";
            $params[] = $filters['tag_id'];
        }
        $excluded = TYA_Plugin::instance()->analysisWhere($alias);
        if ($excluded !== '') $where[] = substr($excluded, 5);
        return [$where, $params];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function exportFilters(array $source): array
    {
        $date = static function (mixed $value): string {
            if (!is_scalar($value)) return '';
            $value = (string)$value;
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };
        $text = static function (mixed $value, int $length = 256): string { if (!is_scalar($value)) return ''; $value = sanitize_text_field(wp_unslash((string)$value)); return function_exists('mb_substr') ? mb_substr($value,0,$length,'UTF-8') : substr($value,0,$length); };
        $actorValue = $source['actor'] ?? 'all';
        $actor = sanitize_key(is_scalar($actorValue) ? (string)$actorValue : 'all'); if (!in_array($actor,['all','human','bot'],true)) $actor='all';
        $asnValue = $source['asn'] ?? '';
        $tagValue = $source['tag_id'] ?? 0;
        $filters = [
            'from'=>$date($source['from']??''),'to'=>$date($source['to']??''),'actor'=>$actor,
            'traffic_channel'=>$text($source['traffic_channel']??'',32),'referrer_host'=>$text($source['referrer_host']??'',255),'utm_source'=>$text($source['utm_source']??'',128),
            'utm_medium'=>$text($source['utm_medium']??'',128),'utm_campaign'=>$text($source['utm_campaign']??'',256),
            'event_type'=>$text($source['event_type']??'',32),'event_name'=>$text($source['event_name']??'',64),
            'path'=>$text($source['path']??'',512),'country_code'=>strtoupper($text($source['country_code']??'',2)),
            'region'=>$text($source['region']??'',128),'asn'=>is_scalar($asnValue) && preg_match('/^\d{1,10}$/',(string)$asnValue)?(string)(int)$asnValue:'',
            'asn_org'=>$text($source['asn_org']??'',255),'watched'=>($source['watched']??'')==='1','tag_id'=>is_scalar($tagValue) && preg_match('/^\d{1,20}$/',(string)$tagValue)?max(0,(int)$tagValue):0,
        ];
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) [$filters['from'],$filters['to']] = [$filters['to'],$filters['from']];
        return $filters;
    }

    /** @return array<int,string> */
    private function columns(string $dataset, string $ipMode): array
    {
        if ($dataset === 'events') {
            $columns = ['event_id','occurred_at','event_type','event_name','event_meta','visitor_id','session_id','country_code','country_name','region','city','asn','asn_org','path','page_title','referrer','target_url','target_host','traffic_channel','referrer_host','utm_source','utm_medium','utm_campaign','utm_content','utm_term','user_agent','browser','os','device_type','language','timezone','screen','viewport','duration_ms','scroll_depth','is_bot'];
            if ($ipMode !== 'omit') array_splice($columns, 6, 0, ['ip']);
            return $columns;
        }
        return match ($dataset) {
            'sessions'=>['session_id','started_at','ended_at','visitor_id','pageviews','events','country_code','asn','organization','traffic_channel','utm_campaign'],
            'content'=>['path','page_title','pageviews','visitors','sessions'],
            'organizations'=>['asn','organization','events','pageviews','visitors','last_seen'],
            'traffic_sources'=>['traffic_channel','referrer_host','events','pageviews','sessions'],
            'campaigns'=>['utm_source','utm_medium','utm_campaign','events','pageviews','sessions'],
            default=>['event_type','event_name','events','sessions','last_seen'],
        };
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function eventExportRow(array $row, string $ipMode): array
    {
        if ($ipMode !== 'omit') {
            $raw = $this->crypto()->decryptIp(isset($row['ip_encrypted']) ? (string)$row['ip_encrypted'] : null);
            $row['ip'] = $ipMode === 'raw' ? $raw : self::maskIp($raw);
        }
        unset($row['ip_encrypted'], $row['ip_hash'], $row['latitude'], $row['longitude'], $row['accuracy_radius'], $row['ip_version']);
        return $row;
    }

    public static function csvCell(mixed $value): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    public static function exportRequestError(string $dataset, string $format, string $ipMode, bool $rawConfirmed): string
    {
        if (!in_array($dataset, self::DATASETS, true) || !in_array($format, ['csv','json'], true) || !in_array($ipMode, self::IP_MODES, true)) return 'invalid';
        return $ipMode === 'raw' && !$rawConfirmed ? 'raw_confirmation' : '';
    }

    public static function maskIp(string $ip): string
    {
        $bytes = @inet_pton($ip);
        if ($bytes === false) return '';
        if (strlen($bytes) === 4) { $bytes[3] = "\0"; return (string)inet_ntop($bytes); }
        for ($i=6;$i<16;$i++) $bytes[$i]="\0";
        return (string)inet_ntop($bytes);
    }

    public static function sanitizeRetention(mixed $value): int
    {
        $days = is_numeric($value) ? (int)$value : 90;
        return $days === 0 ? 0 : max(1, min(3650, $days));
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission.', 'tenyen-analytics'));
        ?>
        <div class="wrap tya-pages" id="tya-lifecycle"><header class="tya-page-head"><div><h1><?= esc_html__('Data lifecycle', 'tenyen-analytics') ?> <small>v<?= esc_html(TYA_VERSION) ?></small></h1><p><?= esc_html__('Export analytics, control raw retention, run bounded cleanup, and inspect storage.', 'tenyen-analytics') ?></p></div></header>
        <div class="notice notice-warning inline"><p><?= esc_html__('Daily aggregates preserve supported totals, but raw cleanup permanently removes detailed session and visitor drill-down.', 'tenyen-analytics') ?></p></div>
        <section class="tya-panel"><h2><?= esc_html__('Daily aggregation', 'tenyen-analytics') ?></h2><p><?= esc_html__('Completed UTC days are aggregated incrementally. Rebuild a day or range after late events or exclusion changes.', 'tenyen-analytics') ?></p><pre data-aggregation-status><?= esc_html__('Loading…', 'tenyen-analytics') ?></pre><form data-aggregation-form class="tya-exclusion-form"><label><?= esc_html__('From', 'tenyen-analytics') ?><input type="date" name="from" required></label><label><?= esc_html__('To', 'tenyen-analytics') ?><input type="date" name="to" required></label><button class="button"><?= esc_html__('Rebuild aggregates', 'tenyen-analytics') ?></button></form></section>
        <section class="tya-panel"><h2><?= esc_html__('Retention and cleanup', 'tenyen-analytics') ?></h2><form data-retention-form class="tya-exclusion-form"><label><?= esc_html__('Mode', 'tenyen-analytics') ?><select name="mode"><option value="unlimited"><?= esc_html__('Unlimited', 'tenyen-analytics') ?></option><option value="preset"><?= esc_html__('Preset', 'tenyen-analytics') ?></option><option value="custom"><?= esc_html__('Custom days', 'tenyen-analytics') ?></option></select></label><label><?= esc_html__('Days', 'tenyen-analytics') ?><input type="number" name="days" min="1" max="3650" value="90"></label><button class="button button-primary"><?= esc_html__('Save retention', 'tenyen-analytics') ?></button></form><p data-lifecycle-status aria-live="polite"></p><p><button class="button" data-cleanup-preview><?= esc_html__('Preview cleanup', 'tenyen-analytics') ?></button> <button class="button button-secondary" data-cleanup-run><?= esc_html__('Run one cleanup batch', 'tenyen-analytics') ?></button></p><pre data-cleanup-result></pre></section>
        <section class="tya-panel"><h2><?= esc_html__('Storage diagnostics', 'tenyen-analytics') ?></h2><div data-storage-diagnostics><?= esc_html__('Loading…', 'tenyen-analytics') ?></div></section>
        <section class="tya-panel"><h2><?= esc_html__('Export', 'tenyen-analytics') ?></h2><form method="get" action="<?= esc_url(admin_url('admin-post.php')) ?>" class="tya-exclusion-form" data-export-form><input type="hidden" name="action" value="tya_export"><?php wp_nonce_field('tya_export'); ?><label><?= esc_html__('Dataset', 'tenyen-analytics') ?><select name="dataset"><?php foreach (self::DATASETS as $dataset): ?><option value="<?= esc_attr($dataset) ?>"><?= esc_html($dataset) ?></option><?php endforeach; ?></select></label><label><?= esc_html__('Format', 'tenyen-analytics') ?><select name="format"><option value="csv">CSV</option><option value="json">JSON</option></select></label><label><?= esc_html__('IP privacy', 'tenyen-analytics') ?><select name="ip_mode"><option value="omit"><?= esc_html__('Omit IP', 'tenyen-analytics') ?></option><option value="masked"><?= esc_html__('Masked IP', 'tenyen-analytics') ?></option><option value="raw"><?= esc_html__('Decrypted raw IP', 'tenyen-analytics') ?></option></select></label><label><input type="checkbox" name="confirm_raw" value="1"> <?= esc_html__('I explicitly authorize raw IP export', 'tenyen-analytics') ?></label><label><?= esc_html__('From', 'tenyen-analytics') ?><input type="date" name="from"></label><label><?= esc_html__('To', 'tenyen-analytics') ?><input type="date" name="to"></label><label><?= esc_html__('Visitor type', 'tenyen-analytics') ?><select name="actor"><option value="all"><?= esc_html__('All', 'tenyen-analytics') ?></option><option value="human"><?= esc_html__('Humans only', 'tenyen-analytics') ?></option><option value="bot"><?= esc_html__('Bots only', 'tenyen-analytics') ?></option></select></label><label><?= esc_html__('Traffic channel', 'tenyen-analytics') ?><input name="traffic_channel" maxlength="32"></label><label><?= esc_html__('Referrer domain', 'tenyen-analytics') ?><input name="referrer_host" maxlength="255"></label><label><?= esc_html__('UTM source', 'tenyen-analytics') ?><input name="utm_source" maxlength="128"></label><label><?= esc_html__('UTM medium', 'tenyen-analytics') ?><input name="utm_medium" maxlength="128"></label><label><?= esc_html__('Campaign', 'tenyen-analytics') ?><input name="utm_campaign" maxlength="256"></label><label><?= esc_html__('Event type', 'tenyen-analytics') ?><input name="event_type" maxlength="32"></label><label><?= esc_html__('Event name', 'tenyen-analytics') ?><input name="event_name" maxlength="64"></label><label><?= esc_html__('Content path', 'tenyen-analytics') ?><input name="path" maxlength="512"></label><label><?= esc_html__('Country code', 'tenyen-analytics') ?><input name="country_code" maxlength="2"></label><label><?= esc_html__('Region', 'tenyen-analytics') ?><input name="region" maxlength="128"></label><label>ASN<input name="asn" maxlength="10"></label><label><?= esc_html__('Organization', 'tenyen-analytics') ?><input name="asn_org" maxlength="255"></label><label><?= esc_html__('Tag ID', 'tenyen-analytics') ?><input type="number" name="tag_id" min="0"></label><label><input type="checkbox" name="watched" value="1"> <?= esc_html__('Watched organizations only', 'tenyen-analytics') ?></label><button class="button button-primary"><?= esc_html__('Download export', 'tenyen-analytics') ?></button></form></section></div>
        <?php
    }

    /** @return array<string,mixed> */
    private function state(string $status, ?string $cutoff, int $deleted, int $remaining, string $error): array
    {
        $next = wp_next_scheduled('tya_daily_cleanup');
        return ['status'=>$status,'cutoff'=>$cutoff,'deleted_total'=>$deleted,'remaining'=>$remaining,'last_run'=>gmdate('c'),'next_run'=>$next?gmdate('c',$next):null,'error'=>$error];
    }

    private function crypto(): Crypto
    {
        $encryption = defined('AUTH_KEY') ? (string)AUTH_KEY : wp_salt('auth');
        $hash = defined('SECURE_AUTH_SALT') ? (string)SECURE_AUTH_SALT : wp_salt('secure_auth');
        return new Crypto($encryption . '|tenyen-ip', $hash . '|tenyen-hmac');
    }

    private function response(array $data, int $status = 200): WP_REST_Response { $response = new WP_REST_Response($data, $status); $response->header('Cache-Control','no-store, private'); return $response; }
    private function error(string $message, int $status): WP_REST_Response { return $this->response(['ok'=>false,'message'=>$message],$status); }
}
