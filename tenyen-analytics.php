<?php
/**
 * Plugin Name: Tenyen Analytics for WordPress
 * Description: A self-hosted analytics plugin for WordPress with pageviews, sessions, engagement metrics, GeoLite2 geolocation, ASN insights, bot detection, and asynchronous admin reports.
 * Version: 0.8.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: 10yendama.com
 * Author URI: https://www.10yendama.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tenyen-analytics
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('TYA_VERSION', '0.8.0');
define('TYA_FILE', __FILE__);
define('TYA_DIR', plugin_dir_path(__FILE__));
define('TYA_URL', plugin_dir_url(__FILE__));

require_once TYA_DIR . 'includes/core/autoload.php';
require_once TYA_DIR . 'includes/class-tya-installer.php';
require_once TYA_DIR . 'includes/class-tya-session-repository.php';
require_once TYA_DIR . 'includes/class-tya-metadata.php';
require_once TYA_DIR . 'includes/class-tya-exclusions.php';
require_once TYA_DIR . 'includes/class-tya-aggregation.php';
require_once TYA_DIR . 'includes/class-tya-lifecycle.php';
require_once TYA_DIR . 'includes/class-tya-geolite-updater.php';
require_once TYA_DIR . 'includes/admin/class-tya-dashboard-widget.php';
require_once TYA_DIR . 'includes/admin/class-tya-session-admin.php';
require_once TYA_DIR . 'includes/core/src/OrganizationClassifierV040.php';
require_once TYA_DIR . 'includes/class-tya-plugin.php';

register_activation_hook(__FILE__, ['TYA_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['TYA_Installer', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('tenyen-analytics', false, dirname(plugin_basename(__FILE__)) . '/languages');
    TYA_Plugin::instance()->boot();
});
