<?php

declare(strict_types=1);

use Tenyen\Analytics\Crypto;
use Tenyen\Analytics\ExclusionRuleEngine;

if (!defined('ABSPATH')) exit;

final class TYA_Exclusions
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $cache = null;

    public function registerRoutes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('tenyen-analytics/v1', '/admin/exclusions', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listRules'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveRule'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/exclusions/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'saveRule'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteRule'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/exclusions/diagnose', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'diagnose'], 'permission_callback' => $permission,
        ]);
    }

    public function listRules(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $page = max(1, (int)$request->get_param('page'));
        $perPage = (int)$request->get_param('per_page');
        if (!in_array($perPage, [25, 50, 100], true)) $perPage = 50;
        $table = TYA_Installer::exclusionsTable();
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT rule_id,rule_type AS type,rule_value AS value,scope,enabled,note,created_at,updated_at FROM {$table} ORDER BY rule_id ASC LIMIT %d OFFSET %d", $perPage, $offset), ARRAY_A) ?: [];
        foreach ($rows as &$row) { $row['rule_id'] = (int)$row['rule_id']; $row['enabled'] = (bool)$row['enabled']; }
        unset($row);
        return $this->response(['ok' => true, 'rules' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    public function saveRule(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $input = $request->get_json_params();
        if (!is_array($input)) return $this->error(__('Invalid request body.', 'tenyen-analytics'), 400);
        $id = max(0, (int)$request->get_param('id'));
        $type = sanitize_key((string)($input['type'] ?? ''));
        $scope = sanitize_key((string)($input['scope'] ?? ''));
        $validated = ExclusionRuleEngine::validate($type, $input['value'] ?? '', $scope);
        if (!$validated['valid']) return $this->error($this->validationError($validated['error']), 400);
        $note = trim(sanitize_textarea_field((string)($input['note'] ?? '')));
        $note = $this->truncate($note, 500);
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'rule_type' => $type, 'rule_value' => $validated['value'], 'scope' => $scope,
            'enabled' => rest_sanitize_boolean($input['enabled'] ?? true) ? 1 : 0,
            'note' => $note, 'updated_at' => $now,
        ];
        $table = TYA_Installer::exclusionsTable();
        if ($id > 0) {
            if (!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE rule_id=%d", $id))) return $this->error(__('Exclusion rule not found.', 'tenyen-analytics'), 404);
            $ok = $wpdb->update($table, $data, ['rule_id' => $id]);
        } else {
            if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}") >= 5000) return $this->error(__('The exclusion-rule limit has been reached.', 'tenyen-analytics'), 400);
            $data['created_at'] = $now;
            $ok = $wpdb->insert($table, $data);
            $id = (int)$wpdb->insert_id;
        }
        if ($ok === false) return $this->error(__('Could not save the exclusion rule.', 'tenyen-analytics'), 500);
        $this->cache = null;
        return $this->response(['ok' => true, 'rule_id' => $id]);
    }

    public function deleteRule(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $id = max(0, (int)$request->get_param('id'));
        $deleted = $wpdb->delete(TYA_Installer::exclusionsTable(), ['rule_id' => $id], ['%d']);
        if (!$deleted) return $this->error(__('Exclusion rule not found.', 'tenyen-analytics'), 404);
        $this->cache = null;
        return $this->response(['ok' => true]);
    }

    public function diagnose(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) return $this->error(__('Invalid request body.', 'tenyen-analytics'), 400);
        $scope = sanitize_key((string)($input['scope'] ?? 'collection'));
        if (!in_array($scope, ExclusionRuleEngine::SCOPES, true)) return $this->error(__('Invalid diagnostic scope.', 'tenyen-analytics'), 400);
        $context = [];
        foreach (['ip','path','country','region','asn','organization','category','browser','os','device','referrer_domain','utm_source','utm_medium','utm_campaign'] as $key) {
            $value = $input[$key] ?? '';
            if (is_scalar($value)) $context[$key] = $this->truncate(sanitize_text_field((string)$value), 512);
        }
        $context['administrator'] = rest_sanitize_boolean($input['administrator'] ?? false);
        $context['bot'] = rest_sanitize_boolean($input['bot'] ?? false);
        $diagnostic = $scope === 'collection'
            ? $this->collectionDiagnostic($context)
            : ExclusionRuleEngine::diagnose($this->rules(), $context, $scope);
        return $this->response(['ok' => true, 'diagnostic' => $diagnostic]);
    }

    /** @param array<string,mixed> $context */
    public function collectionDiagnostic(array $context): array
    {
        $rules = $this->rules();
        if ((bool)get_option('tya_exclude_admins', 1) && !empty($context['administrator'])) {
            $rules[] = ['rule_id' => 0, 'type' => 'administrator', 'value' => '1', 'scope' => 'collection', 'enabled' => true];
        }
        if (!(bool)get_option('tya_log_bots', 1) && !empty($context['bot'])) {
            $rules[] = ['rule_id' => 0, 'type' => 'bot', 'value' => '1', 'scope' => 'collection', 'enabled' => true];
        }
        return ExclusionRuleEngine::diagnose($rules, $context, 'collection');
    }

    public function analysisWhere(string $alias, Crypto $crypto): string
    {
        if ($alias !== '' && !preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) return '';
        $column = static fn(string $name): string => ($alias !== '' ? $alias . '.' : '') . $name;
        $text = static fn(string $value): string => "CONVERT(UNHEX('" . bin2hex($value) . "') USING utf8mb4)";
        $predicates = [];
        foreach ($this->rules() as $rule) {
            if (!$rule['enabled'] || $rule['scope'] !== 'analysis') continue;
            $value = (string)$rule['value'];
            $predicate = match ($rule['type']) {
                'ip_exact' => $column('ip_hash') . "=UNHEX('" . bin2hex($crypto->hashIp($value)) . "')",
                'path_exact' => 'SUBSTRING_INDEX(' . $column('path') . ",CHAR(63),1)=" . $text($value),
                'path_prefix' => 'SUBSTRING_INDEX(' . $column('path') . ",CHAR(63),1) LIKE CONCAT(" . $text($value) . ',CHAR(37))',
                'bot' => $column('is_bot') . '=1',
                'country' => $column('country_code') . '=' . $text($value),
                'region' => 'LOWER(' . $column('region') . ')=' . $text($value),
                'asn' => $column('asn') . '=' . (int)$value,
                'organization' => 'LOWER(' . $column('asn_org') . ')=' . $text($value),
                'browser' => 'LOWER(' . $column('browser') . ')=' . $text($value),
                'os' => 'LOWER(' . $column('os') . ')=' . $text($value),
                'device' => 'LOWER(' . $column('device_type') . ')=' . $text($value),
                'referrer_domain' => 'LOWER(' . $column('referrer_host') . ')=' . $text($value),
                'utm_source', 'utm_medium', 'utm_campaign' => 'LOWER(' . $column((string)$rule['type']) . ')=' . $text($value),
                default => '',
            };
            if ($predicate !== '') $predicates[] = '(' . $predicate . ')';
        }
        return $predicates === [] ? '' : ' AND NOT (' . implode(' OR ', $predicates) . ')';
    }

    public function renderManager(): void
    {
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission.', 'tenyen-analytics'));
        ?>
        <div class="wrap tya-pages" id="tya-exclusions">
            <header class="tya-page-head"><div><h1><?= esc_html__('Exclusions', 'tenyen-analytics') ?> <small>v<?= esc_html(TYA_VERSION) ?></small></h1><p><?= esc_html__('Exclude future collection or hide preserved historical rows from analysis.', 'tenyen-analytics') ?></p></div></header>
            <div class="notice notice-warning inline"><p><?= esc_html__('Collection exclusions are prospective. Analysis exclusions hide matching history but never delete it.', 'tenyen-analytics') ?></p></div>
            <section class="tya-panel"><h2><?= esc_html__('Add or edit rule', 'tenyen-analytics') ?></h2>
                <form data-exclusion-form class="tya-exclusion-form">
                    <input type="hidden" name="rule_id" value="0">
                    <label><?= esc_html__('Type', 'tenyen-analytics') ?><select name="type" required></select></label>
                    <label><?= esc_html__('Value', 'tenyen-analytics') ?><input name="value" maxlength="512" required></label>
                    <label><?= esc_html__('Scope / action', 'tenyen-analytics') ?><select name="scope"><option value="collection"><?= esc_html__('Exclude from collection', 'tenyen-analytics') ?></option><option value="analysis"><?= esc_html__('Exclude from analysis', 'tenyen-analytics') ?></option></select></label>
                    <label><?= esc_html__('Note', 'tenyen-analytics') ?><input name="note" maxlength="500"></label>
                    <label><input type="checkbox" name="enabled" checked> <?= esc_html__('Enabled', 'tenyen-analytics') ?></label>
                    <button class="button button-primary" type="submit"><?= esc_html__('Save rule', 'tenyen-analytics') ?></button><button class="button" type="reset"><?= esc_html__('Cancel edit', 'tenyen-analytics') ?></button>
                </form><p data-exclusion-status aria-live="polite"></p>
            </section>
            <section class="tya-panel"><h2><?= esc_html__('Rules', 'tenyen-analytics') ?></h2><div class="tya-table-wrap" data-exclusion-list></div></section>
            <section class="tya-panel"><h2><?= esc_html__('Diagnostic', 'tenyen-analytics') ?></h2><p><?= esc_html__('Test representative request fields. The result identifies the first matching rule, its precedence, action, and reason.', 'tenyen-analytics') ?></p>
                <form data-diagnostic-form class="tya-exclusion-form"><label><?= esc_html__('Scope', 'tenyen-analytics') ?><select name="scope"><option value="collection"><?= esc_html__('Collection', 'tenyen-analytics') ?></option><option value="analysis"><?= esc_html__('Analysis', 'tenyen-analytics') ?></option></select></label><label>IP<input name="ip" maxlength="45"></label><label><?= esc_html__('Path', 'tenyen-analytics') ?><input name="path" maxlength="512"></label><label><?= esc_html__('Country code', 'tenyen-analytics') ?><input name="country" maxlength="2"></label><label><?= esc_html__('Region', 'tenyen-analytics') ?><input name="region" maxlength="128"></label><label>ASN<input name="asn" maxlength="10"></label><label><?= esc_html__('Organization', 'tenyen-analytics') ?><input name="organization" maxlength="256"></label><label><?= esc_html__('Organization category', 'tenyen-analytics') ?><input name="category" maxlength="32"></label><label><?= esc_html__('Browser', 'tenyen-analytics') ?><input name="browser" maxlength="64"></label><label><?= esc_html__('OS', 'tenyen-analytics') ?><input name="os" maxlength="64"></label><label><?= esc_html__('Device', 'tenyen-analytics') ?><input name="device" maxlength="32"></label><label><?= esc_html__('Referrer domain', 'tenyen-analytics') ?><input name="referrer_domain" maxlength="253"></label><label><?= esc_html__('UTM source', 'tenyen-analytics') ?><input name="utm_source" maxlength="128"></label><label><?= esc_html__('UTM medium', 'tenyen-analytics') ?><input name="utm_medium" maxlength="128"></label><label><?= esc_html__('UTM campaign', 'tenyen-analytics') ?><input name="utm_campaign" maxlength="256"></label><label><input type="checkbox" name="administrator" value="1"> <?= esc_html__('Administrator', 'tenyen-analytics') ?></label><label><input type="checkbox" name="bot" value="1"> <?= esc_html__('Bot', 'tenyen-analytics') ?></label><button class="button" type="submit"><?= esc_html__('Run diagnostic', 'tenyen-analytics') ?></button></form><pre data-diagnostic-result></pre>
            </section>
        </div>
        <?php
    }

    /** @return array<int,array<string,mixed>> */
    private function rules(bool $enabledOnly = true): array
    {
        global $wpdb;
        if ($this->cache === null) {
            $rows = $wpdb->get_results('SELECT rule_id,rule_type AS type,rule_value AS value,scope,enabled,note,created_at,updated_at FROM ' . TYA_Installer::exclusionsTable() . ' ORDER BY rule_id ASC', ARRAY_A) ?: [];
            $this->cache = array_map(static function (array $row): array { $row['rule_id'] = (int)$row['rule_id']; $row['enabled'] = (bool)$row['enabled']; return $row; }, $rows);
        }
        return $enabledOnly ? array_values(array_filter($this->cache, static fn(array $row): bool => $row['enabled'])) : $this->cache;
    }

    private function truncate(string $value, int $length): string { return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length); }
    private function validationError(string $error): string
    {
        return match ($error) {
            'Unsupported rule type.' => __('Unsupported rule type.', 'tenyen-analytics'),
            'Unsupported rule scope.' => __('Unsupported rule scope.', 'tenyen-analytics'),
            'This rule type is collection-only.' => __('This rule type is collection-only.', 'tenyen-analytics'),
            'Rule value must be plain text.' => __('Rule value must be plain text.', 'tenyen-analytics'),
            'Rule value contains invalid characters.' => __('Rule value contains invalid characters.', 'tenyen-analytics'),
            'Rule value is required.' => __('Rule value is required.', 'tenyen-analytics'),
            'Invalid IP address.' => __('Invalid IP address.', 'tenyen-analytics'),
            'Invalid IPv4 or IPv6 CIDR.' => __('Invalid IPv4 or IPv6 CIDR.', 'tenyen-analytics'),
            'Invalid ASN.' => __('Invalid ASN.', 'tenyen-analytics'),
            'Country must be a two-letter code.' => __('Country must be a two-letter code.', 'tenyen-analytics'),
            'Unsupported device type.' => __('Unsupported device type.', 'tenyen-analytics'),
            'Unsupported organization category.' => __('Unsupported organization category.', 'tenyen-analytics'),
            'Invalid referrer domain.' => __('Invalid referrer domain.', 'tenyen-analytics'),
            'Invalid path.' => __('Invalid path.', 'tenyen-analytics'),
            'Rule value is too long.' => __('Rule value is too long.', 'tenyen-analytics'),
            default => __('Invalid exclusion rule.', 'tenyen-analytics'),
        };
    }
    private function response(array $data, int $status = 200): WP_REST_Response { $response = new WP_REST_Response($data, $status); $response->header('Cache-Control', 'no-store, private'); return $response; }
    private function error(string $message, int $status): WP_REST_Response { return $this->response(['ok' => false, 'message' => $message], $status); }
}
