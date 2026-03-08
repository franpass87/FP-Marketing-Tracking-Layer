<?php

namespace FPTracking\ServerSide;

use FPTracking\Admin\Settings;

/**
 * Sends events to Meta Conversions API v21.0.
 * Unifies the CAPI code previously in FP-Restaurant-Reservations (v18)
 * and FP-Forms (v21), standardizing on v21.
 */
final class MetaConversionsAPI {

    private const API_VERSION = 'v21.0';
    private const ENDPOINT    = 'https://graph.facebook.com';

    public function __construct(private readonly Settings $settings) {}

    public function is_enabled(): bool {
        return !empty($this->settings->get('meta_pixel_id'))
            && !empty($this->settings->get('meta_access_token'))
            && (bool) $this->settings->get('server_side_meta', true);
    }

    /**
     * Sends a single event to Meta Conversions API.
     *
     * @param string $event_name      Meta standard or custom event name (e.g. 'Purchase', 'Lead')
     * @param array  $custom_data     Event-specific data (value, currency, contents, etc.)
     * @param array  $user_data       Raw user data (will be hashed automatically)
     * @param string $event_source_url Page URL where the event occurred
     * @param string $event_id        Deduplication ID (shared with client-side fbq)
     * @return bool
     */
    public function send(
        string $event_name,
        array  $custom_data,
        array  $user_data,
        string $event_source_url = '',
        string $event_id = ''
    ): bool {
        if (!$this->is_enabled()) {
            return false;
        }

        $pixel_id = $this->settings->get('meta_pixel_id');
        $url = sprintf(
            '%s/%s/%s/events?access_token=%s',
            self::ENDPOINT,
            self::API_VERSION,
            $pixel_id,
            $this->settings->get('meta_access_token')
        );

        $event = [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_source_url' => $event_source_url ?: home_url(add_query_arg(null, null)),
            'action_source'    => 'website',
            'user_data'        => $this->hash_user_data($user_data),
            'custom_data'      => $custom_data,
        ];

        if (!empty($event_id)) {
            $event['event_id'] = $event_id;
        }

        $body = ['data' => [$event]];

        if ($this->settings->get('debug_mode', false)) {
            $body['test_event_code'] = apply_filters('fp_tracking_meta_test_event_code', '');
        }

        $response = wp_remote_post($url, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($body),
            'timeout'     => 5,
            'blocking'    => false,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            if ($this->settings->get('debug_mode', false)) {
                error_log('[FP Tracking] Meta CAPI error: ' . $response->get_error_message());
            }
            return false;
        }

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
                $value = strtolower(trim($user_data[$field]));
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
}
