<?php
/**
 * Plugin Name:       FP Marketing Tracking Layer
 * Plugin URI:        https://github.com/franpass87/FP-Marketing-Tracking-Layer
 * Description:       Centralized marketing tracking layer. Injects GTM, manages Consent Mode v2, routes events from all FP plugins to window.dataLayer and dispatches server-side events to GA4 Measurement Protocol and Meta Conversions API.
 * Version:           1.2.22
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Francesco Passeri
 * Author URI:        https://francescopasseri.com
 * License:           Proprietary
 * Text Domain:       fp-tracking
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('FP_TRACKING_VERSION', '1.2.22');
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
 *   source_lists: array<string, array{it:int,en:int}>,
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
            'source_lists' => [],
            'enabled' => false,
            'endpoint' => 'https://api.brevo.com/v3/events',
        ];
    }
    $s = get_option('fp_tracking_settings', []);
    $s = is_array($s) ? $s : [];
    $default_it = (int) ($s['brevo_list_id_it'] ?? 0);
    $default_en = (int) ($s['brevo_list_id_en'] ?? 0);
    $resolved_default_en = $default_en > 0 ? $default_en : $default_it;

    $sources = ['forms', 'restaurant', 'experiences', 'woocommerce', 'ctabar', 'discountgift', 'bio'];
    $source_lists = [];
    foreach ($sources as $source) {
        $source_it = (int) ($s['brevo_list_id_' . $source . '_it'] ?? 0);
        $source_en = (int) ($s['brevo_list_id_' . $source . '_en'] ?? 0);

        $resolved_it = $source_it > 0 ? $source_it : $default_it;
        $resolved_en = $source_en > 0
            ? $source_en
            : ($source_it > 0 ? $source_it : $resolved_default_en);

        $source_lists[$source] = [
            'it' => $resolved_it,
            'en' => $resolved_en,
        ];
    }

    $base = [
        'api_key' => (string) ($s['brevo_api_key'] ?? ''),
        'list_id_it' => $default_it,
        'list_id_en' => $resolved_default_en,
        'source_lists' => $source_lists,
        'enabled' => !empty($s['brevo_enabled']) && !empty($s['brevo_api_key'] ?? ''),
        'endpoint' => (string) ($s['brevo_endpoint'] ?? 'https://api.brevo.com/v3/events'),
    ];
    return apply_filters('fp_tracking_brevo_settings', $base);
}

/**
 * Restituisce il list ID Brevo risolto per provenienza e lingua.
 *
 * Provenienze supportate: forms, restaurant, experiences, ctabar, discountgift, bio.
 * Fallback: lista specifica sorgente -> lista default lingua -> lista default ITA.
 */
function fp_tracking_get_brevo_list_id(string $source = '', string $language = 'it'): int {
    $settings = fp_tracking_get_brevo_settings();
    $lang = strtolower($language) === 'en' ? 'en' : 'it';

    if ($source !== '' && isset($settings['source_lists']) && is_array($settings['source_lists'])) {
        $source_key = sanitize_key($source);
        $source_lists = $settings['source_lists'];
        if (isset($source_lists[$source_key]) && is_array($source_lists[$source_key])) {
            $candidate = (int) ($source_lists[$source_key][$lang] ?? 0);
            if ($candidate > 0) {
                return $candidate;
            }
        }
    }

    $default_lang = (int) ($settings['list_id_' . $lang] ?? 0);
    if ($default_lang > 0) {
        return $default_lang;
    }

    return (int) ($settings['list_id_it'] ?? 0);
}

/**
 * Restituisce una source Brevo consigliata in base all'evento tracking.
 *
 * Utile per separare WooCommerce standard dagli eventi Experiences che possono
 * transitare da WooCommerce ma vengono tracciati con nomi evento dedicati.
 */
function fp_tracking_brevo_source_from_event(string $event_name): string {
    $event = sanitize_key($event_name);

    $woocommerce_events = ['purchase', 'add_to_cart', 'begin_checkout', 'cart_abandoned', 'view_item', 'view_item_list', 'view_cart', 'refund'];
    if (in_array($event, $woocommerce_events, true)) {
        return 'woocommerce';
    }

    $experiences_events = ['experience_view', 'experience_checkout_view', 'gift_redeem_view', 'booking_start', 'booking_abandon', 'rtb_start', 'gift_start', 'experience_checkout_started', 'experience_paid', 'experience_cancelled', 'rtb_submitted', 'rtb_approved', 'rtb_declined', 'rtb_hold_expired', 'gift_purchased', 'gift_redeemed'];
    if (in_array($event, $experiences_events, true)) {
        return 'experiences';
    }

    return '';
}

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('fp_tracking_queue_worker');
    wp_clear_scheduled_hook('fp_tracking_queue_heartbeat');
});
