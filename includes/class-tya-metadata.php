<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Administrator-owned annotations, tags, watchlists, and private saved views.
 * Raw analytics rows are never updated by this class.
 */
final class TYA_Metadata
{
    public const ENTITY_TYPES = ['organization', 'visitor', 'content', 'referrer', 'campaign', 'external_target'];
    public const REPORTS = ['history', 'sessions', 'content', 'organizations', 'referrers', 'campaigns', 'events', 'external_targets'];
    public const COLORS = ['blue', 'green', 'orange', 'purple', 'gray', 'red'];

    private const FILTERS = [
        'history' => ['q', 'event', 'actor', 'country', 'organization', 'page_path', 'referrer', 'browser', 'os', 'device', 'date_from', 'date_to', 'order', 'per_page', 'visible_columns', 'tags', 'tag_match', 'watched', 'annotated'],
        'sessions' => ['q', 'actor', 'date_from', 'date_to', 'order', 'per_page', 'tags', 'watched', 'annotated'],
        'content' => ['period', 'from', 'to', 'actor', 'order', 'per_page', 'tags', 'annotated'],
        'organizations' => ['period', 'from', 'to', 'actor', 'order', 'per_page', 'q', 'tags', 'tag_match', 'watched', 'annotated'],
        'referrers' => ['period', 'from', 'to', 'actor', 'order', 'per_page', 'q', 'tags', 'annotated'],
        'campaigns' => ['period', 'from', 'to', 'actor', 'order', 'per_page', 'q', 'tags', 'annotated'],
        'events' => ['period', 'from', 'to', 'actor', 'event', 'order', 'per_page', 'q'],
        'external_targets' => ['period', 'from', 'to', 'actor', 'event', 'order', 'per_page', 'q', 'tags', 'annotated'],
    ];

