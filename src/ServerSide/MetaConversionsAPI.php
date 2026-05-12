<?php
declare(strict_types=1);

namespace FPTracking\ServerSide;

use FPTracking\Admin\Settings;
use FPTracking\Inspector\MetaOutcomeHistory;

/**
 * Sends events to Meta Conversions API v21.0.
 * Unifies the CAPI code previously in FP-Restaurant-Reservations (v18)
 * and FP-Forms (v21), standardizing on v21.
 */
final class MetaConversionsAPI {

    private const API_VERSION = 'v21.0';
    private const ENDPOINT    = 'https://graph.facebook.com';
    private string $last_error = '';
    private MetaOutcomeHistory $outcomes;

    public function __construct(private readonly Settings $settings) {
        $this->outcomes = new MetaOutcomeHistory();
    }

    public function is_enabled(): bool {
        return !empty($this->settings->get('meta_pixel_id'))
            && !empty($this->settings->get('meta_access_token'))
            && (bool) $this->settings->get('server_side_meta', true);
    }

    /**
     * Returns the last transport or API error produced by send().
     */
    public function last_error(): string {
        return $this->last_error;
    }

    /**
     * Sends a single event to Meta Conversions API.
     *
     * @param string $event_name      Meta standard event name (e.g. 'Purchase', 'Lead')
     * @param array  $custom_data     Event-specific data (value, currency, contents, etc.)
     * @param array  $user_data       Raw user data (will be hashed automatically)
     * @param string $event_source_url Page URL where the event occurred (required for website events)
     * @param string $event_id        Deduplication ID — must match the eventID sent by fbq() client-side
     * @return bool
     */
    public function send(
        string $event_name,
        array  $custom_data,
        array  $user_data,
        string $event_source_url = '',
        string $event_id = ''
    ): bool {
        return $this->send_batch([
            $this->build_event($event_name, $custom_data, $user_data, $event_source_url, $event_id),
        ]);
    }

    /**
     * Builds a Meta CAPI event payload.
     *
     * @param string $event_name       Meta standard event name.
     * @param array  $custom_data      Event-specific custom data.
     * @param array  $user_data        Raw user data, hashed before being returned.
     * @param string $event_source_url Source URL for website events.
     * @param string $event_id         Deduplication ID shared with the browser Pixel.
     * @return array<string,mixed>
     */
    public function build_event(
        string $event_name,
        array $custom_data,
        array $user_data,
        string $event_source_url = '',
        string $event_id = ''
    ): array {
        // event_source_url is required for website action_source
        $source_url = $event_source_url
            ?: (isset($_SERVER['HTTP_REFERER']) ? sanitize_url(wp_unslash($_SERVER['HTTP_REFERER'])) : '')
            ?: home_url(add_query_arg(null, null));

        $event = [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_source_url' => $source_url,
            'action_source'    => 'website',
            'user_data'        => $this->hash_user_data($user_data),
            'custom_data'      => $custom_data,
        ];

        // event_id enables deduplication with client-side fbq() — always include when available
        if (!empty($event_id)) {
            $event['event_id'] = $event_id;
        }

        return $event;
    }

