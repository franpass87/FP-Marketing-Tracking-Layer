<?php

namespace FPTracking\Core;

use FPTracking\Admin\Settings;
use FPTracking\Attribution\UTMCookieHandler;
use FPTracking\Consent\ConsentBridge;
use FPTracking\DataLayer\DataLayerManager;
use FPTracking\GTM\ClaritySnippet;
use FPTracking\GTM\GtmSnippet;
use FPTracking\Integrations\WooCommerceIntegration;
use FPTracking\Integrations\WordPressIntegration;
use FPTracking\ServerSide\ServerSideDispatcher;

final class Plugin {

    private static ?self $instance = null;

    private Settings $settings;
    private GtmSnippet $gtm;
    private ClaritySnippet $clarity;
    private DataLayerManager $dataLayer;
    private ConsentBridge $consent;
    private UTMCookieHandler $utm;
    private ServerSideDispatcher $serverSide;
    private WooCommerceIntegration $woocommerce;
    private WordPressIntegration $wordpress;

    private function __construct() {}

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $this->settings    = new Settings();
        $this->consent     = new ConsentBridge($this->settings);
        $this->gtm         = new GtmSnippet($this->settings);
        $this->clarity     = new ClaritySnippet($this->settings);
        $this->dataLayer   = new DataLayerManager($this->settings);
        $this->utm         = new UTMCookieHandler($this->settings);
        $this->serverSide  = new ServerSideDispatcher($this->settings);
        $this->woocommerce = new WooCommerceIntegration($this->settings);
        $this->wordpress   = new WordPressIntegration($this->settings);

        $this->register_hooks();

        if (is_admin()) {
            $this->settings->register_admin_hooks();
        }
    }

    private function register_hooks(): void {
        add_action('init', [$this->utm, 'capture']);

        // Consent Mode v2 — must run before GTM
        add_action('wp_head', [$this->consent, 'output_defaults'], 0);

        // GTM snippet
        add_action('wp_head', [$this->gtm, 'output_head'], 1);
        add_action('wp_body_open', [$this->gtm, 'output_body'], 1);

        // Clarity snippet (after GTM)
        add_action('wp_head', [$this->clarity, 'output_head'], 3);

        // DataLayer init + event flush
        add_action('wp_head', [$this->dataLayer, 'output_init'], 2);
        add_action('wp_footer', [$this->dataLayer, 'output_events'], 90);

        // Central event listener — all FP plugins fire this action
        add_action('fp_tracking_event', [$this->dataLayer, 'queue_event'], 10, 2);

        // Server-side dispatch triggered after events are queued
        add_action('fp_tracking_server_side', [$this->serverSide, 'dispatch'], 10, 2);

        // Consent update from FP-Privacy
        add_action('fp_consent_update', [$this->consent, 'on_consent_update'], 10, 3);

        // WooCommerce ecommerce events
        $this->woocommerce->register_hooks();

        // WordPress core events (search, CF7, GF, WPForms, login)
        $this->wordpress->register_hooks();

        // Enqueue fp-tracking.js on frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void {
        wp_enqueue_script(
            'fp-tracking',
            FP_TRACKING_URL . 'assets/js/fp-tracking.js',
            [],
            FP_TRACKING_VERSION,
            true
        );

        wp_localize_script('fp-tracking', 'fpTrackingConfig', [
            'debug'        => (bool) $this->settings->get('debug_mode', false),
            'scrollDepths' => [25, 50, 75, 90, 100],
            'videoPercents'=> [25, 50, 75],
            'ajaxUrl'      => admin_url('admin-ajax.php'),
        ]);
    }
}
