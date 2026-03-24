<?php

namespace FPTracking\Core;

use FPTracking\Admin\Settings;
use FPTracking\Audit\ConsentAuditService;
use FPTracking\Attribution\UTMCookieHandler;
use FPTracking\Consent\ConsentBridge;
use FPTracking\DataLayer\DataLayerManager;
use FPTracking\GTM\ClaritySnippet;
use FPTracking\GTM\GtmSnippet;
use FPTracking\Health\EventHealthService;
use FPTracking\Integrations\WooCommerceIntegration;
use FPTracking\Integrations\WordPressIntegration;
use FPTracking\Queue\EventQueueRepository;
use FPTracking\Queue\QueueWorker;
use FPTracking\Queue\RetryPolicy;
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
    private EventQueueRepository $eventQueue;
    private QueueWorker $queueWorker;
    private EventHealthService $healthService;
    private ConsentAuditService $consentAudit;
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
        $this->eventQueue  = new EventQueueRepository();
        $this->queueWorker = new QueueWorker($this->eventQueue, $this->serverSide, new RetryPolicy());
        $this->healthService = new EventHealthService($this->eventQueue);
        $this->consentAudit = new ConsentAuditService();
        $this->woocommerce = new WooCommerceIntegration($this->settings);
        $this->wordpress   = new WordPressIntegration($this->settings);

        $this->eventQueue->ensure_schema();

        $this->register_hooks();

        if (is_admin()) {
            $this->settings->register_admin_hooks($this->eventQueue, $this->healthService);
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
        add_action('fp_tracking_server_side', [$this, 'enqueue_server_side_event'], 10, 2);

        // Consent update from FP-Privacy
        add_action('fp_consent_update', [$this->consent, 'on_consent_update'], 10, 3);
        add_action('fp_consent_update', [$this, 'record_consent_audit'], 20, 3);

        // WooCommerce ecommerce events
        $this->woocommerce->register_hooks();

        // WordPress core events (search, CF7, GF, WPForms, login)
        $this->wordpress->register_hooks();

        // Enqueue fp-tracking.js on frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Queue worker + health heartbeat
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_cron_scheduled'], 25);
        add_action('fp_tracking_queue_worker', [$this, 'run_queue_worker']);
        add_action('fp_tracking_queue_heartbeat', [$this, 'run_health_heartbeat']);
    }

    public function register_cron_schedules(array $schedules): array {
        if (!isset($schedules['fp_tracking_every_minute'])) {
            $schedules['fp_tracking_every_minute'] = [
                'interval' => 60,
                'display'  => 'FP Tracking Every Minute',
            ];
        }
        return $schedules;
    }

    public function ensure_cron_scheduled(): void {
        if (!wp_next_scheduled('fp_tracking_queue_worker')) {
            wp_schedule_event(time() + 60, 'fp_tracking_every_minute', 'fp_tracking_queue_worker');
        }
        if (!wp_next_scheduled('fp_tracking_queue_heartbeat')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'fp_tracking_queue_heartbeat');
        }
    }

    public function enqueue_server_side_event(string $event_name, array $params): void {
        $enqueued = $this->eventQueue->enqueue($event_name, $params);
        if (!$enqueued) {
            // Fallback to direct dispatch if queue insert fails.
            $this->serverSide->dispatch($event_name, $params);
        }
    }

    public function run_queue_worker(): void {
        $this->queueWorker->run();
    }

    public function run_health_heartbeat(): void {
        $this->healthService->run_heartbeat_check();
    }

    public function record_consent_audit(array $states, string $event, int $revision): void {
        $this->consentAudit->record_update($states, $event, $revision);
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
            'siteName'     => (string) \get_bloginfo('name'),
        ]);
    }
}