    public function registerRoutes(): void
    {
        $permission = static fn(): bool => current_user_can('manage_options');
        register_rest_route('tenyen-analytics/v1', '/admin/annotations', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listAnnotations'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveAnnotation'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/annotations/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteAnnotation'], 'permission_callback' => $permission,
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/tags', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listTags'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveTag'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/tags/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'saveTag'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteTag'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/saved-views', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'listViews'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'saveView'], 'permission_callback' => $permission],
        ]);
        register_rest_route('tenyen-analytics/v1', '/admin/saved-views/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'saveView'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'deleteView'], 'permission_callback' => $permission],
        ]);
    }

    public static function entityKey(string $type, mixed $value): string
    {
        if (!in_array($type, self::ENTITY_TYPES, true)) return '';
        if ($type === 'organization') {
            $asn = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            return $asn === false ? '' : (string)$asn;
        }
        if ($type === 'campaign') {
            if (!is_array($value)) return '';
            $dimensions = [];
            foreach (['source', 'medium', 'campaign', 'content', 'term'] as $field) {
                $dimensions[$field] = self::plain((string)($value[$field] ?? ''), 256);
            }
            return hash('sha256', (string)wp_json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $key = self::plain((string)$value, 512);
        if ($type === 'visitor' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) return '';
        if ($type === 'content' && str_starts_with($key, 'post:') && !preg_match('/^post:[1-9]\d*$/', $key)) return '';
        if (in_array($type, ['referrer', 'external_target'], true)) {
            $key = strtolower(rtrim($key, '.'));
            if ($key === '' || strlen($key) > 253 || !preg_match('/^[a-z0-9.-]+$/', $key)) return '';
        }
        return $key === '' ? '' : (strlen($key) <= 191 ? $key : hash('sha256', $key));
    }

    public function listAnnotations(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $page = max(1, (int)$request->get_param('page'));
        $perPage = min(100, max(1, (int)($request->get_param('per_page') ?: 25)));
        $offset = ($page - 1) * $perPage;
        $type = sanitize_key((string)$request->get_param('entity_type'));
        $watched = $request->get_param('watched');
        $where = ['1=1']; $params = [];
        if (in_array($type, self::ENTITY_TYPES, true)) { $where[] = 'a.entity_type=%s'; $params[] = $type; }
        if ($watched !== null && $watched !== '') { $where[] = 'a.watched=%d'; $params[] = rest_sanitize_boolean($watched) ? 1 : 0; }
        $q = self::plain((string)$request->get_param('q'), 120);
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(a.alias LIKE %s OR a.note LIKE %s OR a.original_value LIKE %s OR a.entity_key LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $table = TYA_Installer::annotationsTable();
        $total = (int)$wpdb->get_var($this->prepare("SELECT COUNT(*) FROM {$table} a WHERE {$whereSql}", $params));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$table} a WHERE {$whereSql} ORDER BY a.updated_at DESC,a.annotation_id DESC LIMIT %d OFFSET %d",
            ...[...$params, $perPage, $offset]
        ), ARRAY_A) ?: [];
        $this->attachTags($rows);
        return $this->response(['ok' => true, 'items' => $rows, 'total' => $total, 'page' => $page]);
    }

    public function saveAnnotation(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $input = $request->get_json_params();
        $type = sanitize_key((string)($input['entity_type'] ?? ''));
        $key = self::entityKey($type, $input['entity_key'] ?? '');
        if ($key === '') return $this->error(__('Invalid analytics entity.', 'tenyen-analytics'), 400);
        $alias = self::plain((string)($input['alias'] ?? ''), 120);
        $note = self::plainMultiline((string)($input['note'] ?? ''), 4000);
        $watched = $type === 'organization' && rest_sanitize_boolean($input['watched'] ?? false) ? 1 : 0;
        $tagIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['tag_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        if (count($tagIds) > 50) return $this->error(__('No more than 50 tags may be assigned.', 'tenyen-analytics'), 400);
        $now = gmdate('Y-m-d H:i:s'); $user = get_current_user_id();
        $table = TYA_Installer::annotationsTable();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE entity_type=%s AND entity_key=%s", $type, $key), ARRAY_A);
        $data = [
            'original_value' => self::plain((string)($input['original_value'] ?? ''), 512),
            'context_json' => isset($input['context']) && is_array($input['context']) ? wp_json_encode($input['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'alias' => $alias, 'note' => $note, 'watched' => $watched, 'updated_by' => $user, 'updated_at' => $now,
        ];
        if ($existing) {
            $wpdb->update($table, $data, ['annotation_id' => (int)$existing['annotation_id']]);
            $id = (int)$existing['annotation_id'];
        } else {
            $wpdb->insert($table, [...$data, 'entity_type' => $type, 'entity_key' => $key, 'created_by' => $user, 'created_at' => $now]);
            $id = (int)$wpdb->insert_id;
        }
        if ($id < 1) return $this->error(__('Metadata could not be saved.', 'tenyen-analytics'), 500);
        $relation = TYA_Installer::entityTagsTable();
        $wpdb->query($wpdb->prepare("DELETE FROM {$relation} WHERE annotation_id=%d", $id));
        foreach ($tagIds as $tagId) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$relation} (annotation_id,tag_id,created_at) SELECT %d,tag_id,%s FROM " . TYA_Installer::tagsTable() . " WHERE tag_id=%d", $id, $now, $tagId));
        }
        return $this->response(['ok' => true, 'id' => $id], $existing ? 200 : 201);
    }

    public function deleteAnnotation(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $id = (int)$request->get_param('id');
        $wpdb->delete(TYA_Installer::entityTagsTable(), ['annotation_id' => $id]);
        $deleted = $wpdb->delete(TYA_Installer::annotationsTable(), ['annotation_id' => $id]);
        return $this->response(['ok' => (bool)$deleted], $deleted ? 200 : 404);
    }

    public function listTags(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $tags = TYA_Installer::tagsTable(); $relations = TYA_Installer::entityTagsTable();
        $rows = $wpdb->get_results("SELECT t.*,COUNT(r.annotation_id) usage_count FROM {$tags} t LEFT JOIN {$relations} r ON r.tag_id=t.tag_id GROUP BY t.tag_id ORDER BY t.normalized_name LIMIT 100", ARRAY_A) ?: [];
        return $this->response(['ok' => true, 'items' => $rows]);
    }

    public function saveTag(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $input = $request->get_json_params(); $id = (int)$request->get_param('id');
        $name = preg_replace('/\s+/u', ' ', self::plain((string)($input['name'] ?? ''), 50)) ?? '';
        $color = sanitize_key((string)($input['color'] ?? 'blue'));
        if ($name === '' || !in_array($color, self::COLORS, true)) return $this->error(__('Invalid tag name or color.', 'tenyen-analytics'), 400);
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $now = gmdate('Y-m-d H:i:s'); $table = TYA_Installer::tagsTable();
        if ($id > 0) $ok = $wpdb->update($table, ['name' => $name, 'normalized_name' => $normalized, 'color' => $color, 'updated_at' => $now], ['tag_id' => $id]);
        else $ok = $wpdb->insert($table, ['name' => $name, 'normalized_name' => $normalized, 'color' => $color, 'created_at' => $now, 'updated_at' => $now]);
        if ($ok === false) return $this->error(__('A tag with that name already exists.', 'tenyen-analytics'), 409);
        return $this->response(['ok' => true, 'id' => $id ?: (int)$wpdb->insert_id], $id ? 200 : 201);
    }

    public function deleteTag(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $id = (int)$request->get_param('id');
        $wpdb->delete(TYA_Installer::entityTagsTable(), ['tag_id' => $id]);
        $deleted = $wpdb->delete(TYA_Installer::tagsTable(), ['tag_id' => $id]);
        return $this->response(['ok' => (bool)$deleted], $deleted ? 200 : 404);
    }

    public function listViews(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $report = sanitize_key((string)$request->get_param('report')); $params = [get_current_user_id()];
        $where = 'user_id=%d';
        if (in_array($report, self::REPORTS, true)) { $where .= ' AND report=%s'; $params[] = $report; }
        $rows = $wpdb->get_results($this->prepare("SELECT * FROM " . TYA_Installer::savedViewsTable() . " WHERE {$where} ORDER BY pinned DESC,name ASC", $params), ARRAY_A) ?: [];
        foreach ($rows as &$row) $row['filters'] = json_decode((string)$row['filters_json'], true) ?: [];
        return $this->response(['ok' => true, 'items' => $rows]);
    }

    public function saveView(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $input = $request->get_json_params(); $id = (int)$request->get_param('id'); $user = get_current_user_id();
        $report = sanitize_key((string)($input['report'] ?? '')); $name = self::plain((string)($input['name'] ?? ''), 120);
        if (!in_array($report, self::REPORTS, true) || $name === '') return $this->error(__('Invalid saved view.', 'tenyen-analytics'), 400);
        $filters = $this->sanitizeFilters($report, is_array($input['filters'] ?? null) ? $input['filters'] : []);
        if (is_wp_error($filters)) return $this->error($filters->get_error_message(), 400);
        $table = TYA_Installer::savedViewsTable(); $now = gmdate('Y-m-d H:i:s'); $default = rest_sanitize_boolean($input['is_default'] ?? false) ? 1 : 0;
        if ($id > 0 && !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE view_id=%d AND user_id=%d", $id, $user))) return $this->error(__('Saved view not found.', 'tenyen-analytics'), 404);
        if ($default) $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_default=0 WHERE user_id=%d AND report=%s", $user, $report));
        $data = ['report' => $report, 'name' => $name, 'description' => self::plain((string)($input['description'] ?? ''), 500), 'schema_version' => 1, 'filters_json' => wp_json_encode($filters), 'pinned' => rest_sanitize_boolean($input['pinned'] ?? false) ? 1 : 0, 'is_default' => $default, 'updated_at' => $now];
        if ($id) $ok = $wpdb->update($table, $data, ['view_id' => $id, 'user_id' => $user]);
        else { $ok = $wpdb->insert($table, [...$data, 'user_id' => $user, 'created_at' => $now]); $id = (int)$wpdb->insert_id; }
        return $ok === false ? $this->error(__('Saved view could not be saved.', 'tenyen-analytics'), 500) : $this->response(['ok' => true, 'id' => $id], 200);
    }

    public function deleteView(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb; $deleted = $wpdb->delete(TYA_Installer::savedViewsTable(), ['view_id' => (int)$request->get_param('id'), 'user_id' => get_current_user_id()]);
        return $this->response(['ok' => (bool)$deleted], $deleted ? 200 : 404);
    }

    public function renderManager(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission.', 'tenyen-analytics'));
        echo '<div class="wrap tya-pages"><header class="tya-page-head"><div><h1>' . esc_html__('Knowledge', 'tenyen-analytics') . ' <small>v' . esc_html(TYA_VERSION) . '</small></h1><p>' . esc_html__('Manage administrator annotations, tags, organization watchlists, and private saved views.', 'tenyen-analytics') . '</p></div></header>';
        echo '<div id="tya-metadata-manager" class="tya-panel"><nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Knowledge sections', 'tenyen-analytics') . '"><button class="nav-tab nav-tab-active" data-meta-tab="annotations">' . esc_html__('Annotations', 'tenyen-analytics') . '</button><button class="nav-tab" data-meta-tab="watched">' . esc_html__('Watched organizations', 'tenyen-analytics') . '</button><button class="nav-tab" data-meta-tab="tags">' . esc_html__('Tags', 'tenyen-analytics') . '</button><button class="nav-tab" data-meta-tab="views">' . esc_html__('Saved views', 'tenyen-analytics') . '</button></nav><div data-meta-status role="status"></div><div data-meta-content></div></div>';
        echo '<p class="description">' . esc_html__('Access was observed from an IP address registered to the shown ASN/organization. This does not identify a person or prove employment or intent.', 'tenyen-analytics') . '</p></div>';
    }

    /** @return array<string,mixed>|WP_Error */
    private function sanitizeFilters(string $report, array $filters): array|WP_Error
    {
        $unknown = array_diff(array_keys($filters), self::FILTERS[$report]);
        if ($unknown !== []) return new WP_Error('invalid_filter', __('The saved view contains an unsupported filter.', 'tenyen-analytics'));
        unset($filters['page'], $filters['nonce'], $filters['_wpnonce']);
        $clean = [];
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                if (count($value) > 50) return new WP_Error('invalid_filter', __('A saved filter has too many values.', 'tenyen-analytics'));
                $clean[$key] = array_values(array_map(static fn($item): string => self::plain((string)$item, 120), $value));
            } elseif (is_bool($value)) $clean[$key] = $value;
            elseif (is_int($value) || is_float($value)) $clean[$key] = $value;
            else $clean[$key] = self::plain((string)$value, 500);
        }
        if (isset($clean['order']) && !in_array($clean['order'], ['asc', 'desc'], true)) return new WP_Error('invalid_sort', __('Unsupported sort direction.', 'tenyen-analytics'));
        if (isset($clean['per_page']) && !in_array((int)$clean['per_page'], [25, 50, 100], true)) return new WP_Error('invalid_page_size', __('Unsupported page size.', 'tenyen-analytics'));
        return $clean;
    }

    private function attachTags(array &$rows): void
    {
        global $wpdb; $ids = array_map(static fn(array $row): int => (int)$row['annotation_id'], $rows);
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $relations = TYA_Installer::entityTagsTable(); $tags = TYA_Installer::tagsTable();
        $found = $wpdb->get_results($wpdb->prepare("SELECT r.annotation_id,t.tag_id,t.name,t.color FROM {$relations} r JOIN {$tags} t ON t.tag_id=r.tag_id WHERE r.annotation_id IN ({$placeholders}) ORDER BY t.normalized_name", ...$ids), ARRAY_A) ?: [];
        $map = []; foreach ($found as $tag) $map[(int)$tag['annotation_id']][] = $tag;
        foreach ($rows as &$row) $row['tags'] = $map[(int)$row['annotation_id']] ?? [];
    }

    private static function plain(string $value, int $limit): string
    {
        $value = trim(wp_strip_all_tags($value, true));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private static function plainMultiline(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", wp_strip_all_tags($value, true));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($value)) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function prepare(string $sql, array $params): string { global $wpdb; return $params === [] ? $sql : $wpdb->prepare($sql, ...$params); }
    private function response(array $data, int $status = 200): WP_REST_Response { $response = new WP_REST_Response($data, $status); $response->header('Cache-Control', 'no-store, private'); return $response; }
    private function error(string $message, int $status): WP_REST_Response { return $this->response(['ok' => false, 'message' => $message], $status); }
}
