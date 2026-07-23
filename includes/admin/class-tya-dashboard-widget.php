<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TYA_Dashboard_Widget
{
    public static function bootstrap(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function register(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'tenyen-analytics-dashboard-widget',
            __('Tenyen Analytics', 'tenyen-analytics'),
            [self::class, 'render']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'index.php' || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_script(
            'tenyen-analytics-dashboard-widget',
            TYA_URL . 'assets/dashboard-widget.js',
            ['wp-i18n'],
            TYA_VERSION,
            true
        );

        wp_set_script_translations(
            'tenyen-analytics-dashboard-widget',
            'tenyen-analytics',
            TYA_DIR . 'languages'
        );

        wp_enqueue_style(
            'tenyen-analytics-dashboard-widget',
            TYA_URL . 'assets/dashboard-widget.css',
            [],
            TYA_VERSION
        );

        $config = [
            'endpoint' => esc_url_raw(rest_url('tenyen-analytics/v1/admin/dashboard-widget')),
            'nonce' => wp_create_nonce('wp_rest'),
            'dashboardUrl' => esc_url_raw(admin_url('admin.php?page=tenyen-analytics')),
        ];

        wp_add_inline_script(
            'tenyen-analytics-dashboard-widget',
            'window.TYDashboardWidgetConfig=' . wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );
    }

    public static function render(): void
    {
        ?>
        <div class="tya-dashboard-widget" id="tya-dashboard-widget">
            <div class="tya-dashboard-widget__header">
                <div class="tya-dashboard-widget__title"><?php echo esc_html(__('Tenyen Analytics', 'tenyen-analytics')); ?></div>
                <button type="button" class="button button-secondary tya-dashboard-widget__refresh" aria-live="polite"><?php echo esc_html__('Refresh', 'tenyen-analytics'); ?></button>
            </div>
            <div class="tya-dashboard-widget__body">
                <div class="tya-dashboard-widget__loading" aria-live="polite"><?php echo esc_html__('Loading…', 'tenyen-analytics'); ?></div>
            </div>
            <div class="tya-dashboard-widget__footer">
                <?php echo wp_kses(
                    '<span>Powered by <a href="https://www.10yendama.com/" target="_blank" rel="noopener noreferrer">10yendama.com</a></span>',
                    ['span' => [], 'a' => ['href' => [], 'target' => [], 'rel' => []]]
                ); ?>
            </div>
        </div>
        <?php
    }
}
