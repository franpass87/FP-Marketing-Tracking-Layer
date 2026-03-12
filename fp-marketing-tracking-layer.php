<?php
/**
 * Plugin Name:       FP Marketing Tracking Layer
 * Plugin URI:        https://github.com/franpass87/FP-Marketing-Tracking-Layer
 * Description:       Centralized marketing tracking layer. Injects GTM, manages Consent Mode v2, routes events from all FP plugins to window.dataLayer and dispatches server-side events to GA4 Measurement Protocol and Meta Conversions API.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Francesco Passeri
 * Author URI:        https://francescopasseri.com
 * License:           Proprietary
 * Text Domain:       fp-tracking
 */

defined('ABSPATH') || exit;

define('FP_TRACKING_VERSION', '1.0.2');
define('FP_TRACKING_FILE', __FILE__);
define('FP_TRACKING_DIR', plugin_dir_path(__FILE__));
define('FP_TRACKING_URL', plugin_dir_url(__FILE__));

if (file_exists(FP_TRACKING_DIR . 'vendor/autoload.php')) {
    require_once FP_TRACKING_DIR . 'vendor/autoload.php';
}

add_action('plugins_loaded', static function (): void {
    \FPTracking\Core\Plugin::instance()->init();
});
