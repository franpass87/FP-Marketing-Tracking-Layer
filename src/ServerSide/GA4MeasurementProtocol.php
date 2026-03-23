<?php

namespace FPTracking\ServerSide;

use FPTracking\Admin\Settings;

/**
 * Sends events to GA4 via Measurement Protocol.
 * Unifies the server-side GA4 code previously duplicated in
 * FP-Restaurant-Reservations and FP-Forms.
 */
final class GA4MeasurementProtocol {

    private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';
    private const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    public function __construct(private readonly Settings $settings) {}

    public function is_enabled(): bool {
        return !empty($this->settings->get('ga4_measurement_id'))
            && !empty($this->settings->get('ga4_api_secret'))
            && (bool) $this->settings->get('server_side_ga4', true);
    }

    /**
     * Sends a single event to GA4 Measurement Protocol.
     *
     * @param string $event_name   GA4 event name
     * @param array  $params       Event parameters
     * @param string $client_id    GA4 client_id (from _ga cookie)
     * @param string $event_id     Deduplication event ID (shared with client-side)
     * @param array  $user_data    Optional user properties
     * @return bool
     */
    public function send(
        string $event_name,
        array  $params,
        string $client_id,
        string $event_id = '',
        array  $user_data = []
    ): bool {
        if (!$this->is_enabled()) {
            return false;
        }

        if (!empty($event_id)) {
            $params['event_id'] = $event_id;
        }

        $body = [
            'client_id' => $client_id,
            'events'    => [
                [
                    'name'   => $event_name,
                    'params' => $params,
                ],
            ],
        ];

        if (!empty($user_data)) {
            $body['user_properties'] = $user_data;
        }

        $endpoint = $this->settings->get('debug_mode', false)
            ? self::DEBUG_ENDPOINT
            : self::ENDPOINT;

        $url = add_query_arg([
            'measurement_id' => $this->settings->get('ga4_measurement_id'),
            'api_secret'     => $this->settings->get('ga4_api_secret'),
        ], $endpoint);

        $response = wp_remote_post($url, [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($body),
            'timeout'     => 5,
            'blocking'    => false,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG && $this->settings->get('debug_mode', false) && function_exists('error_log')) {
                error_log('[FP Tracking] GA4 MP error: ' . $response->get_error_message());
            }
            return false;
        }

        return true;
    }

    /**
     * Extracts GA4 client_id from the _ga cookie.
     */
    public static function extract_client_id(): string {
        if (!empty($_COOKIE['_ga'])) {
            $parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_ga'])));
            if (count($parts) >= 4) {
                return $parts[2] . '.' . $parts[3];
            }
        }
        return 'unknown.' . time();
    }
}
