<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/wordpress/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['tya_actions'] = [];
$GLOBALS['tya_routes'] = [];
$GLOBALS['tya_submenus'] = [];
$GLOBALS['tya_widgets'] = [];
$GLOBALS['tya_can_manage'] = true;

function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://example.test/plugins/tenyen-analytics/'; }
function plugin_basename(string $file): string { return 'tenyen-analytics/' . basename($file); }
function register_activation_hook(): void {}
function register_deactivation_hook(): void {}
function load_plugin_textdomain(): void {}
function add_action(string $hook, callable $callback): void { $GLOBALS['tya_actions'][$hook][] = $callback; }
function add_filter(): void {}
function is_admin(): bool { return true; }
function current_user_can(string $capability): bool { return $capability === 'manage_options' && $GLOBALS['tya_can_manage']; }
function __($text): string { return $text; }
function esc_html($text): string { return $text; }
function esc_html__($text): string { return $text; }
function esc_attr__($text): string { return $text; }
function esc_attr($text): string { return $text; }
function add_menu_page(): void {}
function add_submenu_page($parent, $title, $label, $capability, $slug): void { $GLOBALS['tya_submenus'][] = $slug; }
function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['tya_routes'][$namespace . $route] = $args; }
function wp_add_dashboard_widget(string $id): void { $GLOBALS['tya_widgets'][] = $id; }

final class WP_REST_Server
{
    public const CREATABLE = 'POST';
    public const READABLE = 'GET';
}

class WP_REST_Request
{
    public function get_param(string $name): mixed { return null; }
}

class WP_REST_Response
{
    public function __construct(public mixed $data, public int $status = 200) {}
    public function header(): void {}
}

require dirname(__DIR__) . '/tenyen-analytics.php';

$plugin = TYA_Plugin::instance();
$historyShellMethod = new ReflectionMethod($plugin, 'renderHistoryShell');
$historyShellMethod->setAccessible(true);
$historyShell = $historyShellMethod->invoke($plugin);
if (!str_contains($historyShell, '<h2>Detailed access history</h2>')) {
    throw new RuntimeException('Access history heading did not render.');
}
if (str_contains($historyShell, "esc_html__(") || str_contains($historyShell, "esc_attr__(")) {
    throw new RuntimeException('Access history leaked PHP translation expressions into the HTML.');
}

$plugin->registerAdminMenu();
if (count($GLOBALS['tya_submenus']) !== 11 || !in_array('tenyen-analytics-sessions', $GLOBALS['tya_submenus'], true)) {
    throw new RuntimeException('Expected all eleven plugin submenu pages including sessions.');
}

$plugin->registerRoutes();
$widgetRoute = $GLOBALS['tya_routes']['tenyen-analytics/v1/admin/dashboard-widget'] ?? null;
if (!$widgetRoute || $widgetRoute['methods'] !== WP_REST_Server::READABLE) {
    throw new RuntimeException('Dashboard widget REST route did not register as readable.');
}
$sessionRoute = $GLOBALS['tya_routes']['tenyen-analytics/v1/admin/sessions'] ?? null;
$sessionDetailRoute = $GLOBALS['tya_routes']['tenyen-analytics/v1/admin/sessions/(?P<id>[A-Za-z0-9_-]+)'] ?? null;
$visitorRoute = $GLOBALS['tya_routes']['tenyen-analytics/v1/admin/visitors/(?P<id>[A-Za-z0-9_-]+)'] ?? null;
if (!$sessionRoute || !$sessionDetailRoute || !$visitorRoute) {
    throw new RuntimeException('Session and visitor REST routes did not register.');
}

TYA_Dashboard_Widget::register();
if (count($GLOBALS['tya_widgets']) !== 1) {
    throw new RuntimeException('Authorized dashboard widget did not register.');
}

$GLOBALS['tya_can_manage'] = false;
TYA_Dashboard_Widget::register();
if (count($GLOBALS['tya_widgets']) !== 1) {
    throw new RuntimeException('Unauthorized dashboard widget registered.');
}
if (($widgetRoute['permission_callback'])() !== false) {
    throw new RuntimeException('Unauthorized REST permission callback was allowed.');
}
foreach ([$sessionRoute, $sessionDetailRoute, $visitorRoute] as $route) {
    if (($route['permission_callback'])() !== false) {
        throw new RuntimeException('Unauthorized session analytics REST permission callback was allowed.');
    }
}
$response = $plugin->dashboardWidget(new WP_REST_Request());
if ($response->status !== 403) {
    throw new RuntimeException('Unauthorized dashboard widget response was not denied.');
}

echo "Smoke checks passed.\n";
