<?php

declare(strict_types=1);

namespace FPTracking\Brevo;

use FPTracking\Admin\Settings;

final class BrevoClient {

    public function __construct(private readonly Settings $settings) {}

    public function is_enabled(): bool {
        return (bool) $this->settings->get('brevo_enabled', false)
            && self::normalize_api_key((string) $this->settings->get('brevo_api_key', '')) !== '';
    }

    /**
     * @param array<string,mixed> $identifiers
     * @param array<string,mixed> $event_properties
     * @param array<string,mixed> $contact_properties
     */
    public function send_event(
        string $event_name,
        array $identifiers,
        array $event_properties = [],
        array $contact_properties = []
    ): bool {
        if (!$this->is_enabled()) {
            return false;
        }

        $endpoint = (string) $this->settings->get('brevo_endpoint', 'https://api.brevo.com/v3/events');
        $apiKey = self::normalize_api_key((string) $this->settings->get('brevo_api_key', ''));
        if ($apiKey === '') {
            return false;
        }

        $body = [
            'event_name' => $event_name,
            'identifiers' => $identifiers,
            'event_properties' => $event_properties,
        ];
        if ($contact_properties !== []) {
            $body['contact_properties'] = $contact_properties;
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'api-key' => $apiKey,
            ],
            'timeout' => 7,
            'blocking' => true,
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /**
     * Normalizza la API key rimuovendo spazi e virgolette accidentali.
     */
    private static function normalize_api_key(string $apiKey): string {
        $normalized = trim($apiKey);
        return trim($normalized, "\"' \t\n\r\0\x0B");
    }
}

