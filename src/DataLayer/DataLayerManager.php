<?php
declare(strict_types=1);

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

        $server_side_event = !$skip_server_dispatch && $this->is_server_side_event($event_name);
        $server_side_payload = null;
        if ($server_side_event) {
            $server_side_payload = $this->prepare_server_side_payload($event_name, $params);
            if ($server_side_payload === null) {
                $server_side_event = false;
            }
        }

        $event = $this->schema->build($event_name, $params);
        $warnings = $this->validator->validate($event_name, $event);
        $sampleRate = (int) $this->settings->get('inspector_sample_rate', 10);
        $this->inspector->record($event_name, $event, $warnings, $sampleRate);
        $this->queue[] = $event;

        // Trigger server-side dispatch (GA4 MP + Meta CAPI) for conversion events
        if ($server_side_event && $server_side_payload !== null) {
            do_action('fp_tracking_server_side', $event_name, $server_side_payload);
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
            unset($event['fp_server_side_consent']);
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
     * Public consent gate for direct server-side enqueue paths.
     *
     * @return bool True when at least one enabled server-side channel can run.
     */
    public function server_side_consent_granted(string $event_name): bool {
        return $this->has_any_server_side_channel_consent($event_name, [
            'fp_server_side_consent' => $this->current_consent_state(),
        ]);
    }

    /**
     * Prepares a payload for queueing by preserving request-only match keys and consent state.
     *
     * @param array<string, mixed> $params Event payload.
     * @return array<string, mixed>|null Payload or null when no configured channel has consent.
     */
    public function prepare_server_side_payload(string $event_name, array $params): ?array {
        $params = $this->with_server_side_user_data($params);
        $params['fp_server_side_consent'] = $this->current_consent_state();

        if (!$this->has_any_server_side_channel_consent($event_name, $params)) {
            return null;
        }

        return $params;
    }

    /**
     * Checks whether at least one configured server-side destination has consent.
     *
     * @param array<string, mixed> $params Event payload.
     * @return bool True when at least one enabled destination can receive the event.
     */
    private function has_any_server_side_channel_consent(string $event_name, array $params): bool {
        $required = (bool) apply_filters('fp_tracking_server_side_consent_required', true, $event_name);
        if (!$required) {
            return true;
        }

        $allowed = false;
        if ($this->settings->get('server_side_ga4', true) && $this->channel_consent_granted('ga4', $event_name, $params)) {
            $allowed = true;
        }

        if (!$allowed
            && $this->settings->get('server_side_meta', true)
            && isset(EventCatalog::META_EVENT_MAP[$event_name])
            && $this->channel_consent_granted('meta', $event_name, $params)
        ) {
            $allowed = true;
        }

        if (!$allowed
            && $this->settings->get('brevo_enabled', false)
            && $this->channel_consent_granted('brevo', $event_name, $params)
        ) {
            $allowed = true;
        }

        return (bool) apply_filters('fp_tracking_server_side_has_consent', $allowed, $event_name, 'any', $params);
    }

    /**
     * Checks whether a destination has the consent category it needs.
     *
     * @param array<string, mixed> $params Event payload.
     * @return bool True when the requested destination has consent.
     */
    public function channel_consent_granted(string $channel, string $event_name, array $params): bool {
        $required = (bool) apply_filters('fp_tracking_server_side_consent_required', true, $event_name, $channel);
        if (!$required) {
            return true;
        }

        $purpose = match ($channel) {
            'ga4' => 'statistics',
            'meta' => 'marketing',
            'brevo' => (string) $this->settings->get('brevo_consent_purpose', 'marketing'),
            default => 'marketing',
        };
        $purpose = (string) apply_filters('fp_tracking_server_side_consent_purpose', $purpose, $channel, $event_name, $params);

        if ($purpose === 'none') {
            return (bool) apply_filters('fp_tracking_server_side_has_consent', true, $event_name, $channel, $params);
        }

        $state = isset($params['fp_server_side_consent']) && is_array($params['fp_server_side_consent'])
            ? $params['fp_server_side_consent']
            : $this->current_consent_state();

        $allowed = !empty($state[$purpose]);

        return (bool) apply_filters('fp_tracking_server_side_has_consent', $allowed, $event_name, $channel, $params);
    }

    /**
     * Resolves current consent categories from FP Privacy when available.
     *
     * @return array{statistics:bool,marketing:bool,preferences:bool,necessary:bool}
     */
    private function current_consent_state(): array {
        $fallback = (string) $this->settings->get('consent_default', 'denied') === 'granted';
        $state = [
            'statistics' => $fallback,
            'marketing' => $fallback,
            'preferences' => false,
            'necessary' => true,
        ];

        if (class_exists('\FP\Privacy\Frontend\ConsentCookieManager') && class_exists('\FP\Privacy\Consent\LogModel')) {
            try {
                $cookie = \FP\Privacy\Frontend\ConsentCookieManager::get_cookie_payload();
                $consent_id = is_array($cookie) ? (string) ($cookie['id'] ?? '') : '';
                if ($consent_id === '') {
                    return ['statistics' => false, 'marketing' => false, 'preferences' => false, 'necessary' => true];
                }

                $record = (new \FP\Privacy\Consent\LogModel())->find_latest_by_consent_id($consent_id);
                if (!is_array($record)) {
                    return ['statistics' => false, 'marketing' => false, 'preferences' => false, 'necessary' => true];
                }

                $states = isset($record['states']) && is_array($record['states']) ? $record['states'] : [];
                foreach (['statistics', 'marketing', 'preferences', 'necessary'] as $purpose) {
                    if (array_key_exists($purpose, $states)) {
                        $state[$purpose] = !empty($states[$purpose]);
                    }
                }
            } catch (\Throwable) {
                return ['statistics' => false, 'marketing' => false, 'preferences' => false, 'necessary' => true];
            }
        }

        return $state;
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

    /**
     * Persists match keys needed by queued Meta CAPI dispatches.
     *
     * Queue workers run outside the original visitor request, so request-only
     * data such as IP, user agent and Meta cookies must be captured before the
     * event is stored.
     *
     * @param array<string, mixed> $params Event payload.
     * @return array<string, mixed> Event payload with enriched user_data.
     */
    private function with_server_side_user_data(array $params): array {
        $user_data = isset($params['user_data']) && is_array($params['user_data'])
            ? $params['user_data']
            : [];

        $user_data = $this->merge_missing_user_data($user_data, $this->request_match_user_data());

        $user_data = array_filter(
            $user_data,
            static fn($value): bool => $value !== null && $value !== ''
        );

        if ($user_data !== []) {
            $params['user_data'] = $user_data;
        }

        return $params;
    }

    /**
     * Builds user_data values available during the original HTTP request.
     *
     * @return array<string, string>
     */
    private function request_match_user_data(): array {
        $data = [];

        $ip = $this->current_request_ip();
        if ($ip !== '') {
            $data['client_ip_address'] = $ip;
        }

        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $data['client_user_agent'] = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']));
        }

        $attribution = UTMCookieHandler::get_current_attribution();
        $fbp = $this->cookie_value('_fbp') ?: (string) ($attribution['_fbp'] ?? '');
        if ($fbp !== '') {
            $data['fbp'] = sanitize_text_field($fbp);
        }

        $fbc = $this->cookie_value('_fbc') ?: (string) ($attribution['_fbc'] ?? '');
        if ($fbc === '') {
            $fbclid = isset($_GET['fbclid'])
                ? sanitize_text_field(wp_unslash((string) $_GET['fbclid']))
                : (string) ($attribution['fbclid'] ?? '');
            if ($fbclid !== '') {
                $captured_at = isset($attribution['captured_at']) ? (int) $attribution['captured_at'] : time();
                $fbc = 'fb.1.' . ($captured_at * 1000) . '.' . sanitize_text_field($fbclid);
            }
        }
        if ($fbc !== '') {
            $data['fbc'] = sanitize_text_field($fbc);
        }

        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user instanceof \WP_User) {
                $data['external_id'] = hash('sha256', (string) $user_id);
                if ($user->user_email !== '') {
                    $data['em'] = sanitize_email($user->user_email);
                }
                if ($user->first_name !== '') {
                    $data['fn'] = sanitize_text_field($user->first_name);
                }
                if ($user->last_name !== '') {
                    $data['ln'] = sanitize_text_field($user->last_name);
                }
            }
        }

        return $data;
    }

    /**
     * Merges fallback user_data without overriding caller-provided PII.
     *
     * @param array<string, mixed>  $user_data Existing payload user data.
     * @param array<string, string> $fallback  Request-derived fallback fields.
     * @return array<string, mixed>
     */
    private function merge_missing_user_data(array $user_data, array $fallback): array {
        foreach ($fallback as $key => $value) {
            if ($value !== '' && empty($user_data[$key])) {
                $user_data[$key] = $value;
            }
        }

        return $user_data;
    }

    /**
     * Returns the current visitor IP address.
     */
    private function current_request_ip(): string {
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }

        return (string) apply_filters('fp_tracking_client_ip_address', $ip);
    }

    /**
     * Reads and sanitizes a cookie value.
     */
    private function cookie_value(string $name): string {
        if (empty($_COOKIE[$name])) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $_COOKIE[$name]));
    }
}
