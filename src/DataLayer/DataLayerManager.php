<?php

namespace FPTracking\DataLayer;

use FPTracking\Admin\Settings;
use FPTracking\Attribution\UTMCookieHandler;
use FPTracking\Catalog\EventCatalog;
use FPTracking\Inspector\EventInspector;
use FPTracking\Rules\EventRuleEngine;
use FPTracking\Validation\EventValidator;

final class DataLayerManager {

    /** @var array<int, array> */
    private array $queue = [];

    private EventSchema $schema;
    private EventRuleEngine $ruleEngine;
    private EventValidator $validator;
    private EventInspector $inspector;

    /** UTM/attribution params to inject from cookie (for GA4, Meta, Brevo). */
    private const UTM_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'msclkid', 'ttclid',
        'wbraid', 'gbraid',
        'utm_id', 'utm_source_platform', 'utm_campaign_id',
        'utm_creative_format', 'utm_marketing_tactic',
        '_fbc', '_fbp',
    ];

    public function __construct(private readonly Settings $settings) {
        $this->schema = new EventSchema();
        $this->ruleEngine = new EventRuleEngine();
        $this->validator = new EventValidator();
        $this->inspector = new EventInspector();
    }

    /**
     * Called by add_action('fp_tracking_event', ...).
     * Normalizes the event and adds it to the page queue.
     * Also triggers server-side dispatch for eligible events.
     * UTM params from cookie are merged into events for attribution (GA4, Meta, Brevo).
     *
     * @param string $event_name
     * @param array  $params
     */
    public function queue_event(string $event_name, array $params = []): void {
        $ruleResult = $this->ruleEngine->apply($event_name, $params);
        if ($ruleResult['drop']) {
            return;
        }
        $event_name = $ruleResult['event_name'];
        $params = $ruleResult['params'];

        // Merge UTM attribution from cookie (captured on landing) into params.
        // Ensures GA4, Meta, Brevo receive campaign data for conversions.
        $params = $this->merge_utm_attribution($params);

        $skip_server_dispatch = !empty($params['fp_skip_server_dispatch']);
        if ($skip_server_dispatch) {
            unset($params['fp_skip_server_dispatch']);
        }

        // Ensure event_id exists before building the payload.
        // The same ID is used for both the dataLayer push (→ GTM → fbq eventID)
        // and the server-side dispatch (→ Meta CAPI event_id), enabling deduplication.
        if (empty($params['event_id'])) {
            $params['event_id'] = uniqid('fp_', true);
        }

        $event = $this->schema->build($event_name, $params);
        $warnings = $this->validator->validate($event_name, $event);
        $sampleRate = (int) $this->settings->get('inspector_sample_rate', 10);
        $this->inspector->record($event_name, $event, $warnings, $sampleRate);
        $this->queue[] = $event;

        // Trigger server-side dispatch (GA4 MP + Meta CAPI) for conversion events
        if (!$skip_server_dispatch && $this->is_server_side_event($event_name)) {
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
        return in_array($event_name, EventCatalog::SERVER_SIDE_EVENTS, true)
            && apply_filters('fp_tracking_server_side_enabled', true, $event_name);
    }

    /**
     * Merges UTM attribution from cookie into params.
     * Only adds params not already set (caller override wins).
     * Used for GA4, Meta CAPI and Brevo campaign attribution.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function merge_utm_attribution(array $params): array {
        $attribution = UTMCookieHandler::get_current_attribution();
        if ($attribution === []) {
            return $params;
        }

        foreach (self::UTM_PARAMS as $param) {
            if (isset($attribution[$param]) && $attribution[$param] !== ''
                && !isset($params[$param])
            ) {
                $params[$param] = sanitize_text_field((string) $attribution[$param]);
            }
        }

        return apply_filters('fp_tracking_params_with_attribution', $params, $attribution);
    }
}
