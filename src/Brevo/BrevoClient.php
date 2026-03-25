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
     * POST /v3/contacts (create/update) con la stessa API key del layer.
     *
     * @param array<string,mixed> $body Corpo Brevo: email, updateEnabled, attributes, listIds, tags, …
     *
     * @return array{success:bool,code:int,message:string,contact_id:int|null}
     */
    public function upsert_contact(array $body): array {
        if (!$this->is_enabled()) {
            return [
                'success' => false,
                'code' => 0,
                'message' => 'Brevo disabled in FP Tracking',
                'contact_id' => null,
            ];
        }

        $apiKey = self::normalize_api_key((string) $this->settings->get('brevo_api_key', ''));
        if ($apiKey === '') {
            return [
                'success' => false,
                'code' => 0,
                'message' => 'Missing Brevo API key',
                'contact_id' => null,
            ];
        }

        $merged = array_merge(['updateEnabled' => true], $body);

        $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'api-key' => $apiKey,
            ],
            'timeout' => 15,
            'blocking' => true,
            'body' => wp_json_encode($merged),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'code' => 0,
                'message' => $response->get_error_message(),
                'contact_id' => null,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $contactId = null;
        if (is_array($decoded) && isset($decoded['id'])) {
            $contactId = is_int($decoded['id']) ? $decoded['id'] : (int) $decoded['id'];
        }

        return [
            'success' => $code >= 200 && $code < 300,
            'code' => $code,
            'message' => $raw,
            'contact_id' => $contactId > 0 ? $contactId : null,
        ];
    }

    /**
     * Normalizza la API key rimuovendo spazi e virgolette accidentali.
     */
    private static function normalize_api_key(string $apiKey): string {
        $normalized = trim($apiKey);
        return trim($normalized, "\"' \t\n\r\0\x0B");
    }
}

