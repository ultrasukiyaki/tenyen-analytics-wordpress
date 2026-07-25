<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only session and anonymous-browser journey queries.
 *
 * Stored session_id is canonical. Empty legacy session IDs are excluded rather
 * than inferred from IP, visitor, or environment data. Ties use event_id.
 */
final class TYA_Session_Repository
{
    public function __construct(private object $wpdb, private string $table)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function listSessions(array $filters): array
    {
        [$where, $params] = $this->sessionWhere($filters);
        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(DISTINCT session_id) FROM {$this->table} WHERE {$whereSql}";
        $total = (int)$this->wpdb->get_var($this->prepared($countSql, $params));
        $pages = max(1, (int)ceil($total / $filters['per_page']));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * $filters['per_page'];
        $direction = $filters['order'] === 'asc' ? 'ASC' : 'DESC';

        $sql = "SELECT s.*,
                    (SELECT p.path FROM {$this->table} p
                     WHERE p.session_id=s.session_id AND p.event_type='pageview'
                     ORDER BY p.occurred_at ASC,p.event_id ASC LIMIT 1) landing_path,
                    (SELECT p.page_title FROM {$this->table} p
                     WHERE p.session_id=s.session_id AND p.event_type='pageview'
                     ORDER BY p.occurred_at ASC,p.event_id ASC LIMIT 1) landing_title,
                    (SELECT p.path FROM {$this->table} p
                     WHERE p.session_id=s.session_id AND p.event_type='pageview'
                     ORDER BY p.occurred_at DESC,p.event_id DESC LIMIT 1) exit_path,
                    (SELECT p.page_title FROM {$this->table} p
                     WHERE p.session_id=s.session_id AND p.event_type='pageview'
                     ORDER BY p.occurred_at DESC,p.event_id DESC LIMIT 1) exit_title,
                    (SELECT SUM(x.max_duration) FROM (
                        SELECT session_id,path,MAX(duration_ms) max_duration
                        FROM {$this->table} WHERE event_type='engagement'
                        GROUP BY session_id,path
                    ) x WHERE x.session_id=s.session_id) engaged_ms
                FROM (
                    SELECT session_id,MIN(occurred_at) started_at,MAX(occurred_at) ended_at,
                        COUNT(CASE WHEN event_type='pageview' THEN 1 END) pageviews,
                        MAX(visitor_id) visitor_id,MAX(referrer) referrer,
                        MAX(country_name) country_name,MAX(country_code) country_code,
                        MAX(region) region,MAX(asn) asn,MAX(asn_org) asn_org,
                        MAX(browser) browser,MAX(os) os,MAX(device_type) device_type,
                        MAX(user_agent) user_agent,MAX(is_bot) is_bot
                    FROM {$this->table}
                    WHERE {$whereSql}
                    GROUP BY session_id
                ) s
                ORDER BY s.started_at {$direction},s.session_id {$direction}
                LIMIT %d OFFSET %d";
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...[...$params, $filters['per_page'], $offset]),
            ARRAY_A
        ) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'first' => $total === 0 ? 0 : $offset + 1,
            'last' => min($offset + $filters['per_page'], $total),
        ];
    }

    /** @return array<string,mixed>|null */
    public function getSession(string $sessionId): ?array
    {
        $events = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT event_id,occurred_at,event_type,visitor_id,session_id,country_code,country_name,
                    region,city,asn,asn_org,path,page_title,referrer,target_url,target_host,
                    event_name,event_meta,traffic_channel,referrer_host,utm_source,utm_medium,
                    utm_campaign,utm_content,utm_term,user_agent,browser,os,device_type,
                    duration_ms,scroll_depth,is_bot
                 FROM {$this->table} WHERE session_id=%s AND session_id<>''
                 ORDER BY occurred_at ASC,event_id ASC",
                $sessionId
            ),
            ARRAY_A
        ) ?: [];
        if ($events === []) {
            return null;
        }

        return self::summarizeEvents($sessionId, $events);
    }

    /** @param array<int,array<string,mixed>> $events @return array<string,mixed>|null */
    public static function summarizeEvents(string $sessionId, array $events): ?array
    {
        if ($sessionId === '' || $events === []) {
            return null;
        }
        usort($events, static fn(array $a, array $b): int =>
            [(string)$a['occurred_at'], (int)$a['event_id']] <=> [(string)$b['occurred_at'], (int)$b['event_id']]
        );
        $pageviews = array_values(array_filter($events, static fn(array $event): bool => $event['event_type'] === 'pageview'));
        $engaged = [];
        foreach ($events as $event) {
            if ($event['event_type'] === 'engagement') {
                $path = (string)$event['path'];
                $engaged[$path] = [
                    'duration_ms' => max((int)($engaged[$path]['duration_ms'] ?? 0), (int)$event['duration_ms']),
                    'scroll_depth' => max((int)($engaged[$path]['scroll_depth'] ?? 0), (int)$event['scroll_depth']),
                ];
            }
        }
        $first = $events[0];
        $last = $events[count($events) - 1];
        return [
            'session_id' => $sessionId,
            'visitor_id' => (string)$first['visitor_id'],
            'started_at' => (string)$first['occurred_at'],
            'ended_at' => (string)$last['occurred_at'],
            'span_seconds' => max(0, strtotime((string)$last['occurred_at']) - strtotime((string)$first['occurred_at'])),
            'engaged_ms' => array_sum(array_column($engaged, 'duration_ms')),
            'pageviews' => count($pageviews),
            'bounce' => count($pageviews) === 1,
            'landing' => $pageviews[0] ?? null,
            'exit' => $pageviews === [] ? null : $pageviews[count($pageviews) - 1],
            'environment' => $last,
            'engagement_by_path' => $engaged,
            'events' => $events,
        ];
    }

    /** @return array<string,mixed>|null */
    public function getVisitor(string $visitorId): ?array
    {
        $summary = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT MIN(occurred_at) first_seen,MAX(occurred_at) last_seen,
                    COUNT(DISTINCT NULLIF(session_id,'')) total_sessions,
                    COUNT(CASE WHEN event_type='pageview' THEN 1 END) total_pageviews
                 FROM {$this->table} WHERE visitor_id=%s AND visitor_id<>''",
                $visitorId
            ),
            ARRAY_A
        );
        if (!$summary || empty($summary['first_seen'])) {
            return null;
        }
        $recent = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT country_name,region,asn,asn_org,browser,os,device_type,is_bot
                 FROM {$this->table} WHERE visitor_id=%s
                 ORDER BY occurred_at DESC,event_id DESC LIMIT 1",
                $visitorId
            ),
            ARRAY_A
        ) ?: [];
        $sessions = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT session_id,MIN(occurred_at) started_at,MAX(occurred_at) ended_at,
                    COUNT(CASE WHEN event_type='pageview' THEN 1 END) pageviews
                 FROM {$this->table} WHERE visitor_id=%s AND session_id<>''
                 GROUP BY session_id ORDER BY started_at DESC,session_id DESC LIMIT 100",
                $visitorId
            ),
            ARRAY_A
        ) ?: [];
        $top = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT path,MAX(page_title) page_title,COUNT(*) pageviews
                 FROM {$this->table} WHERE visitor_id=%s AND event_type='pageview'
                 GROUP BY path ORDER BY pageviews DESC,path ASC LIMIT 5",
                $visitorId
            ),
            ARRAY_A
        ) ?: [];
        $landings = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT e.path,MAX(e.page_title) page_title,COUNT(*) sessions FROM {$this->table} e
                 WHERE e.visitor_id=%s AND e.session_id<>'' AND e.event_type='pageview'
                   AND e.event_id=(SELECT f.event_id FROM {$this->table} f
                       WHERE f.session_id=e.session_id AND f.event_type='pageview'
                       ORDER BY f.occurred_at ASC,f.event_id ASC LIMIT 1)
                 GROUP BY e.path ORDER BY sessions DESC,e.path ASC LIMIT 5",
                $visitorId
            ),
            ARRAY_A
        ) ?: [];
        $referrers = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT COALESCE(NULLIF(referrer,''),'Direct') referrer,COUNT(*) sessions
                 FROM {$this->table} e WHERE visitor_id=%s AND session_id<>'' AND event_type='pageview'
                   AND event_id=(SELECT f.event_id FROM {$this->table} f
                       WHERE f.session_id=e.session_id AND f.event_type='pageview'
                       ORDER BY f.occurred_at ASC,f.event_id ASC LIMIT 1)
                 GROUP BY referrer ORDER BY sessions DESC,referrer ASC LIMIT 5",
                $visitorId
            ),
            ARRAY_A
        ) ?: [];
        $engagedMs = (int)$this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(x.duration_ms),0) FROM (
                    SELECT visitor_id,session_id,path,MAX(duration_ms) duration_ms
                    FROM {$this->table} WHERE visitor_id=%s AND event_type='engagement'
                    GROUP BY visitor_id,session_id,path
                ) x",
                $visitorId
            )
        );
        return array_merge($summary, [
            'visitor_id' => $visitorId,
            'recent' => $recent,
            'sessions' => $sessions,
            'common_content' => $top,
            'common_landings' => $landings,
            'common_referrers' => $referrers,
            'engaged_ms' => $engagedMs,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function contentJourneyMetrics(string $startUtc, string $endUtc, string $actor): array
    {
        $actorSql = $actor === 'human' ? ' AND p.is_bot=0' : ($actor === 'bot' ? ' AND p.is_bot=1' : '');
        $sessionActorSql = $actor === 'human' ? ' AND is_bot=0' : ($actor === 'bot' ? ' AND is_bot=1' : '');
        // Bounce rate = single-PV entry sessions / entry sessions.
        // Exit rate = sessions exiting at the path / pageviews at the path.
        $sql = "SELECT p.path,MAX(p.page_title) page_title,COUNT(*) pageviews,
                    COUNT(DISTINCT p.session_id) sessions,
                    COUNT(DISTINCT CASE WHEN p.event_id=(
                        SELECT f.event_id FROM {$this->table} f
                        WHERE f.session_id=p.session_id AND f.event_type='pageview'
                        ORDER BY f.occurred_at ASC,f.event_id ASC LIMIT 1
                    ) THEN p.session_id END) entries,
                    COUNT(DISTINCT CASE WHEN p.event_id=(
                        SELECT l.event_id FROM {$this->table} l
                        WHERE l.session_id=p.session_id AND l.event_type='pageview'
                        ORDER BY l.occurred_at DESC,l.event_id DESC LIMIT 1
                    ) THEN p.session_id END) exits,
                    COUNT(DISTINCT CASE WHEN p.event_id=(
                        SELECT f2.event_id FROM {$this->table} f2
                        WHERE f2.session_id=p.session_id AND f2.event_type='pageview'
                        ORDER BY f2.occurred_at ASC,f2.event_id ASC LIMIT 1
                    ) AND 1=(SELECT COUNT(*) FROM {$this->table} b
                        WHERE b.session_id=p.session_id AND b.event_type='pageview'{$sessionActorSql})
                    THEN p.session_id END) bounces
                FROM {$this->table} p
                WHERE p.event_type='pageview' AND p.session_id<>''
                    AND p.occurred_at>=%s AND p.occurred_at<%s{$actorSql}
                GROUP BY p.path ORDER BY pageviews DESC,p.path ASC LIMIT 100";
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $startUtc, $endUtc), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $entries = (int)$row['entries'];
            $pageviews = (int)$row['pageviews'];
            $sessions = (int)$row['sessions'];
            $rates = self::calculateRates((int)$row['bounces'], $entries, (int)$row['exits'], $pageviews, $sessions);
            $row = array_merge($row, $rates);
        }
        unset($row);
        return $rows;
    }

    /** @return array{bounce_rate:float,exit_rate:float,pageviews_per_session:float} */
    public static function calculateRates(int $bounces, int $entries, int $exits, int $pageviews, int $sessions): array
    {
        return [
            'bounce_rate' => $entries > 0 ? $bounces / $entries * 100 : 0.0,
            'exit_rate' => $pageviews > 0 ? $exits / $pageviews * 100 : 0.0,
            'pageviews_per_session' => $sessions > 0 ? (float)($pageviews / $sessions) : 0.0,
        ];
    }

    /** @param array<string,mixed> $filters @return array{0:array<int,string>,1:array<int,mixed>} */
    private function sessionWhere(array $filters): array
    {
        $where = ["session_id<>''"];
        $params = [];
        if ($filters['actor'] === 'human') {
            $where[] = 'is_bot=0';
        } elseif ($filters['actor'] === 'bot') {
            $where[] = 'is_bot=1';
        }
        foreach (['country' => 'country_name', 'browser' => 'browser', 'os' => 'os', 'device' => 'device_type'] as $key => $column) {
            if ($filters[$key] !== '') {
                $where[] = "{$column}=%s";
                $params[] = $filters[$key];
            }
        }
        if ($filters['from_utc'] !== '') {
            $where[] = 'occurred_at>=%s';
            $params[] = $filters['from_utc'];
        }
        if ($filters['to_utc'] !== '') {
            $where[] = 'occurred_at<%s';
            $params[] = $filters['to_utc'];
        }
        if ($filters['query'] !== '') {
            $like = '%' . $this->wpdb->esc_like($filters['query']) . '%';
            $where[] = '(session_id LIKE %s OR visitor_id LIKE %s OR path LIKE %s OR page_title LIKE %s OR referrer LIKE %s OR asn_org LIKE %s OR browser LIKE %s OR os LIKE %s OR device_type LIKE %s)';
            array_push($params, ...array_fill(0, 9, $like));
        }
        return [$where, $params];
    }

    /** @param array<int,mixed> $params */
    private function prepared(string $sql, array $params): string
    {
        return $params === [] ? $sql : $this->wpdb->prepare($sql, ...$params);
    }
}
