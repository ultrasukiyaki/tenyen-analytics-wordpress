<?php

declare(strict_types=1);

use Tenyen\Analytics\BotDetector;
use Tenyen\Analytics\Crypto;
use Tenyen\Analytics\GeoIp;
use Tenyen\Analytics\IpResolver;
use Tenyen\Analytics\OrganizationClassifierV040;
use Tenyen\Analytics\Payload;
use Tenyen\Analytics\TrafficAttribution;
use Tenyen\Analytics\UserAgentParser;

if (!defined('ABSPATH')) {
    exit;
}

final class TYA_Plugin
{
    public const UI_BUILD = '0.7.0-lifecycle';
    private static ?self $instance = null;
    private ?TYA_Session_Admin $sessionAdmin = null;
    private ?TYA_Metadata $metadata = null;
    private ?TYA_Exclusions $exclusions = null;
    private ?TYA_Lifecycle $lifecycle = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        TYA_Installer::maybeUpgrade();
        $this->lifecycle()->boot();
        add_action('wp_enqueue_scripts', [$this, 'enqueueTracker']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_footer', [$this, 'renderAdminFooter']);
        add_filter('plugin_action_links_' . plugin_basename(TYA_FILE), [$this, 'actionLinks']);

        if (is_admin()) {
            TYA_Dashboard_Widget::bootstrap();
        }
    }

    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=tenyen-analytics')) . '">' . esc_html__('Analytics', 'tenyen-analytics') . '</a>');
        return $links;
    }



    public function enqueueAdminAssets(string $hook): void
    {
        if (!str_contains($hook, 'tenyen-analytics')) {
            return;
        }

        $page = sanitize_key((string)($_GET['page'] ?? 'tenyen-analytics'));
        wp_enqueue_style(
            'tenyen-analytics-admin-pages',
            TYA_URL . 'assets/admin-pages.css',
            [],
            TYA_VERSION
        );

        if (in_array($page, ['tenyen-analytics', 'tenyen-analytics-audience'], true)) {
            wp_enqueue_script(
                'tenyen-analytics-admin-charts',
                TYA_URL . 'assets/admin-charts.js',
                ['wp-i18n'],
                TYA_VERSION,
                true
            );
            wp_set_script_translations(
                'tenyen-analytics-admin-charts',
                'tenyen-analytics',
                TYA_DIR . 'languages'
            );
        }

        if ($page === 'tenyen-analytics-history') {
            wp_enqueue_style(
                'tenyen-analytics-admin-history',
                TYA_URL . 'assets/admin-history.css',
                [],
                TYA_VERSION
            );
            wp_enqueue_script(
                'tenyen-analytics-admin-history',
                TYA_URL . 'assets/admin-history.js',
                ['wp-i18n'],
                TYA_VERSION,
                true
            );
            wp_set_script_translations(
                'tenyen-analytics-admin-history',
                'tenyen-analytics',
                TYA_DIR . 'languages'
            );
            $historyConfig = [
                'endpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/events')),
                'nonce' => wp_create_nonce('wp_rest'),
                'storageKey' => 'tenyenAnalytics.history.v1',
                'defaults' => [
                    'collapsed' => false,
                    'density' => 'compact',
                    'perPage' => 25,
                    'actor' => 'human',
                    'event' => 'all',
                    'autoRefresh' => 0,
                    'wrap' => false,
                    'stickyHeader' => true,
                    'order' => 'desc',
                    'visibleColumns' => ['datetime', 'event', 'organization', 'page', 'referrer', 'environment', 'details'],
                ],
                'options' => $this->historyFilterOptions(),
            ];
            wp_add_inline_script(
                'tenyen-analytics-admin-history',
                'window.TYHistoryConfig=' . wp_json_encode($historyConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
                'before'
            );
            wp_add_inline_script(
                'tenyen-analytics-admin-history',
                'document.addEventListener("DOMContentLoaded",function(){window.TYHistory&&window.TYHistory.init(document,window.TYHistoryConfig||{});});',
                'after'
            );
        }
        if ($page === 'tenyen-analytics-sessions') {
            $this->sessionAdmin()->enqueue();
        }
        if ($page === 'tenyen-analytics-knowledge') {
            wp_enqueue_script('tenyen-analytics-metadata', TYA_URL . 'assets/admin-metadata.js', ['wp-i18n'], TYA_VERSION, true);
            wp_set_script_translations('tenyen-analytics-metadata', 'tenyen-analytics', TYA_DIR . 'languages');
            wp_add_inline_script('tenyen-analytics-metadata', 'window.TYAMetadata=' . wp_json_encode([
                'annotations' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/annotations')),
                'tags' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/tags')),
                'views' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/saved-views')),
                'nonce' => wp_create_nonce('wp_rest'),
            ], JSON_UNESCAPED_SLASHES) . ';', 'before');
        }
        if ($page === 'tenyen-analytics-exclusions') {
            wp_enqueue_script('tenyen-analytics-exclusions', TYA_URL . 'assets/admin-exclusions.js', ['wp-i18n'], TYA_VERSION, true);
            wp_set_script_translations('tenyen-analytics-exclusions', 'tenyen-analytics', TYA_DIR . 'languages');
            wp_add_inline_script('tenyen-analytics-exclusions', 'window.TYAExclusions=' . wp_json_encode([
                'endpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/exclusions')),
                'diagnose' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/exclusions/diagnose')),
                'nonce' => wp_create_nonce('wp_rest'),
                'types' => \Tenyen\Analytics\ExclusionRuleEngine::TYPES,
                'analysisTypes' => \Tenyen\Analytics\ExclusionRuleEngine::ANALYSIS_TYPES,
            ], JSON_UNESCAPED_SLASHES) . ';', 'before');
        }
        if ($page === 'tenyen-analytics-lifecycle') {
            wp_enqueue_script('tenyen-analytics-lifecycle', TYA_URL . 'assets/admin-lifecycle.js', ['wp-i18n'], TYA_VERSION, true);
            wp_set_script_translations('tenyen-analytics-lifecycle', 'tenyen-analytics', TYA_DIR . 'languages');
            wp_add_inline_script('tenyen-analytics-lifecycle', 'window.TYALifecycle=' . wp_json_encode([
                'diagnostics' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/lifecycle/diagnostics')),
                'retention' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/lifecycle/retention')),
                'preview' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/lifecycle/cleanup/preview')),
                'cleanup' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/lifecycle/cleanup/run')),
                'nonce' => wp_create_nonce('wp_rest'),
            ], JSON_UNESCAPED_SLASHES) . ';', 'before');
        }
    }

    public function enqueueTracker(): void
    {
        if ((bool)get_option('tya_exclude_admins', 1) && is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_script(
            'tenyen-analytics',
            TYA_URL . 'assets/tracker.js',
            [],
            TYA_VERSION,
            true
        );
        wp_script_add_data('tenyen-analytics', 'defer', true);

        $config = [
            'endpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/collect')),
            'token' => (string)get_option('tya_site_token', ''),
            'notFound' => is_404(),
            'trackInternalLinks' => (bool)get_option('tya_track_internal_links', 0),
            'trackButtons' => (bool)get_option('tya_track_buttons', 0),
            'trackForms' => (bool)get_option('tya_track_forms', 0),
        ];
        wp_add_inline_script(
            'tenyen-analytics',
            'window.TYAnalyticsConfig=' . wp_json_encode($config, JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );
    }

    public function registerRoutes(): void
    {
        $this->metadata()->registerRoutes();
        $this->exclusions()->registerRoutes();
        $this->lifecycle()->registerRoutes();
        register_rest_route('tenyen-analytics/v1', '/collect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'collect'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/events', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'historyEvents'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'enum' => [25, 50, 100]],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/dashboard-widget', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'dashboardWidget'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'args' => [
                'fresh' => ['type' => 'boolean'],
            ],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/sessions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->sessionAdmin(), 'listRest'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'enum' => [25, 50, 100]],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'actor' => ['type' => 'string', 'enum' => ['all', 'human', 'bot']],
            ],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/sessions/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->sessionAdmin(), 'sessionRest'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/visitors/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->sessionAdmin(), 'visitorRest'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        ]);
    }

    public function collect(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $expectedToken = (string)get_option('tya_site_token', '');
        $providedToken = isset($input['token']) ? (string)$input['token'] : '';
        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_token'], 403);
        }

        if (!$this->isSameSiteRequest($request)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'cross_site'], 403);
        }

        $ip = IpResolver::resolve($_SERVER, (string)get_option('tya_proxy_header', ''));
        $crypto = $this->crypto();
        $ipHash = $ip !== '' ? $crypto->hashIp($ip) : null;
        if ($ipHash !== null && !$this->allowRequest($ipHash)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }

        $payload = Payload::normalize($input);
        if ($payload['event'] === 'custom' && $payload['event_name'] === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_event_name'], 400);
        }
        $attribution = TrafficAttribution::fromPage($payload['path'], $payload['referrer'], home_url('/'));
        if ($payload['session_id'] !== '') {
            $firstTouch = $this->firstTouchAttribution($payload['session_id']);
            if ($firstTouch !== null) {
                $attribution = $firstTouch;
            }
        }
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024);
        $isBot = BotDetector::isBot($ua);
        $geo = (new GeoIp(
            (string)get_option('tya_city_db', ''),
            (string)get_option('tya_asn_db', '')
        ))->lookup($ip);
        $agent = UserAgentParser::parse($ua);
        $classification = OrganizationClassifierV040::classify(
            $geo['asn'] !== null ? (int)$geo['asn'] : null,
            (string)$geo['asn_org'],
            $isBot,
            $this->organizationOverrides()
        );
        $diagnostic = $this->exclusions()->collectionDiagnostic([
            'ip' => $ip, 'path' => $payload['path'],
            'administrator' => is_user_logged_in() && current_user_can('manage_options'),
            'bot' => $isBot, 'country' => $geo['country_code'], 'region' => $geo['region'],
            'asn' => $geo['asn'], 'organization' => $geo['asn_org'], 'category' => $classification['category'],
            'browser' => $agent['browser'], 'os' => $agent['os'], 'device' => $agent['device'],
            'referrer_domain' => $attribution['referrer_host'], 'utm_source' => $attribution['utm_source'],
            'utm_medium' => $attribution['utm_medium'], 'utm_campaign' => $attribution['utm_campaign'],
        ]);
        if ($diagnostic['excluded']) {
            $response = new WP_REST_Response(['ok' => true, 'excluded' => true], 202);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            TYA_Installer::tableName(),
            [
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'event_type' => $payload['event'],
                'visitor_id' => $payload['visitor_id'],
                'session_id' => $payload['session_id'],
                'ip_encrypted' => $ip !== '' ? $crypto->encryptIp($ip) : null,
                'ip_hash' => $ipHash,
                'ip_version' => IpResolver::version($ip),
                'country_code' => $geo['country_code'],
                'country_name' => $geo['country_name'],
                'region' => $geo['region'],
                'city' => $geo['city'],
                'latitude' => $geo['latitude'],
                'longitude' => $geo['longitude'],
                'accuracy_radius' => $geo['accuracy_radius'],
                'asn' => $geo['asn'],
                'asn_org' => $geo['asn_org'],
                'path' => $payload['path'],
                'page_title' => $payload['title'],
                'referrer' => $payload['referrer'],
                'target_url' => $payload['target_url'],
                'target_host' => TrafficAttribution::host($payload['target_url']),
                'event_name' => $payload['event_name'],
                'event_meta' => $payload['event_meta'] === [] ? null : wp_json_encode($payload['event_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'traffic_channel' => $attribution['traffic_channel'],
                'referrer_host' => $attribution['referrer_host'],
                'utm_source' => $attribution['utm_source'],
                'utm_medium' => $attribution['utm_medium'],
                'utm_campaign' => $attribution['utm_campaign'],
                'utm_content' => $attribution['utm_content'],
                'utm_term' => $attribution['utm_term'],
                'user_agent' => $ua,
                'browser' => $agent['browser'],
                'os' => $agent['os'],
                'device_type' => $agent['device'],
                'language' => $payload['language'],
                'timezone' => $payload['timezone'],
                'screen' => $payload['screen'],
                'viewport' => $payload['viewport'],
                'duration_ms' => $payload['duration_ms'],
                'scroll_depth' => $payload['scroll_depth'],
                'is_bot' => $isBot ? 1 : 0,
            ]
        );

        $response = new WP_REST_Response(['ok' => $inserted !== false], $inserted !== false ? 201 : 500);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /** @return array<string,string>|null */
    private function firstTouchAttribution(string $sessionId): ?array
    {
        global $wpdb;
        $table = TYA_Installer::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT traffic_channel,referrer_host,utm_source,utm_medium,utm_campaign,utm_content,utm_term
             FROM {$table} WHERE session_id=%s AND event_type='pageview'
             ORDER BY occurred_at ASC,event_id ASC LIMIT 1",
            $sessionId
        ), ARRAY_A);
        return is_array($row) ? array_map('strval', $row) : null;
    }

    private function isSameSiteRequest(WP_REST_Request $request): bool
    {
        $homeHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        foreach (['origin', 'referer'] as $header) {
            $value = $request->get_header($header);
            if ($value !== '') {
                $host = strtolower((string)wp_parse_url($value, PHP_URL_HOST));
                return $host !== '' && hash_equals($homeHost, $host);
            }
        }
        return true;
    }

    private function allowRequest(string $binaryHash): bool
    {
        $key = 'tya_rate_' . substr(bin2hex($binaryHash), 0, 32);
        $count = (int)get_transient($key);
        if ($count >= 120) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    private function crypto(): Crypto
    {
        $encryption = defined('AUTH_KEY') ? (string)AUTH_KEY : wp_salt('auth');
        $hash = defined('SECURE_AUTH_SALT') ? (string)SECURE_AUTH_SALT : wp_salt('secure_auth');
        return new Crypto($encryption . '|tenyen-ip', $hash . '|tenyen-hmac');
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            'Tenyen Analytics',
            __('Tenyen Analytics', 'tenyen-analytics'),
            'manage_options',
            'tenyen-analytics',
            [$this, 'renderDashboard'],
            'dashicons-chart-area',
            80
        );
        $items = [
            ['tenyen-analytics', __('Dashboard', 'tenyen-analytics'), 'renderDashboard'],
            ['tenyen-analytics-realtime', __('Realtime', 'tenyen-analytics'), 'renderRealtime'],
            ['tenyen-analytics-history', __('Access History', 'tenyen-analytics'), 'renderHistory'],
            ['tenyen-analytics-sessions', __('Sessions', 'tenyen-analytics'), 'renderSessions'],
            ['tenyen-analytics-content', __('Content', 'tenyen-analytics'), 'renderContent'],
            ['tenyen-analytics-referrers', __('Referrers', 'tenyen-analytics'), 'renderReferrers'],
            ['tenyen-analytics-organizations', __('ASN / Organizations', 'tenyen-analytics'), 'renderOrganizations'],
            ['tenyen-analytics-knowledge', __('Knowledge', 'tenyen-analytics'), 'renderKnowledge'],
            ['tenyen-analytics-exclusions', __('Exclusions', 'tenyen-analytics'), 'renderExclusions'],
            ['tenyen-analytics-lifecycle', __('Data lifecycle', 'tenyen-analytics'), 'renderLifecycle'],
            ['tenyen-analytics-audience', __('Audience', 'tenyen-analytics'), 'renderAudience'],
            ['tenyen-analytics-engagement', __('Engagement', 'tenyen-analytics'), 'renderEngagement'],
            ['tenyen-analytics-system', __('System', 'tenyen-analytics'), 'renderSystem'],
            ['tenyen-analytics-settings', __('Settings', 'tenyen-analytics'), 'renderSettings'],
        ];
        foreach ($items as [$slug, $label, $callback]) {
            add_submenu_page(
                'tenyen-analytics',
                'Tenyen Analytics ' . $label,
                $label,
                'manage_options',
                $slug,
                [$this, $callback]
            );
        }
    }

    public function renderSessions(): void
    {
        $this->sessionAdmin()->renderPage();
    }

    public function renderKnowledge(): void
    {
        $this->metadata()->renderManager();
    }

    public function renderExclusions(): void
    {
        $this->exclusions()->renderManager();
    }

    public function renderLifecycle(): void
    {
        $this->lifecycle()->renderPage();
    }

    public function registerSettings(): void
    {
        register_setting('tya_settings', 'tya_retention_days', [
            'type' => 'integer', 'sanitize_callback' => [TYA_Lifecycle::class, 'sanitizeRetention'], 'default' => 90,
        ]);
        register_setting('tya_settings', 'tya_exclude_admins', [
            'type' => 'boolean', 'sanitize_callback' => static fn($v) => $v ? 1 : 0, 'default' => 1,
        ]);
        register_setting('tya_settings', 'tya_log_bots', [
            'type' => 'boolean', 'sanitize_callback' => static fn($v) => $v ? 1 : 0, 'default' => 1,
        ]);
        register_setting('tya_settings', 'tya_proxy_header', [
            'type' => 'string',
            'sanitize_callback' => static function ($v): string {
                $allowed = ['', 'cf-connecting-ip', 'x-real-ip', 'x-forwarded-for'];
                return in_array($v, $allowed, true) ? $v : '';
            },
            'default' => '',
        ]);
        register_setting('tya_settings', 'tya_city_db', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('tya_settings', 'tya_asn_db', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('tya_settings', 'tya_org_overrides', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '',
        ]);
        foreach (['tya_track_internal_links', 'tya_track_buttons', 'tya_track_forms'] as $option) {
            register_setting('tya_settings', $option, [
                'type' => 'boolean', 'sanitize_callback' => static fn($v) => $v ? 1 : 0, 'default' => 0,
            ]);
        }
    }

    public function renderDashboard(): void
    {
        $this->ensureAdmin();
        global $wpdb;
        $table = TYA_Installer::tableName();
        $analysis = $this->analysisFilters();
        $actorSql = $this->actorSql($analysis['actor']);
        $analysisOnly = $this->analysisWhere('');
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(event_type='pageview') pageviews,
                    COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(visitor_id,'') END) visitors,
                    COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(session_id,'') END) sessions,
                    AVG(CASE WHEN event_type='engagement' AND duration_ms>0 THEN duration_ms END) avg_duration_ms,
                    AVG(CASE WHEN event_type='engagement' THEN scroll_depth END) avg_scroll
             FROM {$table} WHERE occurred_at>=%s AND occurred_at<%s{$actorSql}",
            $analysis['start_utc'], $analysis['end_utc']
        ), ARRAY_A) ?: [];
        $summary['bot_events'] = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE occurred_at>=%s AND occurred_at<%s AND is_bot=1{$analysisOnly}",
            $analysis['start_utc'], $analysis['end_utc']
        ));

        $offsetMinutes = intdiv($analysis['start_local']->getOffset(), 60);
        $grain = $analysis['days'] <= 2 ? 'hour' : ($analysis['days'] <= 62 ? 'day' : 'month');
        $local = "DATE_ADD(occurred_at, INTERVAL {$offsetMinutes} MINUTE)";
        $bucket = $grain === 'hour'
            ? "CONCAT(DATE({$local}),' ',LPAD(HOUR({$local}),2,'0'),':00')"
            : ($grain === 'day' ? "DATE({$local})" : "CONCAT(YEAR({$local}),'-',LPAD(MONTH({$local}),2,'0'),'-01')");
        $timelineDb = $wpdb->get_results($wpdb->prepare(
            "SELECT {$bucket} bucket,COUNT(*) pageviews,COUNT(DISTINCT NULLIF(visitor_id,'')) visitors,COUNT(DISTINCT NULLIF(session_id,'')) sessions
             FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql}
             GROUP BY {$bucket} ORDER BY {$bucket}",
            $analysis['start_utc'], $analysis['end_utc']
        ), ARRAY_A) ?: [];
        $timeline = $this->fillTimeline($timelineDb, $analysis['start_local'], $analysis['end_local'], $grain);
        $topPages = $wpdb->get_results($wpdb->prepare(
            "SELECT path,MAX(page_title) page_title,COUNT(*) pageviews,COUNT(DISTINCT session_id) sessions
             FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql}
             GROUP BY path ORDER BY pageviews DESC,sessions DESC LIMIT 5",
            $analysis['start_utc'], $analysis['end_utc']
        ), ARRAY_A) ?: [];
        $recent = $wpdb->get_results("SELECT * FROM {$table} WHERE event_type='pageview' AND is_bot=0{$analysisOnly} ORDER BY event_id DESC LIMIT 8", ARRAY_A) ?: [];
        $candidates = $wpdb->get_results("SELECT * FROM {$table} WHERE event_type='pageview' AND is_bot=0 AND asn_org<>''{$analysisOnly} ORDER BY event_id DESC LIMIT 120", ARRAY_A) ?: [];
        $notable = [];
        foreach ($candidates as $row) {
            $classification = OrganizationClassifierV040::classify($row['asn'] !== null ? (int)$row['asn'] : null, (string)$row['asn_org'], false, $this->organizationOverrides());
            if (!$classification['featured']) continue;
            $key = (string)$row['asn'] . '|' . (string)$row['path'];
            if (isset($notable[$key])) continue;
            $row['_classification'] = $classification;
            $notable[$key] = $row;
            if (count($notable) >= 5) break;
        }
        $payload = ['timeline' => ['rows' => $timeline, 'series' => [['key' => 'pageviews', 'label' => __('Pageviews', 'tenyen-analytics')], ['key' => 'visitors', 'label' => __('Visitors', 'tenyen-analytics')], ['key' => 'sessions', 'label' => __('Sessions', 'tenyen-analytics')]]], 'breakdowns' => []];

        $this->pageStart(__('Dashboard', 'tenyen-analytics'), __('A compact entry point for monitoring site-wide activity.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('Period analysis', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<div class="tya-cards">';
        foreach ([
            __('Pageviews', 'tenyen-analytics') => number_format((int)($summary['pageviews'] ?? 0)),
            __('Estimated visitors', 'tenyen-analytics') => number_format((int)($summary['visitors'] ?? 0)),
            __('Sessions', 'tenyen-analytics') => number_format((int)($summary['sessions'] ?? 0)),
            __('Average duration', 'tenyen-analytics') => $this->formatDuration((int)round((float)($summary['avg_duration_ms'] ?? 0))),
            __('Average scroll', 'tenyen-analytics') => number_format((float)($summary['avg_scroll'] ?? 0), 1).'%',
            __('Bot events', 'tenyen-analytics') => number_format((int)$summary['bot_events']),
        ] as $label => $value) {
            echo '<div class="tya-card">'.esc_html($label).'<b>'.esc_html($value).'</b></div>';
        }
        echo '</div><div class="tya-chart-card"><h3>'.esc_html__('Pageviews / Visitors / Sessions trend', 'tenyen-analytics').'</h3><canvas data-tya-line></canvas><div class="tya-chart-legend"><span>'.esc_html__('Pageviews', 'tenyen-analytics').'</span><span>'.esc_html__('Visitors', 'tenyen-analytics').'</span><span>'.esc_html__('Sessions', 'tenyen-analytics').'</span></div></div></section>';
        echo '<div class="tya-insight-grid"><section class="tya-panel"><h2>'.esc_html__('Notable organization activity', 'tenyen-analytics').'</h2>';
        if ($notable === []) echo '<p class="description">'.esc_html__('No notable organization activity yet.', 'tenyen-analytics').'</p>';
        foreach (array_values($notable) as $row) {
            $time = get_date_from_gmt((string)$row['occurred_at'], 'm-d H:i');
            $asn = trim(($row['asn'] ? 'AS'.(int)$row['asn'].' ' : '').(string)$row['asn_org']);
            echo '<div class="tya-notable">'.$this->organizationBadge($row['_classification']).' <span class="description">'.esc_html($time).'</span><b>'.esc_html($asn).'</b>'.$this->pageLink((string)$row['path'], (string)$row['page_title']).'</div>';
        }
        echo '</section><section class="tya-panel"><h2>'.esc_html__('Popular pages', 'tenyen-analytics').'</h2><ol class="tya-rank">';
        foreach ($topPages as $row) echo '<li><b>'.$this->pageLink((string)$row['path'],(string)$row['page_title']).'</b><br><span class="description">'.number_format((int)$row['pageviews']).' '.esc_html__('pageviews', 'tenyen-analytics').' / '.number_format((int)$row['sessions']).' '.esc_html__('sessions', 'tenyen-analytics').'</span></li>';
        if ($topPages === []) echo '<li>'.esc_html__('No data', 'tenyen-analytics').'</li>';
        echo '</ol></section></div><section class="tya-panel"><h2>'.esc_html__('Recent views', 'tenyen-analytics').'</h2><div class="tya-table-wrap"><table><thead><tr><th>'.esc_html__('Date', 'tenyen-analytics').'</th><th>'.esc_html__('Organization', 'tenyen-analytics').'</th><th>'.esc_html__('Page', 'tenyen-analytics').'</th><th>'.esc_html__('Referrer', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            $classification = OrganizationClassifierV040::classify($row['asn'] !== null ? (int)$row['asn'] : null, (string)$row['asn_org'], false, $this->organizationOverrides());
            echo '<tr><td>'.esc_html(get_date_from_gmt((string)$row['occurred_at'],'m-d H:i:s')).'</td><td>'.$this->organizationBadge($classification).'<br>'.esc_html(trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org'])?:'―').'</td><td>'.$this->pageLink((string)$row['path'],(string)$row['page_title']).'</td><td>'.$this->referrerLink((string)$row['referrer']).'</td></tr>';
        }
        if ($recent === []) echo '<tr><td colspan="4">'.esc_html__('No data yet.', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section>';
        $this->chartInit($payload);
        $this->pageEnd();
    }

    public function renderRealtime(): void
    {
        $this->ensureAdmin();
        global $wpdb;
        $table = TYA_Installer::tableName();
        $minutes = max(5, min(180, (int)($_GET['minutes'] ?? 30)));
        $since = gmdate('Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,
                COALESCE((SELECT MAX(e.duration_ms) FROM {$table} e WHERE e.session_id=p.session_id AND e.path=p.path AND e.event_type='engagement' AND e.occurred_at>=p.occurred_at" . $this->analysisWhere('e') . "),0) live_duration,
                GREATEST(p.scroll_depth,COALESCE((SELECT MAX(e2.scroll_depth) FROM {$table} e2 WHERE e2.session_id=p.session_id AND e2.path=p.path AND e2.occurred_at>=p.occurred_at" . $this->analysisWhere('e2') . "),0)) live_scroll
             FROM {$table} p WHERE p.event_type='pageview' AND p.occurred_at>=%s" . $this->analysisWhere('p') . " ORDER BY p.event_id DESC LIMIT 100",
            $since
        ), ARRAY_A) ?: [];
        $this->pageStart(__('Realtime', 'tenyen-analytics'), __('This screen refreshes every 30 seconds with recent pageviews.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><form class="tya-filters" method="get"><input type="hidden" name="page" value="tenyen-analytics-realtime">';
        echo '<label>' . esc_html__('Interval', 'tenyen-analytics') . '<select name="minutes">';
        foreach ([5,15,30,60,180] as $value) {
            echo '<option value="'.$value.'"'.selected($minutes,$value,false).'>'.sprintf(esc_html__('%d minutes', 'tenyen-analytics'), $value).'</option>';
        }
        echo '</select></label><button class="button button-primary">' . esc_html__('Apply', 'tenyen-analytics') . '</button></form><p class="description">'.number_format(count($rows)).' '.esc_html__('pageviews', 'tenyen-analytics').'</p><div class="tya-table-wrap"><table><thead><tr><th>'.esc_html__('Date', 'tenyen-analytics').'</th><th>'.esc_html__('Status', 'tenyen-analytics').'</th><th>'.esc_html__('Page', 'tenyen-analytics').'</th><th>'.esc_html__('ASN / organization', 'tenyen-analytics').'</th><th>'.esc_html__('Referrer', 'tenyen-analytics').'</th><th>'.esc_html__('Duration / scroll', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $classification = OrganizationClassifierV040::classify($row['asn'] !== null ? (int)$row['asn'] : null, (string)$row['asn_org'], (bool)$row['is_bot'], $this->organizationOverrides());
            $state = (int)$row['is_bot'] ? 'Bot' : ((int)$row['live_duration'] > 0 ? __('Viewed & measured', 'tenyen-analytics') : __('New view', 'tenyen-analytics'));
            echo '<tr><td>'.esc_html(get_date_from_gmt((string)$row['occurred_at'],'H:i:s')).'</td><td><span class="tya-state">'.esc_html($state).'</span></td><td>'.$this->pageLink((string)$row['path'],(string)$row['page_title']).'<br><code>'.esc_html((string)$row['path']).'</code></td><td>'.$this->organizationBadge($classification).'<br>'.esc_html(trim(($row['asn']?'AS'.(int)$row['asn'].' ':'').$row['asn_org'])?:'―').'</td><td>'.$this->referrerLink((string)$row['referrer']).'</td><td>'.esc_html($this->formatDuration((int)$row['live_duration'])).' / '.(int)$row['live_scroll'].'%</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="6">'.esc_html__('No activity in this timeframe yet.', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section><script>setTimeout(function(){location.reload();},30000);</script>';
        $this->pageEnd();
    }

    public function renderHistory(): void
    {
        $this->ensureAdmin();
        $this->pageStart(__('History', 'tenyen-analytics'), __('Browse raw events with asynchronous search, filters, and paging.', 'tenyen-analytics'));
        echo $this->renderHistoryShell();
        $this->pageEnd();
    }

    public function renderContent(): void
    {
        $this->ensureAdmin();
        global $wpdb;
        $table=TYA_Installer::tableName();$analysis=$this->analysisFilters();
        $rows=(new TYA_Session_Repository($wpdb,$table))->contentJourneyMetrics($analysis['start_utc'],$analysis['end_utc'],$analysis['actor']);
        $this->pageStart(__('Content', 'tenyen-analytics'), __('View page performance for posts and pages.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('Top content', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<p class="description">'.esc_html__('Bounce rate is single-page entry sessions divided by entry sessions. Exit rate is exit sessions divided by pageviews.', 'tenyen-analytics').'</p>';
        echo '<div class="tya-table-wrap"><table><thead><tr><th>#</th><th>'.esc_html__('Page', 'tenyen-analytics').'</th><th>'.esc_html__('Pageviews', 'tenyen-analytics').'</th><th>'.esc_html__('Sessions', 'tenyen-analytics').'</th><th>'.esc_html__('Entries', 'tenyen-analytics').'</th><th>'.esc_html__('Exits', 'tenyen-analytics').'</th><th>'.esc_html__('Bounces', 'tenyen-analytics').'</th><th>'.esc_html__('Bounce rate (estimated)', 'tenyen-analytics').'</th><th>'.esc_html__('Exit rate', 'tenyen-analytics').'</th><th>'.esc_html__('Pageviews per session', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            echo '<tr><td>'.($i + 1).'</td><td><b>'.$this->pageLink((string)$row['path'], (string)$row['page_title']).'</b><br><code>'.esc_html((string)$row['path']).'</code></td><td>'.number_format((int)$row['pageviews']).'</td><td>'.number_format((int)$row['sessions']).'</td><td>'.number_format((int)$row['entries']).'</td><td>'.number_format((int)$row['exits']).'</td><td>'.number_format((int)$row['bounces']).'</td><td>'.number_format((float)$row['bounce_rate'],1).'%</td><td>'.number_format((float)$row['exit_rate'],1).'%</td><td>'.number_format((float)$row['pageviews_per_session'],2).'</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="10">'.esc_html__('No data', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section>';
        $this->pageEnd();
    }

    public function renderReferrers(): void
    {
        $this->ensureAdmin();
        global $wpdb;$table=TYA_Installer::tableName();$analysis=$this->analysisFilters();$actorSql=$this->actorSql($analysis['actor']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT CASE WHEN referrer='' THEN 'Direct' ELSE COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer,'/',3),'/',-1),''),'Unknown') END referrer_host,MAX(referrer) sample_url,COUNT(*) pageviews,COUNT(DISTINCT session_id) sessions FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql} GROUP BY referrer_host ORDER BY pageviews DESC LIMIT 100",$analysis['start_utc'],$analysis['end_utc']),ARRAY_A)?:[];
        $clicks = $wpdb->get_results($wpdb->prepare("SELECT event_type,target_url,COUNT(*) events FROM {$table} WHERE event_type IN('outbound','download','internal_link_click') AND occurred_at>=%s AND occurred_at<%s{$actorSql} GROUP BY event_type,target_url ORDER BY events DESC LIMIT 50",$analysis['start_utc'],$analysis['end_utc']),ARRAY_A)?:[];
        $this->pageStart(__('Referrers', 'tenyen-analytics'), __('Check direct, search, external sources, and outbound clicks.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('Referrer domains', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<div class="tya-table-wrap"><table><thead><tr><th>#</th><th>'.esc_html__('Referrer', 'tenyen-analytics').'</th><th>'.esc_html__('Pageviews', 'tenyen-analytics').'</th><th>'.esc_html__('Sessions', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $render = $row['referrer_host'] === 'Direct' ? 'Direct' : $this->absoluteLink((string)$row['sample_url'], (string)$row['referrer_host']);
            echo '<tr><td>'.($i + 1).'</td><td>'.$render.'</td><td>'.number_format((int)$row['pageviews']).'</td><td>'.number_format((int)$row['sessions']).'</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="4">'.esc_html__('No data', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section><section class="tya-panel"><h2>'.esc_html__('External clicks & downloads', 'tenyen-analytics').'</h2><div class="tya-table-wrap"><table><thead><tr><th>'.esc_html__('Type', 'tenyen-analytics').'</th><th>'.esc_html__('Target URL', 'tenyen-analytics').'</th><th>'.esc_html__('Count', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($clicks as $row) {
            echo '<tr><td>'.esc_html((string)$row['event_type']).'</td><td>'.$this->absoluteLink((string)$row['target_url'], (string)$row['target_url']).'</td><td>'.number_format((int)$row['events']).'</td></tr>';
        }
        if ($clicks === []) echo '<tr><td colspan="3">'.esc_html__('No data', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section>';
        $this->pageEnd();
    }

    public function renderOrganizations(): void
    {
        $this->ensureAdmin();
        global $wpdb;$table=TYA_Installer::tableName();$analysis=$this->analysisFilters();$actorSql=$this->actorSql($analysis['actor']);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT asn,asn_org,COUNT(*) pageviews,COUNT(DISTINCT visitor_id) visitors,COUNT(DISTINCT session_id) sessions,MAX(occurred_at) last_seen FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql} GROUP BY asn,asn_org ORDER BY pageviews DESC LIMIT 200",$analysis['start_utc'],$analysis['end_utc']),ARRAY_A)?:[];
        $this->pageStart(__('ASN & organizations', 'tenyen-analytics'), __('Classify network organizations registered to incoming IPs.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('ASN & organization ranking', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<p class="description">'.esc_html__('Displayed organizations are not a definitive identification of individual visitors.', 'tenyen-analytics').'</p><div class="tya-table-wrap"><table><thead><tr><th>#</th><th>'.esc_html__('Classification', 'tenyen-analytics').'</th><th>'.esc_html__('ASN / registered organization', 'tenyen-analytics').'</th><th>'.esc_html__('Pageviews', 'tenyen-analytics').'</th><th>'.esc_html__('Visitors', 'tenyen-analytics').'</th><th>'.esc_html__('Sessions', 'tenyen-analytics').'</th><th>'.esc_html__('Last seen', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $classification = OrganizationClassifierV040::classify($row['asn'] !== null ? (int)$row['asn'] : null, (string)$row['asn_org'], false, $this->organizationOverrides());
            $asn = trim(($row['asn'] ? 'AS'.(int)$row['asn'].' ' : '').$row['asn_org']) ?: __('Unknown', 'tenyen-analytics');
            echo '<tr><td>'.($i + 1).'</td><td>'.$this->organizationBadge($classification).'</td><td><b>'.esc_html($asn).'</b><br><span class="description">'.esc_html($classification['reason']).' / '.(int)$classification['confidence'].'%</span></td><td>'.number_format((int)$row['pageviews']).'</td><td>'.number_format((int)$row['visitors']).'</td><td>'.number_format((int)$row['sessions']).'</td><td>'.esc_html(get_date_from_gmt((string)$row['last_seen'],'Y-m-d H:i')).'</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="7">'.esc_html__('No data', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section>';
        $this->pageEnd();
    }

    public function renderAudience(): void
    {
        $this->ensureAdmin();
        global $wpdb;$table=TYA_Installer::tableName();$analysis=$this->analysisFilters();$actorSql=$this->actorSql($analysis['actor']);
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql}",$analysis['start_utc'],$analysis['end_utc']));$breakdowns=[];
        foreach ([
            'browser' => [__('Browser', 'tenyen-analytics'), 'browser'],
            'os' => [__('OS', 'tenyen-analytics'), 'os'],
            'device' => [__('Device', 'tenyen-analytics'), 'device_type'],
            'country' => [__('Country', 'tenyen-analytics'), 'country_name'],
        ] as $key => [$title, $column]) {
            $items = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF({$column},''),'Unknown') label,COUNT(*) value FROM {$table} WHERE event_type='pageview' AND occurred_at>=%s AND occurred_at<%s{$actorSql} GROUP BY {$column} ORDER BY value DESC LIMIT 8", $analysis['start_utc'], $analysis['end_utc']), ARRAY_A) ?: [];
            $breakdowns[$key] = ['title' => $title, 'rows' => $this->withOther($items, $total)];
        }
        $this->pageStart(__('Audience', 'tenyen-analytics'), __('Review browser, OS, device, and country composition.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('Audience environment', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<div class="tya-chart-grid">';
        foreach ($breakdowns as $key => $part) {
            echo '<div class="tya-chart-card"><h3>'.esc_html($part['title']).'</h3><canvas data-tya-donut="'.esc_attr($key).'"></canvas><ol class="tya-composition">';
            foreach ($part['rows'] as $row) {
                $percent = $total > 0 ? (int)$row['value'] / $total * 100 : 0;
                echo '<li><b>'.esc_html($row['label']).'</b> <small>'.number_format((int)$row['value']).' '.esc_html__('pageviews', 'tenyen-analytics').' / '.number_format($percent,1).'%</small></li>';
            }
            echo '</ol></div>';
        }
        echo '</div></section>';
        $this->chartInit(['timeline'=>[],'breakdowns'=>$breakdowns]);
        $this->pageEnd();
    }

    public function renderEngagement(): void
    {
        $this->ensureAdmin();
        global $wpdb;$table=TYA_Installer::tableName();$analysis=$this->analysisFilters();$actorSql=$this->actorSql($analysis['actor'], 'p');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT p.path,MAX(p.page_title) page_title,COUNT(*) pageviews,COALESCE(AVG((SELECT MAX(e.duration_ms) FROM {$table} e WHERE e.session_id=p.session_id AND e.path=p.path AND e.event_type='engagement' AND e.occurred_at>=p.occurred_at".$this->analysisWhere('e').")),0) avg_duration,COALESCE(AVG((SELECT MAX(e2.scroll_depth) FROM {$table} e2 WHERE e2.session_id=p.session_id AND e2.path=p.path AND e2.event_type='engagement' AND e2.occurred_at>=p.occurred_at".$this->analysisWhere('e2').")),0) avg_scroll FROM {$table} p WHERE p.event_type='pageview' AND p.occurred_at>=%s AND p.occurred_at<%s{$actorSql} GROUP BY p.path ORDER BY pageviews DESC LIMIT 100",$analysis['start_utc'],$analysis['end_utc']),ARRAY_A)?:[];
        $this->pageStart(__('Engagement', 'tenyen-analytics'), __('Monitor average visit time and scroll rate by page.', 'tenyen-analytics'));
        echo '<section class="tya-panel"><h2>'.esc_html__('Page engagement', 'tenyen-analytics').'</h2>';
        $this->analysisFilterForm($analysis);
        echo '<div class="tya-table-wrap"><table><thead><tr><th>#</th><th>'.esc_html__('Page', 'tenyen-analytics').'</th><th>'.esc_html__('Pageviews', 'tenyen-analytics').'</th><th>'.esc_html__('Average duration', 'tenyen-analytics').'</th><th>'.esc_html__('Average scroll', 'tenyen-analytics').'</th></tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            echo '<tr><td>'.($i + 1).'</td><td>'.$this->pageLink((string)$row['path'], (string)$row['page_title']).'<br><code>'.esc_html((string)$row['path']).'</code></td><td>'.number_format((int)$row['pageviews']).'</td><td>'.esc_html($this->formatDuration((int)round((float)$row['avg_duration']))).'</td><td>'.number_format((float)$row['avg_scroll'],1).'%</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="5">'.esc_html__('No data', 'tenyen-analytics').'</td></tr>';
        echo '</tbody></table></div></section>';
        $this->pageEnd();
    }

    public function renderSystem(): void
    {
        $this->ensureAdmin();
        global $wpdb;
        $table = TYA_Installer::tableName();
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $latest = (string)$wpdb->get_var("SELECT MAX(occurred_at) FROM {$table}");
        $city = (string)get_option('tya_city_db', '');
        $asn = (string)get_option('tya_asn_db', '');
        $geo = new GeoIp($city, $asn);

        $this->pageStart(
            __('System', 'tenyen-analytics'),
            __('Check collection, database, and GeoLite2 status in one place.', 'tenyen-analytics')
        );

        echo '<div class="tya-diagnostics">';
        foreach ([
            ['PHP', PHP_VERSION, true],
            ['WordPress', get_bloginfo('version'), true],
            [__('Saved events', 'tenyen-analytics'), number_format($count) . ' ' . esc_html__('items', 'tenyen-analytics'), true],
            [__('Last received', 'tenyen-analytics'), $latest !== '' ? get_date_from_gmt($latest, 'Y-m-d H:i:s') : __('Not received', 'tenyen-analytics'), $latest !== ''],
            [__('GeoIP Reader', 'tenyen-analytics'), $geo->isReaderAvailable() ? (class_exists('MaxMind\\Db\\Reader') ? __('Official Reader', 'tenyen-analytics') : __('Built-in Reader', 'tenyen-analytics')) : __('Unavailable', 'tenyen-analytics'), $geo->isReaderAvailable()],
            ['GeoLite2 City', is_readable($city) ? basename($city) : __('Unconfigured', 'tenyen-analytics'), is_readable($city)],
            ['GeoLite2 ASN', is_readable($asn) ? basename($asn) : __('Unconfigured', 'tenyen-analytics'), is_readable($asn)],
        ] as [$label, $detail, $ok]) {
            echo '<div class="tya-diagnostic ' . ($ok ? 'ok' : 'warn') . '"><span>' . ($ok ? '✅' : '⚠️') . '</span><div><b>' . esc_html($label) . '</b><small>' . esc_html($detail) . '</small></div></div>';
        }
        echo '</div>';

        echo '<section class="tya-panel"><h2>' . esc_html__('Collection endpoints', 'tenyen-analytics') . '</h2><dl class="tya-system-list">';
        echo '<dt>' . esc_html__('REST API', 'tenyen-analytics') . '</dt><dd><code>' . esc_html(rest_url('tenyen-analytics/v1/collect')) . '</code></dd>';
        echo '<dt>' . esc_html__('History API', 'tenyen-analytics') . '</dt><dd><code>' . esc_html(rest_url('tenyen-analytics/v1/admin/events')) . '</code></dd>';
        echo '<dt>' . esc_html__('DB table', 'tenyen-analytics') . '</dt><dd><code>' . esc_html($table) . '</code></dd>';
        echo '<dt>' . esc_html__('UI build', 'tenyen-analytics') . '</dt><dd>' . esc_html(self::UI_BUILD) . '</dd>';
        echo '</dl></section>';
        $this->pageEnd();
    }

    private function ensureAdmin(): void
    {
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission.', 'tenyen-analytics'));
    }

    private function actorSql(string $actor, string $alias = ''): string
    {
        $column = $alias !== '' ? $alias . '.is_bot' : 'is_bot';
        $actorSql = $actor === 'human' ? " AND {$column}=0" : ($actor === 'bot' ? " AND {$column}=1" : '');
        return $actorSql . $this->analysisWhere($alias);
    }

    public function analysisWhere(string $alias = ''): string
    {
        return $this->exclusions()->analysisWhere($alias, $this->crypto());
    }

    private function pageStart(string $title, string $description): void
    {
        echo '<div class="wrap tya-pages"><header class="tya-page-head"><div><h1>'.esc_html($title).' <small>v'.esc_html(TYA_VERSION).'</small></h1><p>'.esc_html($description).'</p></div><span class="tya-build">'.esc_html(self::UI_BUILD).'</span></header>';
    }

    private function pageEnd(): void
    {
        echo '</div>';
    }

    private function analysisFilterForm(array $analysis): void
    {
        $page = sanitize_key((string)($_GET['page'] ?? 'tenyen-analytics'));
        echo '<form class="tya-filters" method="get"><input type="hidden" name="page" value="'.esc_attr($page).'">';
        echo '<label>' . esc_html__('Period', 'tenyen-analytics') . '<select name="tya_period">';
        foreach (['today' => __('Today', 'tenyen-analytics'), 'yesterday' => __('Yesterday', 'tenyen-analytics'), '7d' => __('Last 7 days', 'tenyen-analytics'), '30d' => __('Last 30 days', 'tenyen-analytics'), 'month' => __('This month', 'tenyen-analytics'), 'custom' => __('Custom', 'tenyen-analytics')] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($analysis['period'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('Start date', 'tenyen-analytics') . '<input type="date" name="tya_chart_from" value="'.esc_attr($analysis['from']).'"></label>';
        echo '<label>' . esc_html__('End date', 'tenyen-analytics') . '<input type="date" name="tya_chart_to" value="'.esc_attr($analysis['to']).'"></label>';
        echo '<label>' . esc_html__('Actor', 'tenyen-analytics') . '<select name="tya_chart_actor">';
        foreach (['human' => __('Humans only', 'tenyen-analytics'), 'bot' => __('Bots only', 'tenyen-analytics'), 'all' => __('All', 'tenyen-analytics')] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($analysis['actor'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        echo '<button class="button button-primary">' . esc_html__('Apply', 'tenyen-analytics') . '</button></form>';
        echo '<p class="description">'.esc_html($analysis['label']).' '.esc_html__('Unique visitors are estimated by deduplicating visitor_id within the selected period.', 'tenyen-analytics').'</p>';
    }

    private function chartInit(array $payload): void
    {
        echo '<script>document.addEventListener("DOMContentLoaded",function(){window.TYCharts&&window.TYCharts.render(document,'.wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');});</script>';
    }

    /** @return array{countries:array<int,array{value:string,label:string}>,browsers:array<int,array{value:string,label:string}>,os:array<int,array{value:string,label:string}>,devices:array<int,array{value:string,label:string}>} */
    private function historyFilterOptions(): array
    {
        global $wpdb;
        $table = TYA_Installer::tableName();

        $excluded = $this->analysisWhere('');
        $load = static function (string $column, int $limit = 60) use ($wpdb, $table, $excluded): array {
            $allowed = ['country_name', 'browser', 'os', 'device_type'];
            if (!in_array($column, $allowed, true)) {
                return [];
            }
            $rows = $wpdb->get_results(
                "SELECT {$column} AS value, COUNT(*) AS hits
                 FROM {$table}
                 WHERE {$column} <> ''{$excluded}
                 GROUP BY {$column}
                 ORDER BY hits DESC, {$column} ASC
                 LIMIT " . (int)$limit,
                ARRAY_A
            ) ?: [];
            return array_map(static fn(array $row): array => [
                'value' => (string)$row['value'],
                'label' => (string)$row['value'],
            ], $rows);
        };

        return [
            'countries' => $load('country_name', 100),
            'browsers' => $load('browser'),
            'os' => $load('os'),
            'devices' => $load('device_type'),
        ];
    }

    public function historyEvents(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(['ok' => false, 'message' => __('You do not have permission.', 'tenyen-analytics')], 403);
        }

        $filters = $this->historyFilters($request->get_query_params());
        $result = $this->queryHistory($filters);
        $response = new WP_REST_Response([
            'ok' => true,
            'table_html' => $this->renderHistoryTable($result['rows']),
            'range_html' => $this->historyRangeHtml(
                $result['first'],
                $result['last'],
                $result['total'],
                $result['page'],
                $result['pages']
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'generated_at' => wp_date('H:i:s'),
        ], 200);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /**
     * @param array<string,mixed> $source
     * @return array{query:string,event:string,actor:string,date_from:string,date_to:string,country:string,browser:string,os:string,device:string,per_page:int,page:int,order:string}
     */
    private function historyFilters(array $source): array
    {
        $query = trim(sanitize_text_field((string)($source['q'] ?? '')));
        $query = function_exists('mb_substr') ? mb_substr($query, 0, 255) : substr($query, 0, 255);

        $event = sanitize_key((string)($source['event'] ?? 'all'));
        if (!in_array($event, ['all', 'pageview', 'engagement', 'outbound', 'download', 'internal_link_click', 'button_click', 'form_submit', 'not_found', 'custom'], true)) {
            $event = 'all';
        }
        $actor = sanitize_key((string)($source['actor'] ?? 'human'));
        if (!in_array($actor, ['all', 'human', 'bot'], true)) {
            $actor = 'human';
        }

        $dateFrom = $this->sanitizeDate((string)($source['from'] ?? ''));
        $dateTo = $this->sanitizeDate((string)($source['to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $cleanExact = static function (mixed $value): string {
            $value = trim(sanitize_text_field((string)$value));
            return function_exists('mb_substr') ? mb_substr($value, 0, 128) : substr($value, 0, 128);
        };

        $perPage = (int)($source['per_page'] ?? 25);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }
        $order = strtolower((string)($source['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            'query' => $query,
            'event' => $event,
            'actor' => $actor,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'country' => $cleanExact($source['country'] ?? ''),
            'browser' => $cleanExact($source['browser'] ?? ''),
            'os' => $cleanExact($source['os'] ?? ''),
            'device' => $cleanExact($source['device'] ?? ''),
            'event_name' => $cleanExact($source['event_name'] ?? ''),
            'source_page' => $cleanExact($source['source_page'] ?? ''),
            'target_host' => $cleanExact($source['target_host'] ?? ''),
            'utm_source' => $cleanExact($source['utm_source'] ?? ''),
            'utm_medium' => $cleanExact($source['utm_medium'] ?? ''),
            'utm_campaign' => $cleanExact($source['utm_campaign'] ?? ''),
            'organization' => $cleanExact($source['organization'] ?? ''),
            'per_page' => $perPage,
            'page' => max(1, (int)($source['page'] ?? 1)),
            'order' => $order,
        ];
    }

    /**
     * @param array{query:string,event:string,actor:string,date_from:string,date_to:string,country:string,browser:string,os:string,device:string,per_page:int,page:int,order:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,first:int,last:int}
     */
    private function queryHistory(array $filters): array
    {
        global $wpdb;
        $table = TYA_Installer::tableName();
        $crypto = $this->crypto();
        $where = ['1 = 1'];
        $params = [];

        if ($filters['event'] !== 'all') {
            $where[] = 'event_type = %s';
            $params[] = $filters['event'];
        }
        if ($filters['actor'] === 'human') {
            $where[] = 'is_bot = 0';
        } elseif ($filters['actor'] === 'bot') {
            $where[] = 'is_bot = 1';
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'occurred_at >= %s';
            $params[] = $this->localDateToUtc($filters['date_from'], false);
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'occurred_at < %s';
            $params[] = $this->localDateToUtc($filters['date_to'], true);
        }
        foreach ([
            'country' => 'country_name',
            'browser' => 'browser',
            'os' => 'os',
            'device' => 'device_type',
            'event_name' => 'event_name',
            'target_host' => 'target_host',
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
        ] as $filterName => $column) {
            if ($filters[$filterName] !== '') {
                $where[] = "{$column} = %s";
                $params[] = $filters[$filterName];
            }
        }
        foreach (['source_page' => 'path', 'organization' => 'asn_org'] as $filterName => $column) {
            if ($filters[$filterName] !== '') {
                $where[] = "{$column} LIKE %s";
                $params[] = '%' . $wpdb->esc_like($filters[$filterName]) . '%';
            }
        }

        if ($filters['query'] !== '') {
            $or = [];
            $keyword = $filters['query'];
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            foreach ([
                'event_type', 'visitor_id', 'session_id', 'country_code', 'country_name',
                'region', 'city', 'asn_org', 'path', 'page_title', 'referrer', 'target_url',
                'event_name', 'event_meta', 'traffic_channel', 'referrer_host', 'target_host',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                'user_agent', 'browser', 'os', 'device_type', 'language', 'timezone',
            ] as $column) {
                $or[] = "{$column} LIKE %s";
                $params[] = $like;
            }
            $or[] = 'CAST(asn AS CHAR) LIKE %s';
            $params[] = $like;
            if (filter_var($keyword, FILTER_VALIDATE_IP) !== false) {
                $or[] = 'ip_hash = %s';
                $params[] = $crypto->hashIp($keyword);
            }
            if (preg_match('/^AS\s*(\d{1,10})$/i', $keyword, $matches)) {
                $or[] = 'asn = %d';
                $params[] = (int)$matches[1];
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        $analysisWhere = $this->analysisWhere('');
        if ($analysisWhere !== '') $where[] = substr($analysisWhere, 5);

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
        if ($params !== []) {
            $countSql = $wpdb->prepare($countSql, ...$params);
        }
        $total = (int)$wpdb->get_var($countSql);
        $pages = max(1, (int)ceil($total / $filters['per_page']));
        $page = min($filters['page'], $pages);
        $offset = ($page - 1) * $filters['per_page'];
        $direction = $filters['order'] === 'asc' ? 'ASC' : 'DESC';
        $listSql = "SELECT * FROM {$table} {$whereSql} ORDER BY event_id {$direction} LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($listSql, ...[...$params, $filters['per_page'], $offset]),
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

    /** @param array<int,array<string,mixed>> $rows */
    private function renderHistoryTable(array $rows): string
    {
        $crypto = $this->crypto();
        $overrides = $this->organizationOverrides();
        ob_start();
        ?>
        <div class="tya-history-table-wrap">
            <table class="tya-history-table">
                <thead><tr>
                    <th data-col="datetime"><?= esc_html__('Date', 'tenyen-analytics') ?></th>
                    <th data-col="event"><?= esc_html__('Event type', 'tenyen-analytics') ?></th>
                    <th data-col="ip">IP</th>
                    <th data-col="location"><?= esc_html__('Location', 'tenyen-analytics') ?></th>
                    <th data-col="organization"><?= esc_html__('ASN / candidate organization', 'tenyen-analytics') ?></th>
                    <th data-col="page"><?= esc_html__('Page', 'tenyen-analytics') ?></th>
                    <th data-col="referrer"><?= esc_html__('Referrer', 'tenyen-analytics') ?></th>
                    <th data-col="environment"><?= esc_html__('Environment', 'tenyen-analytics') ?></th>
                    <th data-col="details"><?= esc_html__('Details', 'tenyen-analytics') ?></th>
                </tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><div class="tya-history-empty"><?= esc_html__('No matching access records found.', 'tenyen-analytics') ?></div></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row):
                    $ip = $crypto->decryptIp($row['ip_encrypted'] ?? '');
                    $localTime = get_date_from_gmt((string)$row['occurred_at'], 'Y-m-d H:i:s');
                    $location = implode(' / ', array_filter([$row['country_name'], $row['region'], $row['city']]));
                    $asn = $row['asn'] ? 'AS' . (int)$row['asn'] : '';
                    $org = trim((string)$row['asn_org']);
                    $classification = OrganizationClassifierV040::classify(
                        $row['asn'] !== null ? (int)$row['asn'] : null,
                        $org,
                        (bool)$row['is_bot'],
                        $overrides
                    );
                    $environment = trim((string)$row['browser'] . ' / ' . (string)$row['os'] . ' / ' . (string)$row['device_type'], ' /');
                    $asnText = trim($asn . ' ' . $org);
                ?>
                    <tr>
                        <td data-col="datetime" title="<?= esc_attr($localTime) ?>"><span class="tya-history-cell-primary"><?= esc_html($localTime) ?></span></td>
                        <td data-col="event"><span class="tya-history-cell-primary"><?= esc_html((string)$row['event_type']) ?></span><?php if ((int)$row['is_bot']): ?><span class="tya-history-cell-secondary">Bot</span><?php endif; ?></td>
                        <td data-col="ip" class="tya-history-ip" title="<?= esc_attr($ip ?: '―') ?>"><?= esc_html($ip ?: '―') ?></td>
                        <td data-col="location" title="<?= esc_attr($location ?: '―') ?>"><?= esc_html($location ?: '―') ?></td>
                        <td data-col="organization" class="tya-history-org" title="<?= esc_attr($asnText ?: '―') ?>"><?= $this->organizationBadge($classification) ?><span class="tya-history-cell-secondary"><?= esc_html($asnText ?: '―') ?></span></td>
                        <td data-col="page" class="tya-history-page" title="<?= esc_attr((string)$row['page_title']) ?>"><span class="tya-history-cell-primary"><?= $this->pageLink((string)$row['path'], (string)$row['page_title']) ?></span><span class="tya-history-cell-secondary"><code><?= esc_html((string)$row['path']) ?></code></span></td>
                        <td data-col="referrer" class="tya-history-referrer" title="<?= esc_attr((string)$row['referrer']) ?>"><?= $this->referrerLink((string)$row['referrer']) ?></td>
                        <td data-col="environment" class="tya-history-environment" title="<?= esc_attr($environment) ?>"><?= esc_html($environment ?: '―') ?></td>
                        <td data-col="details"><button type="button" class="button button-small" data-history-detail aria-expanded="false"><?= esc_html__('Details', 'tenyen-analytics') ?></button></td>
                    </tr>
                    <tr class="tya-history-detail-row" hidden>
                        <td colspan="9">
                            <div class="tya-history-detail-grid">
                                <dl><dt><?= esc_html__('Classification', 'tenyen-analytics') ?></dt><dd><?= esc_html($classification['reason'] . ' (' . $classification['confidence'] . '%)') ?></dd></dl>
                                <dl><dt>IP</dt><dd><code><?= esc_html($ip ?: '―') ?></code></dd></dl>
                                <dl><dt><?= esc_html__('Location', 'tenyen-analytics') ?></dt><dd><?= esc_html($location ?: '―') ?></dd></dl>
                                <dl><dt><?= esc_html__('ASN / organization', 'tenyen-analytics') ?></dt><dd><?= esc_html($asnText ?: '―') ?></dd></dl>
                                <dl><dt><?= esc_html__('Duration', 'tenyen-analytics') ?></dt><dd><?= esc_html($this->formatDuration((int)$row['duration_ms'])) ?></dd></dl>
                                <dl><dt><?= esc_html__('Scroll', 'tenyen-analytics') ?></dt><dd><?= esc_html((string)(int)$row['scroll_depth']) ?>%</dd></dl>
                                <dl><dt><?= esc_html__('Session', 'tenyen-analytics') ?></dt><dd><?php if ((string)$row['session_id'] !== ''): ?><a href="<?= esc_url(add_query_arg(['page' => 'tenyen-analytics-sessions', 'session' => (string)$row['session_id']], admin_url('admin.php'))) ?>"><code><?= esc_html((string)$row['session_id']) ?></code></a><?php else: ?>―<?php endif; ?></dd></dl>
                                <dl><dt><?= esc_html__('Visitor', 'tenyen-analytics') ?></dt><dd><?php if ((string)$row['visitor_id'] !== ''): ?><a href="<?= esc_url(add_query_arg(['page' => 'tenyen-analytics-sessions', 'visitor' => (string)$row['visitor_id']], admin_url('admin.php'))) ?>"><code><?= esc_html((string)$row['visitor_id']) ?></code></a><?php else: ?>―<?php endif; ?></dd></dl>
                                <dl><dt><?= esc_html__('Screen', 'tenyen-analytics') ?></dt><dd><?= esc_html(trim((string)$row['screen'] . ' / ' . (string)$row['viewport'], ' /') ?: '―') ?></dd></dl>
                                <dl><dt><?= esc_html__('User-Agent', 'tenyen-analytics') ?></dt><dd><?= esc_html((string)$row['user_agent']) ?></dd></dl>
                                <dl><dt><?= esc_html__('Full referrer', 'tenyen-analytics') ?></dt><dd><?= $row['referrer'] !== '' ? $this->absoluteLink((string)$row['referrer'], (string)$row['referrer']) : 'Direct' ?></dd></dl>
                                <?php if ((string)$row['target_url'] !== ''): ?><dl><dt><?= esc_html__('Target URL', 'tenyen-analytics') ?></dt><dd><?= $this->absoluteLink((string)$row['target_url'], (string)$row['target_url']) ?></dd></dl><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function historyRangeHtml(int $first, int $last, int $total, int $page, int $pages): string
    {
        return '<span class="tya-history-range-text">' .
            esc_html(number_format_i18n($first)) . '–' . esc_html(number_format_i18n($last)) . ' ' . esc_html__('of', 'tenyen-analytics') . ' ' . esc_html(number_format_i18n($total)) . ' ' . esc_html__('items', 'tenyen-analytics') . '</span>' .
            $this->historyPaginationHtml($page, $pages);
    }

    private function historyPaginationHtml(int $current, int $pages): string
    {
        if ($pages <= 1) {
            return '';
        }
        $numbers = [1, $pages];
        for ($number = max(1, $current - 2); $number <= min($pages, $current + 2); $number++) {
            $numbers[] = $number;
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        $html = '<nav class="tya-history-pagination" aria-label="' . esc_attr__('History paging', 'tenyen-analytics') . '">';
        if ($current > 1) {
            $html .= '<a href="#" data-history-page="' . ($current - 1) . '">' . esc_html__('‹ Previous', 'tenyen-analytics') . '</a>';
        }
        $previous = 0;
        foreach ($numbers as $number) {
            if ($previous !== 0 && $number > $previous + 1) {
                $html .= '<span class="ellipsis">…</span>';
            }
            $html .= $number === $current
                ? '<span class="current" aria-current="page">' . $number . '</span>'
                : '<a href="#" data-history-page="' . $number . '">' . $number . '</a>';
            $previous = $number;
        }
        if ($current < $pages) {
            $html .= '<a href="#" data-history-page="' . ($current + 1) . '">' . esc_html__('Next ›', 'tenyen-analytics') . '</a>';
        }
        return $html . '</nav>';
    }


    private function renderHistoryShell(): string
    {
        ob_start();
        ?>
        <section id="tya-history" class="tya-history">
            <header class="tya-history-header">
                <div>
                    <h2><?= esc_html__('Detailed access history', 'tenyen-analytics') ?></h2>
                    <span class="tya-history-status" data-history-status><?= esc_html__('Not loaded', 'tenyen-analytics') ?></span>
                </div>
                <div class="tya-history-actions">
                    <button type="button" class="button" data-history-toggle aria-expanded="false"><?= esc_html__('Open history', 'tenyen-analytics') ?></button>
                    <button type="button" class="button" data-history-settings-toggle aria-expanded="false">⚙ <?= esc_html__('Display settings', 'tenyen-analytics') ?></button>
                </div>
            </header>

            <div class="tya-history-settings" data-history-settings hidden>
                <div class="tya-history-settings-grid">
                    <fieldset>
                        <legend><?= esc_html__('Display density', 'tenyen-analytics') ?></legend>
                        <label><input type="radio" name="history_density" value="compact"> <?= esc_html__('Compact', 'tenyen-analytics') ?></label>
                        <label><input type="radio" name="history_density" value="standard"> <?= esc_html__('Standard', 'tenyen-analytics') ?></label>
                        <label><input type="checkbox" name="history_wrap"> <?= esc_html__('Wrap cells', 'tenyen-analytics') ?></label>
                        <label><input type="checkbox" name="history_sticky"> <?= esc_html__('Sticky header', 'tenyen-analytics') ?></label>
                        <label><input type="checkbox" name="history_collapsed"> <?= esc_html__('Collapsed by default', 'tenyen-analytics') ?></label>
                    </fieldset>
                    <fieldset>
                        <legend><?= esc_html__('Visible columns', 'tenyen-analytics') ?></legend>
                        <?php foreach ([
                            'datetime' => __('Date', 'tenyen-analytics'),
                            'event' => __('Event type', 'tenyen-analytics'),
                            'ip' => 'IP',
                            'location' => __('Location', 'tenyen-analytics'),
                            'organization' => __('ASN / candidate organization', 'tenyen-analytics'),
                            'page' => __('Page', 'tenyen-analytics'),
                            'referrer' => __('Referrer', 'tenyen-analytics'),
                            'environment' => __('Environment', 'tenyen-analytics'),
                            'details' => __('Details', 'tenyen-analytics'),
                        ] as $value => $label): ?>
                            <label><input type="checkbox" name="history_columns[]" value="<?= esc_attr($value) ?>"> <?= esc_html($label) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset>
                        <legend><?= esc_html__('Auto refresh', 'tenyen-analytics') ?></legend>
                        <label><?= esc_html__('Interval', 'tenyen-analytics') ?>
                            <select name="history_auto_refresh">
                                <option value="0"><?= esc_html__('Disabled', 'tenyen-analytics') ?></option>
                                <option value="30"><?= esc_html__('30 seconds', 'tenyen-analytics') ?></option>
                                <option value="60"><?= esc_html__('1 minute', 'tenyen-analytics') ?></option>
                                <option value="300"><?= esc_html__('5 minutes', 'tenyen-analytics') ?></option>
                            </select>
                        </label>
                        <p class="description"><?= esc_html__('Display settings are saved to localStorage in this browser. The access data itself is not stored here.', 'tenyen-analytics') ?></p>
                    </fieldset>
                </div>
                <div class="tya-history-settings-actions">
                    <button type="button" class="button button-primary" data-settings-apply><?= esc_html__('Apply settings', 'tenyen-analytics') ?></button>
                    <button type="button" class="button" data-settings-reset><?= esc_html__('Reset to defaults', 'tenyen-analytics') ?></button>
                </div>
            </div>

            <div class="tya-history-body" data-history-body hidden>
                <form class="tya-history-filter" data-history-form>
                    <label><?= esc_html__('Search', 'tenyen-analytics') ?><input type="search" name="q" placeholder="<?= esc_attr__('IP, URL, title, location, ASN, environment', 'tenyen-analytics') ?>" autocomplete="off"></label>
                    <label><?= esc_html__('From', 'tenyen-analytics') ?><input type="date" name="from"></label>
                    <label><?= esc_html__('To', 'tenyen-analytics') ?><input type="date" name="to"></label>
                    <label><?= esc_html__('Event', 'tenyen-analytics') ?>
                        <select name="event">
                            <option value="all"><?= esc_html__('All', 'tenyen-analytics') ?></option>
                            <option value="pageview">pageview</option>
                            <option value="engagement">engagement</option>
                            <option value="outbound">outbound</option>
                            <option value="download">download</option>
                            <option value="internal_link_click">internal_link_click</option>
                            <option value="button_click">button_click</option>
                            <option value="form_submit">form_submit</option>
                            <option value="not_found">not_found</option>
                            <option value="custom">custom</option>
                        </select>
                    </label>
                    <label><?= esc_html__('Event name', 'tenyen-analytics') ?><input type="text" name="event_name" maxlength="64"></label>
                    <label><?= esc_html__('Source page', 'tenyen-analytics') ?><input type="text" name="source_page" maxlength="128"></label>
                    <label><?= esc_html__('Target domain', 'tenyen-analytics') ?><input type="text" name="target_host" maxlength="128"></label>
                    <label>UTM source<input type="text" name="utm_source" maxlength="128"></label>
                    <label>UTM medium<input type="text" name="utm_medium" maxlength="128"></label>
                    <label>UTM campaign<input type="text" name="utm_campaign" maxlength="128"></label>
                    <label><?= esc_html__('ASN / organization', 'tenyen-analytics') ?><input type="text" name="organization" maxlength="128"></label>
                    <label><?= esc_html__('Visitor', 'tenyen-analytics') ?>
                        <select name="actor">
                            <option value="human"><?= esc_html__('Humans only', 'tenyen-analytics') ?></option>
                            <option value="bot"><?= esc_html__('Bots only', 'tenyen-analytics') ?></option>
                            <option value="all"><?= esc_html__('All', 'tenyen-analytics') ?></option>
                        </select>
                    </label>
                    <label><?= esc_html__('Country', 'tenyen-analytics') ?><select name="country"><option value=""><?= esc_html__('All countries', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('Browser', 'tenyen-analytics') ?><select name="browser"><option value=""><?= esc_html__('All browsers', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('OS', 'tenyen-analytics') ?><select name="os"><option value=""><?= esc_html__('All operating systems', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('Device', 'tenyen-analytics') ?><select name="device"><option value=""><?= esc_html__('All devices', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('Items per page', 'tenyen-analytics') ?>
                        <select name="per_page"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
                    </label>
                    <label><?= esc_html__('Sort order', 'tenyen-analytics') ?>
                        <select name="order"><option value="desc"><?= esc_html__('Newest first', 'tenyen-analytics') ?></option><option value="asc"><?= esc_html__('Oldest first', 'tenyen-analytics') ?></option></select>
                    </label>
                    <div class="tya-history-filter-actions">
                        <button type="submit" class="button button-primary"><?= esc_html__('Search', 'tenyen-analytics') ?></button>
                        <button type="button" class="button" data-filter-reset><?= esc_html__('Reset', 'tenyen-analytics') ?></button>
                    </div>
                </form>
                <p class="tya-history-help"><?= esc_html__('Search, filters, and paging update asynchronously without reloading the whole page. Raw IP search is exact; others are partial matches.', 'tenyen-analytics') ?></p>
                <div class="tya-history-range" data-history-range-top></div>
                <div data-history-table><div class="tya-history-empty"><?= esc_html__('Open the history panel to load events.', 'tenyen-analytics') ?></div></div>
                <div class="tya-history-range" data-history-range-bottom></div>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    public function renderSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'tenyen-analytics'));
        }
        $city = (string)get_option('tya_city_db', '');
        $asn = (string)get_option('tya_asn_db', '');
        $reader = (new GeoIp($city, $asn))->isReaderAvailable();
        echo '<div class="wrap"><h1>' . esc_html__('Tenyen Analytics Settings', 'tenyen-analytics') . '</h1>';
        echo '<p>' . esc_html__('Reader', 'tenyen-analytics') . ': <b>' . ($reader ? 'OK' : esc_html__('Not installed', 'tenyen-analytics')) . '</b> / ' . esc_html__('City DB', 'tenyen-analytics') . ': <b>' . (is_readable($city) ? 'OK' : esc_html__('Unconfigured', 'tenyen-analytics')) . '</b> / ' . esc_html__('ASN DB', 'tenyen-analytics') . ': <b>' . (is_readable($asn) ? 'OK' : esc_html__('Unconfigured', 'tenyen-analytics')) . '</b></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('tya_settings');
        echo '<table class="form-table"><tbody>';
        $this->numberRow(__('Raw log retention days (0 = unlimited)', 'tenyen-analytics'), 'tya_retention_days', TYA_Lifecycle::sanitizeRetention(get_option('tya_retention_days', 90)), 0, 3650);
        $this->checkboxRow(__('Exclude administrator access', 'tenyen-analytics'), 'tya_exclude_admins', (bool)get_option('tya_exclude_admins', 1));
        $this->checkboxRow(__('Record bots as well', 'tenyen-analytics'), 'tya_log_bots', (bool)get_option('tya_log_bots', 1));
        $this->checkboxRow(__('Track internal link clicks', 'tenyen-analytics'), 'tya_track_internal_links', (bool)get_option('tya_track_internal_links', 0));
        $this->checkboxRow(__('Track generic button clicks', 'tenyen-analytics'), 'tya_track_buttons', (bool)get_option('tya_track_buttons', 0));
        $this->checkboxRow(__('Track opted-in form submissions', 'tenyen-analytics'), 'tya_track_forms', (bool)get_option('tya_track_forms', 0));
        echo '<tr><th>' . esc_html__('IP header to use', 'tenyen-analytics') . '</th><td><select name="tya_proxy_header">';
        $selected = (string)get_option('tya_proxy_header', '');
        foreach ([
            '' => __('REMOTE_ADDR (default)', 'tenyen-analytics'),
            'cf-connecting-ip' => 'CF-Connecting-IP',
            'x-real-ip' => 'X-Real-IP',
            'x-forwarded-for' => 'X-Forwarded-For',
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Change only if you understand your proxy configuration.', 'tenyen-analytics') . '</p></td></tr>';
        $this->textRow('GeoLite2 City DB', 'tya_city_db', $city);
        $this->textRow('GeoLite2 ASN DB', 'tya_asn_db', $asn);
        echo '<tr><th>' . esc_html__('Organization classification overrides', 'tenyen-analytics') . '</th><td><textarea class="large-text code" rows="7" name="tya_org_overrides">' . esc_textarea((string)get_option('tya_org_overrides', '')) . '</textarea>';
        echo '<p class="description"><code>AS2907=research</code> ' . esc_html__('one per line. Categories are research / government / company / isp / cloud / proxy / bot / unknown.', 'tenyen-analytics') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button();
        echo '</form><hr><p><b>' . esc_html__('Site token', 'tenyen-analytics') . ':</b> <code>' . esc_html((string)get_option('tya_site_token', '')) . '</code></p></div>';
    }

    private function numberRow(string $label, string $name, int $value, int $min, int $max): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input type="number" name="' . esc_attr($name) . '" value="' . esc_attr((string)$value) . '" min="' . $min . '" max="' . $max . '"></td></tr>';
    }

    private function checkboxRow(string $label, string $name, bool $checked): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html__('Enabled', 'tenyen-analytics') . '</label></td></tr>';
    }

    private function textRow(string $label, string $name, string $value): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input class="large-text code" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    /**
     * @return array{query:string,event:string,actor:string,date_from:string,date_to:string,per_page:int,page:int}
     */
    private function dashboardFilters(): array
    {
        $query = isset($_GET['tya_q']) ? sanitize_text_field(wp_unslash((string)$_GET['tya_q'])) : '';
        $query = trim($query);
        $query = function_exists('mb_substr') ? mb_substr($query, 0, 255) : substr($query, 0, 255);

        $event = isset($_GET['tya_event']) ? sanitize_key((string)$_GET['tya_event']) : 'all';
        $allowedEvents = ['all', 'pageview', 'engagement', 'external_click', 'download'];
        if (!in_array($event, $allowedEvents, true)) {
            $event = 'all';
        }

        $actor = isset($_GET['tya_actor']) ? sanitize_key((string)$_GET['tya_actor']) : 'all';
        if (!in_array($actor, ['all', 'human', 'bot'], true)) {
            $actor = 'all';
        }

        $dateFrom = $this->sanitizeDate(isset($_GET['tya_from']) ? (string)$_GET['tya_from'] : '');
        $dateTo = $this->sanitizeDate(isset($_GET['tya_to']) ? (string)$_GET['tya_to'] : '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $perPage = isset($_GET['tya_per_page']) ? (int)$_GET['tya_per_page'] : 50;
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 50;
        }

        return [
            'query' => $query,
            'event' => $event,
            'actor' => $actor,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
            'page' => max(1, isset($_GET['tya_paged']) ? (int)$_GET['tya_paged'] : 1),
        ];
    }

    private function sanitizeDate(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return '';
        }
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) ? $date : '';
    }

    private function localDateToUtc(string $date, bool $endExclusive): string
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        if (!$value instanceof DateTimeImmutable) {
            return '';
        }
        if ($endExclusive) {
            $value = $value->modify('+1 day');
        }
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array{query:string,event:string,actor:string,date_from:string,date_to:string,per_page:int,page:int} $filters
     */
    private function dashboardPagination(array $filters, int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $analysis = $this->analysisFilters();
        $args = [
            'page' => 'tenyen-analytics',
            'tya_period' => $analysis['period'],
            'tya_chart_from' => $analysis['from'],
            'tya_chart_to' => $analysis['to'],
            'tya_chart_actor' => $analysis['actor'],
            'tya_q' => $filters['query'],
            'tya_event' => $filters['event'],
            'tya_actor' => $filters['actor'],
            'tya_from' => $filters['date_from'],
            'tya_to' => $filters['date_to'],
            'tya_per_page' => $filters['per_page'],
            'tya_paged' => '%#%',
        ];
        $base = add_query_arg($args, admin_url('admin.php'));
        $base = str_replace('%25%23%25', '%#%', $base);
        $links = paginate_links([
            'base' => $base,
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
            'mid_size' => 2,
            'end_size' => 1,
            'prev_text' => esc_html__('‹ Previous', 'tenyen-analytics'),
            'next_text' => esc_html__('Next ›', 'tenyen-analytics'),
            'type' => 'plain',
        ]);

        return $links ? '<div class="tablenav-pages">' . $links . '</div>' : '';
    }

    private function todayUtcRange(): array
    {
        $timezone = wp_timezone();
        $start = new DateTimeImmutable('today', $timezone);
        $end = $start->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function shortHost(string $url): string
    {
        if ($url === '') {
            return 'Direct';
        }
        return (string)(wp_parse_url($url, PHP_URL_HOST) ?: $url);
    }

    /** @return array<int, string> */
    private function organizationOverrides(): array
    {
        return OrganizationClassifierV040::parseOverrides((string)get_option('tya_org_overrides', ''));
    }

    /** @param array{category:string,label:string,icon:string,featured:bool,confidence:int,reason:string} $classification */
    private function organizationBadge(array $classification): string
    {
        return '<span class="tya-badge tya-badge--' . esc_attr($classification['category']) . '" title="' . esc_attr($classification['reason'] . ' / ' . esc_html__('confidence', 'tenyen-analytics') . ' ' . $classification['confidence'] . '%') . '">' . esc_html($classification['icon'] . ' ' . $classification['label']) . '</span>';
    }

    private function formatDuration(int $milliseconds): string
    {
        $seconds = max(0, (int)round($milliseconds / 1000));
        if ($seconds < 60) {
            return sprintf(__('%d sec', 'tenyen-analytics'), $seconds);
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        if ($minutes < 60) {
            return sprintf(__('%d min %02d sec', 'tenyen-analytics'), $minutes, $remaining);
        }
        $hours = intdiv($minutes, 60);
        return sprintf(__('%d hr %d min', 'tenyen-analytics'), $hours, $minutes % 60);
    }

    /**
     * @return array{period:string,actor:string,from:string,to:string,label:string,days:int,start_local:DateTimeImmutable,end_local:DateTimeImmutable,start_utc:string,end_utc:string}
     */
    private function analysisFilters(): array
    {
        $period = isset($_GET['tya_period']) ? sanitize_key((string)$_GET['tya_period']) : '7d';
        if (!in_array($period, ['today', 'yesterday', '7d', '30d', 'month', 'custom'], true)) $period = '7d';
        $actor = isset($_GET['tya_chart_actor']) ? sanitize_key((string)$_GET['tya_chart_actor']) : 'human';
        if (!in_array($actor, ['human', 'bot', 'all'], true)) $actor = 'human';

        $timezone = wp_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        $fromInput = $this->sanitizeDate(isset($_GET['tya_chart_from']) ? (string)$_GET['tya_chart_from'] : '');
        $toInput = $this->sanitizeDate(isset($_GET['tya_chart_to']) ? (string)$_GET['tya_chart_to'] : '');
        switch ($period) {
            case 'today': $start = $today; $end = $today->modify('+1 day'); break;
            case 'yesterday': $start = $today->modify('-1 day'); $end = $today; break;
            case '30d': $start = $today->modify('-29 days'); $end = $today->modify('+1 day'); break;
            case 'month': $start = $today->modify('first day of this month'); $end = $today->modify('+1 day'); break;
            case 'custom':
                $start = $fromInput !== '' ? new DateTimeImmutable($fromInput, $timezone) : $today->modify('-6 days');
                $endInclusive = $toInput !== '' ? new DateTimeImmutable($toInput, $timezone) : $today;
                if ($start > $endInclusive) [$start, $endInclusive] = [$endInclusive, $start];
                $end = $endInclusive->modify('+1 day');
                break;
            default: $start = $today->modify('-6 days'); $end = $today->modify('+1 day');
        }
        if ($end->diff($start)->days > 730) $start = $end->modify('-730 days');
        $days = max(1, (int)$start->diff($end)->days);
        $utc = new DateTimeZone('UTC');
        return [
            'period' => $period, 'actor' => $actor,
            'from' => $start->format('Y-m-d'), 'to' => $end->modify('-1 day')->format('Y-m-d'),
            'label' => $start->format('Y-m-d') . ' ~ ' . $end->modify('-1 day')->format('Y-m-d'), 'days' => $days,
            'start_local' => $start, 'end_local' => $end,
            'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<int,array<string,mixed>> $databaseRows @return array<int,array{label:string,pageviews:int,visitors:int,sessions:int}> */
    private function fillTimeline(array $databaseRows, DateTimeImmutable $start, DateTimeImmutable $end, string $grain): array
    {
        $map = [];
        foreach ($databaseRows as $row) $map[(string)$row['bucket']] = $row;
        $result = [];
        if ($grain === 'hour') {
            $cursor = $start->setTime(0, 0);
            $step = new DateInterval('PT1H');
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-d H:00'); $row = $map[$key] ?? [];
                $result[] = ['label' => $cursor->format('m/d H:i'), 'pageviews' => (int)($row['pageviews'] ?? 0), 'visitors' => (int)($row['visitors'] ?? 0), 'sessions' => (int)($row['sessions'] ?? 0)];
                $cursor = $cursor->add($step);
            }
        } elseif ($grain === 'day') {
            $cursor = $start; $step = new DateInterval('P1D');
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-d'); $row = $map[$key] ?? [];
                $result[] = ['label' => $cursor->format('m/d'), 'pageviews' => (int)($row['pageviews'] ?? 0), 'visitors' => (int)($row['visitors'] ?? 0), 'sessions' => (int)($row['sessions'] ?? 0)];
                $cursor = $cursor->add($step);
            }
        } else {
            $cursor = $start->modify('first day of this month'); $step = new DateInterval('P1M');
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-01'); $row = $map[$key] ?? [];
                $result[] = ['label' => $cursor->format('Y/m'), 'pageviews' => (int)($row['pageviews'] ?? 0), 'visitors' => (int)($row['visitors'] ?? 0), 'sessions' => (int)($row['sessions'] ?? 0)];
                $cursor = $cursor->add($step);
            }
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,array{label:string,value:int}> */
    private function withOther(array $items, int $total): array
    {
        $rows = [];
        $shown = 0;
        foreach ($items as $item) {
            $value = (int)($item['value'] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $rows[] = ['label' => (string)($item['label'] ?? __('Unknown', 'tenyen-analytics')), 'value' => $value];
            $shown += $value;
        }
        if ($total > $shown) {
            $rows[] = ['label' => __('Other', 'tenyen-analytics'), 'value' => $total - $shown];
        }
        return $rows;
    }

    private function pageLink(string $path, string $label): string
    {
        $label = trim($label) !== '' ? $label : $path;
        $url = $this->pageUrl($path);
        return $url === '' ? esc_html($label) : '<a class="tya-out-link" target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function pageUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('~^https?://~i', $path)) return wp_http_validate_url($path) ? $path : '';
        if (!str_starts_with($path, '/')) $path = '/' . $path;
        return home_url($path);
    }

    private function referrerLink(string $url): string
    {
        if ($url === '') return 'Direct';
        return $this->absoluteLink($url, $this->shortHost($url));
    }

    private function absoluteLink(string $url, string $label): string
    {
        $scheme = strtolower((string)wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !wp_http_validate_url($url)) return esc_html($label);
        return '<a class="tya-out-link" target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public function dashboardWidget(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return new WP_REST_Response(
                ['ok' => false, 'message' => __('You do not have permission.', 'tenyen-analytics')],
                403
            );
        }

        $fresh = (bool)$request->get_param('fresh');
        $cacheKey = 'tya_dashboard_widget_v1';
        if ($fresh) {
            delete_transient($cacheKey);
        }
        $payload = $fresh ? false : get_transient($cacheKey);

        if ($payload === false) {
            $payload = $this->buildDashboardWidgetPayload();
            set_transient($cacheKey, $payload, 60);
        }

        $response = new WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    private function buildDashboardWidgetPayload(): array
    {
        global $wpdb;
        [$todayStart, $todayEnd] = $this->todayUtcRange();
        $table = TYA_Installer::tableName();
        $excluded = $this->analysisWhere('');

        $todaySummary = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(event_type='pageview') AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(visitor_id,'') END) AS visitors,
                    COUNT(DISTINCT CASE WHEN event_type='pageview' THEN NULLIF(session_id,'') END) AS sessions
             FROM {$table} WHERE occurred_at >= %s AND occurred_at < %s{$excluded}",
            $todayStart,
            $todayEnd
        ), ARRAY_A) ?: [];

        $realtimeSessions = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT NULLIF(session_id,'')) FROM {$table}
             WHERE event_type='pageview' AND is_bot=0 AND occurred_at >= %s{$excluded}",
            gmdate('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS)
        ));

        $topPages = $wpdb->get_results($wpdb->prepare(
            "SELECT path, MAX(page_title) AS title, COUNT(*) AS pageviews
             FROM {$table}
             WHERE event_type='pageview' AND occurred_at >= %s AND occurred_at < %s{$excluded}
             GROUP BY path ORDER BY pageviews DESC LIMIT 3",
            $todayStart,
            $todayEnd
        ), ARRAY_A) ?: [];

        $notableRows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT asn, asn_org FROM {$table}
             WHERE event_type='pageview' AND occurred_at >= %s AND occurred_at < %s AND asn_org <> ''{$excluded}
             ORDER BY occurred_at DESC LIMIT 300",
            $todayStart,
            $todayEnd
        ), ARRAY_A) ?: [];

        $notable = 0;
        foreach ($notableRows as $row) {
            $classification = OrganizationClassifierV040::classify(
                $row['asn'] !== null ? (int)$row['asn'] : null,
                (string)$row['asn_org'], false, $this->organizationOverrides()
            );
            if ($classification['featured']) {
                $notable += 1;
            }
        }

        $lastReceived = $wpdb->get_var("SELECT MAX(occurred_at) FROM {$table}");
        $lastReceivedAt = $lastReceived !== null ? get_date_from_gmt((string)$lastReceived, 'c') : null;

        return [
            'ok' => true,
            'today' => [
                'pageviews' => (int)($todaySummary['pageviews'] ?? 0),
                'visitors' => (int)($todaySummary['visitors'] ?? 0),
                'sessions' => (int)($todaySummary['sessions'] ?? 0),
            ],
            'realtime_sessions' => $realtimeSessions,
            'notable_organizations' => $notable,
            'top_pages' => array_map(static fn(array $row): array => [
                'path' => (string)$row['path'], 'title' => (string)$row['title'], 'pageviews' => (int)$row['pageviews'],
            ], $topPages),
            'last_received_at' => $lastReceivedAt,
            'generated_at' => wp_date('c'),
        ];
    }

    public function renderAdminFooter(): void
    {
        if (!is_admin()) {
            return;
        }

        $page = sanitize_key((string)($_GET['page'] ?? ''));
        if ($page === '' || !str_starts_with($page, 'tenyen-analytics')) {
            return;
        }

        $year = max(2026, (int)wp_date('Y'));
        $copyright = $year === 2026 ? '2026' : sprintf('2026–%d', $year);
        echo '<div class="tya-admin-footer-credit" style="margin:24px 0 0;padding-top:12px;border-top:1px solid #edf2f7;color:#6b7280;font-size:13px;">';
        echo esc_html(sprintf('Tenyen Analytics v%s — Powered by ', TYA_VERSION));
        echo '<a href="https://www.10yendama.com/" target="_blank" rel="noopener noreferrer">10yendama.com</a>';
        echo esc_html(sprintf(' — © %s 10yendama.com', $copyright));
        echo '</div>';
    }

    private function sessionAdmin(): TYA_Session_Admin
    {
        return $this->sessionAdmin ??= new TYA_Session_Admin();
    }

    private function metadata(): TYA_Metadata
    {
        return $this->metadata ??= new TYA_Metadata();
    }

    private function exclusions(): TYA_Exclusions
    {
        return $this->exclusions ??= new TYA_Exclusions();
    }

    private function lifecycle(): TYA_Lifecycle
    {
        return $this->lifecycle ??= new TYA_Lifecycle();
    }
}
