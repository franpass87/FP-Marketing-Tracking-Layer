<?php

namespace FPTracking\DataLayer;

use FPTracking\Admin\Settings;

final class DataLayerManager {

    /** @var array<int, array> */
    private array $queue = [];

    private EventSchema $schema;

    public function __construct(private readonly Settings $settings) {
        $this->schema = new EventSchema();
    }

    /**
     * Called by add_action('fp_tracking_event', ...).
     * Normalizes the event and adds it to the page queue.
     * Also triggers server-side dispatch for eligible events.
     *
     * @param string $event_name
     * @param array  $params
     */
    public function queue_event(string $event_name, array $params = []): void {
        // Ensure event_id exists before building the payload.
        // The same ID is used for both the dataLayer push (→ GTM → fbq eventID)
        // and the server-side dispatch (→ Meta CAPI event_id), enabling deduplication.
        if (empty($params['event_id'])) {
            $params['event_id'] = uniqid('fp_', true);
        }

        $event = $this->schema->build($event_name, $params);
        $this->queue[] = $event;

        // Trigger server-side dispatch (GA4 MP + Meta CAPI) for conversion events
        if ($this->is_server_side_event($event_name)) {
            do_action('fp_tracking_server_side', $event_name, $params);
        }
    }

    /**
     * Outputs window.dataLayer = window.dataLayer || []; in <head>.
     */
    public function output_init(): void {
        echo "<script>window.dataLayer = window.dataLayer || [];</script>\n";
    }

    /**
     * Outputs all queued events as dataLayer.push() calls in wp_footer.
     * user_data is stripped from the browser payload to avoid exposing PII.
     */
    public function output_events(): void {
        if (empty($this->queue)) {
            return;
        }

        // Strip user_data (PII) before pushing to the browser dataLayer
        $browser_queue = array_map(static function (array $event): array {
            unset($event['user_data']);
            return $event;
        }, $this->queue);

        $debug = $this->settings->get('debug_mode', false);
        $json  = wp_json_encode($browser_queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        echo "<script id=\"fp-tracking-events\">\n";
        echo "(function(){\n";
        echo "  var events = " . $json . ";\n";
        echo "  events.forEach(function(e){\n";
        if ($debug) {
            echo "    console.log('[FP Tracking] dataLayer.push', e);\n";
        }
        echo "    window.dataLayer = window.dataLayer || [];\n";
        echo "    window.dataLayer.push(e);\n";
        echo "  });\n";
        echo "})();\n";
        echo "</script>\n";
    }

    /**
     * Returns all queued events (used by server-side dispatcher if needed).
     */
    public function get_queue(): array {
        return $this->queue;
    }

    private function is_server_side_event(string $event_name): bool {
        // Must stay in sync with ServerSideDispatcher::META_EVENT_MAP + GA4-only high-value events.
        // All events here are dispatched to GA4 MP; those in META_EVENT_MAP also go to Meta CAPI.
        $server_side_events = [
            // WooCommerce
            'purchase',
            'add_to_cart',
            'begin_checkout',
            // FP-Restaurant
            'booking_confirmed',
            'booking_submitted',
            'booking_payment_completed',
            'event_ticket_purchase',
            // FP-Forms
            'generate_lead',
            'form_payment_started',
            // FP-Experiences
            'experience_checkout_started',
            'experience_paid',
            'rtb_submitted',
            'rtb_approved',
            'gift_purchased'
        ];
        return in_array($event_name, $server_side_events, true)
            && apply_filters('fp_tracking_server_side_enabled', true, $event_name);
    }
}
