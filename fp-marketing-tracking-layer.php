<?php
/**
 * Plugin Name:       FP Marketing Tracking Layer
 * Plugin URI:        https://github.com/franpass87/FP-Marketing-Tracking-Layer
 * Description:       Centralized marketing tracking layer. Injects GTM, manages Consent Mode v2, routes events from all FP plugins to window.dataLayer and dispatches server-side events to GA4 Measurement Protocol and Meta Conversions API.
 * Version:           1.2.5
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Francesco Passeri
 * Author URI:        https://francescopasseri.com
 * License:           Proprietary
 * Text Domain:       fp-tracking
 */

defined('ABSPATH') || exit;

define('FP_TRACKING_VERSION', '1.2.5');
define('FP_TRACKING_FILE', __FILE__);
define('FP_TRACKING_DIR', plugin_dir_path(__FILE__));
define('FP_TRACKING_URL', plugin_dir_url(__FILE__));

$autoload = FP_TRACKING_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>FP Marketing Tracking Layer:</strong> ' .
            esc_html__('Esegui `composer install` nella cartella del plugin oppure carica la cartella vendor.', 'fp-tracking') .
            '</p></div>';
    });
    return;
}
require_once $autoload;

add_action('plugins_loaded', static function (): void {
    try {
        \FPTracking\Core\Plugin::instance()->init();
    } catch (Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FP-Marketing-Tracking-Layer] FATAL: ' . $e->getMessage());
            error_log('[FP-Marketing-Tracking-Layer] File: ' . $e->getFile() . ':' . $e->getLine());
        }
        throw $e;
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('fp_tracking_queue_worker');
    wp_clear_scheduled_hook('fp_tracking_queue_heartbeat');
});
