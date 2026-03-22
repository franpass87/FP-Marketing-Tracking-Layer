<?php
/**
 * Plugin Name:       FP Marketing Tracking Layer
 * Plugin URI:        https://github.com/franpass87/FP-Marketing-Tracking-Layer
 * Description:       Centralized marketing tracking layer. Injects GTM, manages Consent Mode v2, routes events from all FP plugins to window.dataLayer and dispatches server-side events to GA4 Measurement Protocol and Meta Conversions API.
 * Version:           1.2.19
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Francesco Passeri
 * Author URI:        https://francescopasseri.com
 * License:           Proprietary
 * Text Domain:       fp-tracking
 */

defined('ABSPATH') || exit;

define('FP_TRACKING_VERSION', '1.2.19');
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

/**
 * Restituisce le impostazioni Brevo centralizzate per uso da altri plugin FP.
 *
 * I plugin FP-Forms, FP-Restaurant-Reservations, FP-Experiences devono usare
 * questa funzione per ottenere API key e liste invece di avere impostazioni duplicate.
 *
 * @return array{
 *   api_key: string,
 *   list_id_it: int,
 *   list_id_en: int,
 *   enabled: bool,
 *   endpoint: string
 * }
 */
function fp_tracking_get_brevo_settings(): array {
    if (!defined('FP_TRACKING_VERSION')) {
        return [
            'api_key' => '',
            'list_id_it' => 0,
            'list_id_en' => 0,
            'enabled' => false,
            'endpoint' => 'https://api.brevo.com/v3/events',
        ];
    }
    $s = get_option('fp_tracking_settings', []);
    $s = is_array($s) ? $s : [];
    $base = [
        'api_key' => (string) ($s['brevo_api_key'] ?? ''),
        'list_id_it' => (int) ($s['brevo_list_id_it'] ?? 0),
        'list_id_en' => (int) ($s['brevo_list_id_en'] ?? 0),
        'enabled' => !empty($s['brevo_enabled']) && !empty($s['brevo_api_key'] ?? ''),
        'endpoint' => (string) ($s['brevo_endpoint'] ?? 'https://api.brevo.com/v3/events'),
    ];
    return apply_filters('fp_tracking_brevo_settings', $base);
}

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('fp_tracking_queue_worker');
    wp_clear_scheduled_hook('fp_tracking_queue_heartbeat');
});