    /**
     * Sends multiple events to Meta Conversions API in one request.
     *
     * @param array<int,array<string,mixed>> $events Prepared Meta CAPI events.
     * @return bool
     */
    public function send_batch(array $events): bool {
        $this->last_error = '';

        if (!$this->is_enabled()) {
            $this->last_error = 'Meta CAPI is disabled or not configured';
            $this->record_outcome(false, 0, [], null, $this->last_error);
            return false;
        }

        $events = array_values(array_filter($events, 'is_array'));
        if ($events === []) {
            $this->last_error = 'Meta CAPI batch is empty';
            $this->record_outcome(false, 0, [], null, $this->last_error);
            return false;
        }

        $pixel_id = $this->settings->get('meta_pixel_id');
        $url = add_query_arg(
            ['access_token' => $this->settings->get('meta_access_token')],
            sprintf('%s/%s/%s/events', self::ENDPOINT, self::API_VERSION, $pixel_id)
        );

        $body = ['data' => $events];

        // test_event_code: only add when explicitly set (empty string would cause API errors).
        $test_code = (string) $this->settings->get('meta_test_event_code', '');
        $test_code = (string) apply_filters('fp_tracking_meta_test_event_code', $test_code);
        if ($test_code !== '') {
            $body['test_event_code'] = sanitize_text_field($test_code);
        }

        $response = wp_remote_post($url, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($body),
            'timeout'     => 10,
            'blocking'    => true,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            $this->last_error = 'Transport error: ' . $response->get_error_message();
            $this->record_outcome(false, 0, $events, null, $this->last_error, $test_code);
            $this->debug_log($this->last_error);
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($status < 200 || $status >= 300) {
            $this->last_error = 'HTTP ' . $status . ': ' . $this->extract_api_error($decoded, $raw_body);
            $this->record_outcome(false, $status, $events, $decoded, $this->last_error, $test_code);
            $this->debug_log($this->last_error);
            return false;
        }

        if (is_array($decoded) && isset($decoded['error'])) {
            $this->last_error = $this->extract_api_error($decoded, $raw_body);
            $this->record_outcome(false, $status, $events, $decoded, $this->last_error, $test_code);
            $this->debug_log($this->last_error);
            return false;
        }

        if (is_array($decoded) && isset($decoded['events_received']) && (int) $decoded['events_received'] < count($events)) {
            $this->last_error = sprintf(
                'Meta accepted the request but reported %d/%d events_received',
                (int) $decoded['events_received'],
                count($events)
            );
            $this->record_outcome(false, $status, $events, $decoded, $this->last_error, $test_code);
            $this->debug_log($this->last_error);
            return false;
        }

        $this->record_outcome(true, $status, $events, $decoded, '', $test_code);

        return true;
    }

    /**
     * Hashes user data fields with SHA256 as required by Meta CAPI.
     */
    private function hash_user_data(array $user_data): array {
        $hashed = [];

        $hash_fields = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country'];
        foreach ($hash_fields as $field) {
            if (!empty($user_data[$field])) {
                $value = $this->normalize_hash_value($field, (string) $user_data[$field]);
                if ($value === '') {
                    continue;
                }
                $hashed[$field] = hash('sha256', $value);
            }
        }

        // Pass-through fields (already hashed or non-PII)
        foreach (['fbc', 'fbp', 'client_ip_address', 'client_user_agent', 'external_id'] as $field) {
            if (!empty($user_data[$field])) {
                $hashed[$field] = $user_data[$field];
            }
        }

        // Auto-populate IP and user agent if not provided
        if (empty($hashed['client_ip_address']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $hashed['client_ip_address'] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        if (empty($hashed['client_user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
            $hashed['client_user_agent'] = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }

        // Auto-populate fbp/fbc from cookies
        if (empty($hashed['fbp']) && !empty($_COOKIE['_fbp'])) {
            $hashed['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }
        if (empty($hashed['fbc']) && !empty($_COOKIE['_fbc'])) {
            $hashed['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }

        return $hashed;
    }

    /**
     * Normalizes user_data values before SHA-256 hashing.
     */
    private function normalize_hash_value(string $field, string $value): string {
        $value = strtolower(trim($value));

        if ($field === 'em') {
            return sanitize_email($value);
        }

        if ($field === 'ph') {
            $phone = preg_replace('/[^0-9+]/', '', $value);
            return is_string($phone) ? $phone : '';
        }

        if ($field === 'country') {
            return substr(preg_replace('/[^a-z]/', '', $value) ?: '', 0, 2);
        }

        $value = remove_accents($value);
        $value = preg_replace('/\s+/', '', $value);
        $value = preg_replace('/[^a-z0-9]/', '', is_string($value) ? $value : '');

        return is_string($value) ? $value : '';
    }

    /**
     * Extracts a safe error message from Meta API responses.
     */
    private function extract_api_error(mixed $decoded, string $raw_body): string {
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $message = sanitize_text_field((string) ($decoded['error']['message'] ?? 'Meta API error'));
            $type = sanitize_text_field((string) ($decoded['error']['type'] ?? ''));
            $code = sanitize_text_field((string) ($decoded['error']['code'] ?? ''));
            return trim($message . ($type !== '' ? ' [' . $type . ']' : '') . ($code !== '' ? ' code ' . $code : ''));
        }

        return substr(sanitize_text_field($raw_body !== '' ? $raw_body : 'Empty Meta API response'), 0, 300);
    }

    /**
     * Stores a compact Meta CAPI outcome for troubleshooting.
     *
     * @param array<int,array<string,mixed>> $events Sent Meta events.
     * @param mixed $decoded Decoded Meta response.
     * @return void
     */
    private function record_outcome(bool $ok, int $http_status, array $events, mixed $decoded, string $error = '', string $test_code = ''): void {
        $this->outcomes->record($ok, $http_status, $events, $decoded, $error, $test_code);
    }

    /**
     * Logs Meta CAPI failures only when plugin debug is enabled.
     */
    private function debug_log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG && $this->settings->get('debug_mode', false) && function_exists('error_log')) {
            error_log('[FP Tracking] Meta CAPI error: ' . $message);
        }
    }
}
