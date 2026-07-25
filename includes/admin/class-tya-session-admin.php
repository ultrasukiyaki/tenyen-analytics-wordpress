<?php

declare(strict_types=1);

use Tenyen\Analytics\OrganizationClassifierV040;

if (!defined('ABSPATH')) {
    exit;
}

final class TYA_Session_Admin
{
    public function enqueue(): void
    {
        wp_enqueue_style('tenyen-analytics-sessions', TYA_URL . 'assets/admin-sessions.css', [], TYA_VERSION);
        wp_enqueue_script('tenyen-analytics-sessions', TYA_URL . 'assets/admin-sessions.js', ['wp-i18n'], TYA_VERSION, true);
        wp_set_script_translations('tenyen-analytics-sessions', 'tenyen-analytics', TYA_DIR . 'languages');
        wp_add_inline_script('tenyen-analytics-sessions', 'window.TYASessions=' . wp_json_encode([
            'listEndpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/sessions')),
            'sessionEndpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/sessions/')),
            'visitorEndpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/visitors/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';', 'before');
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'tenyen-analytics'));
        }
        ?>
        <div class="wrap tya-pages">
            <header class="tya-page-head"><div>
                <h1><?= esc_html__('Sessions', 'tenyen-analytics') ?> <small>v<?= esc_html(TYA_VERSION) ?></small></h1>
                <p><?= esc_html__('Browse ordered anonymous visitor journeys.', 'tenyen-analytics') ?></p>
            </div></header>
            <section class="tya-panel tya-sessions" data-sessions-root>
                <form class="tya-filters" data-sessions-form>
                    <label><?= esc_html__('Search', 'tenyen-analytics') ?><input name="q" type="search" maxlength="255" placeholder="<?= esc_attr__('Session, visitor, URL, referrer, or environment', 'tenyen-analytics') ?>"></label>
                    <label><?= esc_html__('From', 'tenyen-analytics') ?><input name="from" type="date"></label>
                    <label><?= esc_html__('To', 'tenyen-analytics') ?><input name="to" type="date"></label>
                    <label><?= esc_html__('Visitor type', 'tenyen-analytics') ?><select name="actor"><option value="human"><?= esc_html__('Humans only', 'tenyen-analytics') ?></option><option value="bot"><?= esc_html__('Bots only', 'tenyen-analytics') ?></option><option value="all"><?= esc_html__('All', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('Country', 'tenyen-analytics') ?><input name="country" maxlength="128"></label>
                    <label><?= esc_html__('Browser', 'tenyen-analytics') ?><input name="browser" maxlength="128"></label>
                    <label><?= esc_html__('OS', 'tenyen-analytics') ?><input name="os" maxlength="128"></label>
                    <label><?= esc_html__('Device', 'tenyen-analytics') ?><input name="device" maxlength="128"></label>
                    <label><?= esc_html__('Items per page', 'tenyen-analytics') ?><select name="per_page"><option>25</option><option>50</option><option>100</option></select></label>
                    <label><?= esc_html__('Sort order', 'tenyen-analytics') ?><select name="order"><option value="desc"><?= esc_html__('Newest first', 'tenyen-analytics') ?></option><option value="asc"><?= esc_html__('Oldest first', 'tenyen-analytics') ?></option></select></label>
                    <button class="button button-primary" type="submit"><?= esc_html__('Apply', 'tenyen-analytics') ?></button>
                    <button class="button" type="reset"><?= esc_html__('Reset', 'tenyen-analytics') ?></button>
                </form>
                <p class="description"><?= esc_html__('Legacy events without a session ID are excluded. Duration and bounce are estimated analytics metrics.', 'tenyen-analytics') ?></p>
                <div class="tya-session-status" role="status" aria-live="polite" data-sessions-status><?= esc_html__('Loading…', 'tenyen-analytics') ?></div>
                <div data-sessions-list></div>
            </section>
            <div class="tya-session-dialog" data-session-dialog hidden>
                <div class="tya-session-dialog__backdrop" data-dialog-close></div>
                <section role="dialog" aria-modal="true" aria-labelledby="tya-session-dialog-title" class="tya-session-dialog__panel">
                    <header><h2 id="tya-session-dialog-title" data-dialog-title><?= esc_html__('Details', 'tenyen-analytics') ?></h2><button type="button" class="button" data-dialog-close aria-label="<?= esc_attr__('Close', 'tenyen-analytics') ?>">×</button></header>
                    <div data-dialog-content></div>
                </section>
            </div>
        </div>
        <?php
    }

    public function listRest(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return $this->error(__('You do not have permission.', 'tenyen-analytics'), 403);
        }
        $filters = $this->filters($request->get_query_params());
        $result = $this->repository()->listSessions($filters);
        return $this->response(['ok' => true, 'html' => $this->listHtml($result), 'total' => $result['total']]);
    }

    public function sessionRest(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return $this->error(__('You do not have permission.', 'tenyen-analytics'), 403);
        }
        $id = $this->identifier((string)$request->get_param('id'));
        if ($id === '') {
            return $this->error(__('Invalid session identifier.', 'tenyen-analytics'), 400);
        }
        $session = $this->repository()->getSession($id);
        if ($session === null) {
            return $this->error(__('Session no longer available.', 'tenyen-analytics'), 404);
        }
        return $this->response(['ok' => true, 'title' => __('Session detail', 'tenyen-analytics'), 'html' => $this->sessionHtml($session)]);
    }

    public function visitorRest(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can('manage_options')) {
            return $this->error(__('You do not have permission.', 'tenyen-analytics'), 403);
        }
        $id = $this->identifier((string)$request->get_param('id'));
        if ($id === '') {
            return $this->error(__('Invalid visitor identifier.', 'tenyen-analytics'), 400);
        }
        $visitor = $this->repository()->getVisitor($id);
        if ($visitor === null) {
            return $this->error(__('Anonymous visitor no longer available.', 'tenyen-analytics'), 404);
        }
        return $this->response(['ok' => true, 'title' => __('Anonymous visitor detail', 'tenyen-analytics'), 'html' => $this->visitorHtml($visitor)]);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function filters(array $source): array
    {
        $clean = static function (mixed $value, int $length = 128): string {
            $value = trim(sanitize_text_field((string)$value));
            return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
        };
        $actor = sanitize_key((string)($source['actor'] ?? 'human'));
        $actor = in_array($actor, ['all', 'human', 'bot'], true) ? $actor : 'human';
        $date = static function (mixed $value): string {
            $value = (string)$value;
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };
        $from = $date($source['from'] ?? '');
        $to = $date($source['to'] ?? '');
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $toUtc = static function (string $value, bool $end): string {
            if ($value === '') return '';
            $time = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
            if (!$time) return '';
            return $time->modify($end ? '+1 day' : '+0 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $perPage = in_array((int)($source['per_page'] ?? 25), [25, 50, 100], true) ? (int)$source['per_page'] : 25;
        return [
            'query' => $clean($source['q'] ?? '', 255),
            'actor' => $actor,
            'country' => $clean($source['country'] ?? ''),
            'browser' => $clean($source['browser'] ?? ''),
            'os' => $clean($source['os'] ?? ''),
            'device' => $clean($source['device'] ?? ''),
            'from_utc' => $toUtc($from, false),
            'to_utc' => $toUtc($to, true),
            'per_page' => $perPage,
            'page' => max(1, (int)($source['page'] ?? 1)),
            'order' => strtolower((string)($source['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @param array<string,mixed> $result */
    private function listHtml(array $result): string
    {
        ob_start(); ?>
        <div class="tya-session-range"><?= esc_html(sprintf(__('%1$s–%2$s of %3$s sessions', 'tenyen-analytics'), number_format_i18n($result['first']), number_format_i18n($result['last']), number_format_i18n($result['total']))) ?></div>
        <div class="tya-table-wrap"><table class="tya-session-table"><thead><tr>
            <th><?= esc_html__('Session start', 'tenyen-analytics') ?></th><th><?= esc_html__('Last activity', 'tenyen-analytics') ?></th>
            <th><?= esc_html__('Anonymous visitor', 'tenyen-analytics') ?></th><th>PV</th><th><?= esc_html__('Estimated duration', 'tenyen-analytics') ?></th>
            <th><?= esc_html__('Landing page', 'tenyen-analytics') ?></th><th><?= esc_html__('Exit page', 'tenyen-analytics') ?></th>
            <th><?= esc_html__('Referrer', 'tenyen-analytics') ?></th><th><?= esc_html__('Environment', 'tenyen-analytics') ?></th><th><?= esc_html__('Details', 'tenyen-analytics') ?></th>
        </tr></thead><tbody>
        <?php if ($result['rows'] === []): ?><tr><td colspan="10"><?= esc_html__('No sessions found.', 'tenyen-analytics') ?></td></tr><?php endif; ?>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td><?= esc_html(get_date_from_gmt((string)$row['started_at'], 'Y-m-d H:i:s')) ?></td>
                <td><?= esc_html(get_date_from_gmt((string)$row['ended_at'], 'Y-m-d H:i:s')) ?></td>
                <td><button class="button-link tya-break" data-visitor-id="<?= esc_attr((string)$row['visitor_id']) ?>"><code><?= esc_html($this->shortId((string)$row['visitor_id'])) ?></code></button></td>
                <td><?= (int)$row['pageviews'] ?></td><td><?= esc_html($this->duration((int)($row['engaged_ms'] ?? 0))) ?></td>
                <td class="tya-break"><?= esc_html((string)($row['landing_title'] ?: $row['landing_path'] ?: '―')) ?></td>
                <td class="tya-break"><?= esc_html((string)($row['exit_title'] ?: $row['exit_path'] ?: '―')) ?></td>
                <td class="tya-break"><?= esc_html((string)($row['referrer'] ?: __('Direct', 'tenyen-analytics'))) ?></td>
                <td class="tya-break"><?= esc_html(trim(($row['country_name'] ?: '') . ' / ' . ($row['asn'] ? 'AS' . $row['asn'] : '') . ' ' . ($row['asn_org'] ?: '') . ' / ' . ($row['browser'] ?: '') . ' / ' . ($row['os'] ?: '') . ' / ' . ($row['device_type'] ?: ''), ' /') ?: '―') ?><br><?= (int)$row['is_bot'] ? 'Bot' : 'Human' ?></td>
                <td><button class="button" data-session-id="<?= esc_attr((string)$row['session_id']) ?>"><?= esc_html__('Details', 'tenyen-analytics') ?></button></td>
            </tr>
        <?php endforeach; ?></tbody></table></div>
        <?= $this->pagination((int)$result['page'], (int)$result['pages']) ?>
        <?php return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $session */
    private function sessionHtml(array $session): string
    {
        $environment = $session['environment'];
        $classification = OrganizationClassifierV040::classify(
            $environment['asn'] !== null ? (int)$environment['asn'] : null,
            (string)$environment['asn_org'],
            (bool)$environment['is_bot'],
            []
        );
        ob_start(); ?>
        <div class="tya-detail-grid">
            <dl><dt><?= esc_html__('Session', 'tenyen-analytics') ?></dt><dd><code><?= esc_html($session['session_id']) ?></code></dd></dl>
            <dl><dt><?= esc_html__('Anonymous visitor', 'tenyen-analytics') ?></dt><dd><button class="button-link" data-visitor-id="<?= esc_attr($session['visitor_id']) ?>"><code><?= esc_html($session['visitor_id'] ?: '―') ?></code></button></dd></dl>
            <dl><dt><?= esc_html__('Start / last activity', 'tenyen-analytics') ?></dt><dd><?= esc_html(get_date_from_gmt($session['started_at'], 'Y-m-d H:i:s') . ' – ' . get_date_from_gmt($session['ended_at'], 'Y-m-d H:i:s')) ?></dd></dl>
            <dl><dt><?= esc_html__('Pageviews', 'tenyen-analytics') ?></dt><dd><?= (int)$session['pageviews'] ?><?= $session['bounce'] ? ' · ' . esc_html__('Bounce (estimated)', 'tenyen-analytics') : '' ?></dd></dl>
            <dl><dt><?= esc_html__('Engaged time (estimated)', 'tenyen-analytics') ?></dt><dd><?= esc_html($this->duration((int)$session['engaged_ms'])) ?></dd></dl>
            <dl><dt><?= esc_html__('Session span', 'tenyen-analytics') ?></dt><dd><?= esc_html($this->duration((int)$session['span_seconds'] * 1000)) ?></dd></dl>
            <dl><dt><?= esc_html__('Country / region', 'tenyen-analytics') ?></dt><dd><?= esc_html(trim($environment['country_name'] . ' / ' . $environment['region'], ' /') ?: '―') ?></dd></dl>
            <dl><dt><?= esc_html__('ASN / registered organization', 'tenyen-analytics') ?></dt><dd><?= esc_html(trim(($environment['asn'] ? 'AS' . $environment['asn'] : '') . ' ' . $environment['asn_org']) ?: '―') ?></dd></dl>
            <dl><dt><?= esc_html__('Organization category', 'tenyen-analytics') ?></dt><dd><?= esc_html($classification['category']) ?></dd></dl>
            <dl><dt><?= esc_html__('Browser / OS / device', 'tenyen-analytics') ?></dt><dd><?= esc_html(trim($environment['browser'] . ' / ' . $environment['os'] . ' / ' . $environment['device_type'], ' /') ?: '―') ?></dd></dl>
        </div>
        <h3><?= esc_html__('Ordered journey', 'tenyen-analytics') ?></h3>
        <ol class="tya-journey">
        <?php foreach ($session['events'] as $event): $engagement = $session['engagement_by_path'][$event['path']] ?? null; ?>
            <li><time><?= esc_html(get_date_from_gmt($event['occurred_at'], 'Y-m-d H:i:s')) ?></time>
                <strong><?= esc_html($event['event_type']) ?></strong>
                <div class="tya-break"><?= esc_html($event['page_title'] ?: $event['path'] ?: '―') ?></div>
                <?php if ($event['event_type'] === 'pageview' && $engagement): ?><small><?= esc_html__('Final engagement', 'tenyen-analytics') ?>: <?= esc_html($this->duration((int)$engagement['duration_ms'])) ?> / <?= (int)$engagement['scroll_depth'] ?>%</small><?php endif; ?>
                <?php if ($event['referrer']): ?><small class="tya-break"><?= esc_html__('Referrer', 'tenyen-analytics') ?>: <?= esc_html($event['referrer']) ?></small><?php endif; ?>
                <?php if ($event['target_url']): ?><small class="tya-break"><?= esc_html__('Target URL', 'tenyen-analytics') ?>: <?= esc_html($event['target_url']) ?></small><?php endif; ?>
                <details><summary><?= esc_html__('Raw event', 'tenyen-analytics') ?></summary><pre><?= esc_html(wp_json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></details>
            </li>
        <?php endforeach; ?></ol>
        <?php return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $visitor */
    private function visitorHtml(array $visitor): string
    {
        ob_start(); ?>
        <div class="notice notice-info inline"><p><?= esc_html__('This is an anonymous browser identifier. It may change when browser storage is cleared or another browser or device is used, and is not proof of a person’s identity.', 'tenyen-analytics') ?></p></div>
        <div class="tya-detail-grid">
            <dl><dt><?= esc_html__('Anonymous visitor', 'tenyen-analytics') ?></dt><dd><code><?= esc_html($visitor['visitor_id']) ?></code></dd></dl>
            <dl><dt><?= esc_html__('First seen', 'tenyen-analytics') ?></dt><dd><?= esc_html(get_date_from_gmt($visitor['first_seen'], 'Y-m-d H:i:s')) ?></dd></dl>
            <dl><dt><?= esc_html__('Last seen', 'tenyen-analytics') ?></dt><dd><?= esc_html(get_date_from_gmt($visitor['last_seen'], 'Y-m-d H:i:s')) ?></dd></dl>
            <dl><dt><?= esc_html__('Total sessions', 'tenyen-analytics') ?></dt><dd><?= (int)$visitor['total_sessions'] ?></dd></dl>
            <dl><dt><?= esc_html__('Total pageviews', 'tenyen-analytics') ?></dt><dd><?= (int)$visitor['total_pageviews'] ?></dd></dl>
            <dl><dt><?= esc_html__('Engaged time (estimated)', 'tenyen-analytics') ?></dt><dd><?= esc_html($this->duration((int)$visitor['engaged_ms'])) ?></dd></dl>
            <dl><dt><?= esc_html__('Most recent environment', 'tenyen-analytics') ?></dt><dd><?= esc_html(trim(($visitor['recent']['browser'] ?? '') . ' / ' . ($visitor['recent']['os'] ?? '') . ' / ' . ($visitor['recent']['device_type'] ?? ''), ' /') ?: '―') ?></dd></dl>
        </div>
        <h3><?= esc_html__('Common landing pages', 'tenyen-analytics') ?></h3><ol><?php foreach ($visitor['common_landings'] as $row): ?><li class="tya-break"><?= esc_html($row['page_title'] ?: $row['path']) ?> (<?= (int)$row['sessions'] ?>)</li><?php endforeach; ?></ol>
        <h3><?= esc_html__('Common content', 'tenyen-analytics') ?></h3><ol><?php foreach ($visitor['common_content'] as $row): ?><li class="tya-break"><?= esc_html($row['page_title'] ?: $row['path']) ?> (<?= (int)$row['pageviews'] ?>)</li><?php endforeach; ?></ol>
        <h3><?= esc_html__('Common referrers', 'tenyen-analytics') ?></h3><ol><?php foreach ($visitor['common_referrers'] as $row): ?><li class="tya-break"><?= esc_html($row['referrer']) ?> (<?= (int)$row['sessions'] ?>)</li><?php endforeach; ?></ol>
        <h3><?= esc_html__('Historical sessions', 'tenyen-analytics') ?></h3><div class="tya-table-wrap"><table><thead><tr><th><?= esc_html__('Session start', 'tenyen-analytics') ?></th><th><?= esc_html__('Last activity', 'tenyen-analytics') ?></th><th>PV</th><th><?= esc_html__('Details', 'tenyen-analytics') ?></th></tr></thead><tbody>
        <?php foreach ($visitor['sessions'] as $row): ?><tr><td><?= esc_html(get_date_from_gmt($row['started_at'], 'Y-m-d H:i:s')) ?></td><td><?= esc_html(get_date_from_gmt($row['ended_at'], 'Y-m-d H:i:s')) ?></td><td><?= (int)$row['pageviews'] ?></td><td><button class="button" data-session-id="<?= esc_attr($row['session_id']) ?>"><?= esc_html__('Details', 'tenyen-analytics') ?></button></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php return (string)ob_get_clean();
    }

    private function pagination(int $page, int $pages): string
    {
        if ($pages <= 1) return '';
        $html = '<nav class="tya-session-pagination" aria-label="' . esc_attr__('Session paging', 'tenyen-analytics') . '">';
        if ($page > 1) $html .= '<button class="button" data-session-page="' . ($page - 1) . '">' . esc_html__('‹ Previous', 'tenyen-analytics') . '</button>';
        $html .= '<span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'tenyen-analytics'), $page, $pages)) . '</span>';
        if ($page < $pages) $html .= '<button class="button" data-session-page="' . ($page + 1) . '">' . esc_html__('Next ›', 'tenyen-analytics') . '</button>';
        return $html . '</nav>';
    }

    private function repository(): TYA_Session_Repository
    {
        global $wpdb;
        return new TYA_Session_Repository($wpdb, TYA_Installer::tableName());
    }

    private function identifier(string $value): string
    {
        $value = trim(sanitize_text_field($value));
        return $value !== '' && strlen($value) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
    }

    private function shortId(string $id): string
    {
        return strlen($id) > 16 ? substr($id, 0, 8) . '…' . substr($id, -6) : ($id ?: '―');
    }

    private function duration(int $milliseconds): string
    {
        $seconds = (int)round($milliseconds / 1000);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    private function error(string $message, int $status): WP_REST_Response
    {
        return $this->response(['ok' => false, 'message' => $message], $status);
    }
}
