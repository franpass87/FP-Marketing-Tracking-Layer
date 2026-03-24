<?php

declare(strict_types=1);

namespace FPTracking\Brevo;

use FPTracking\Admin\Settings;

/**
 * Servizio per interrogare le liste Brevo (Contacts API).
 * Usato in admin per popolare i campi lista ITA/ENG.
 *
 * @see https://developers.brevo.com/reference/getlists
 */
final class BrevoListsService {

    private const API_BASE = 'https://api.brevo.com/v3';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Verifica se Brevo è configurato (API key presente).
     */
    public function is_configured(): bool {
        $key = self::normalize_api_key((string) $this->settings->get('brevo_api_key', ''));
        return $key !== '';
    }

    /**
     * Ottiene le liste disponibili da Brevo.
     *
     * @return array{success: bool, lists: array<int, array{id: int, name: string, total_subscribers: int}>, error: string|null}
     */
    public function get_lists(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'lists' => [],
                'error' => __('Brevo non configurato: inserisci la API Key.', 'fp-tracking'),
            ];
        }

        $url = self::API_BASE . '/contacts/lists';
        $apiKey = self::normalize_api_key((string) $this->settings->get('brevo_api_key', ''));
        if ($apiKey === '') {
            return [
                'success' => false,
                'lists' => [],
                'error' => __('Brevo API Key vuota o non valida (spazi/virgolette).', 'fp-tracking'),
            ];
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'api-key' => $apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'lists' => [],
                'error' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code >= 200 && $code < 300 && is_array($decoded) && isset($decoded['lists'])) {
            $lists = [];
            foreach ($decoded['lists'] as $list) {
                if (isset($list['id'])) {
                    $lists[] = [
                        'id' => (int) $list['id'],
                        'name' => (string) ($list['name'] ?? ''),
                        'total_subscribers' => (int) ($list['totalSubscribers'] ?? 0),
                    ];
                }
            }
            return [
                'success' => true,
                'lists' => $lists,
                'error' => null,
            ];
        }

        $errorMsg = is_array($decoded) ? ($decoded['message'] ?? $decoded['code'] ?? '') : '';
        if ($code === 401) {
            $errorMsg = __('Key not found. Usa una API key Brevo valida da SMTP & API > API Keys (non SMTP relay password).', 'fp-tracking');
        }
        return [
            'success' => false,
            'lists' => [],
            'error' => sprintf('HTTP %d: %s', $code, $errorMsg ?: $body),
        ];
    }

    /**
     * Testa la connessione all'API Brevo (endpoint /account).
     *
     * @return array{success: bool, message: string, account: array|null}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Brevo non configurato: inserisci la API Key.', 'fp-tracking'),
                'account' => null,
            ];
        }

        $url = self::API_BASE . '/account';
        $apiKey = self::normalize_api_key((string) $this->settings->get('brevo_api_key', ''));
        if ($apiKey === '') {
            return [
                'success' => false,
                'message' => __('Brevo API Key vuota o non valida (spazi/virgolette).', 'fp-tracking'),
                'account' => null,
            ];
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'api-key' => $apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'account' => null,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code >= 200 && $code < 300 && is_array($decoded)) {
            $company = (string) ($decoded['companyName'] ?? $decoded['email'] ?? 'Unknown');
            $plan = isset($decoded['plan'][0]['type']) ? (string) $decoded['plan'][0]['type'] : 'Free';

            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %1$s: company name, %2$s: plan type */
                    __('Connesso! Account: %1$s (%2$s)', 'fp-tracking'),
                    $company,
                    $plan
                ),
                'account' => [
                    'email' => (string) ($decoded['email'] ?? ''),
                    'company' => (string) ($decoded['companyName'] ?? ''),
                    'plan' => $plan,
                ],
            ];
        }

        $errorMsg = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['code'] ?? '') : '';
        if ($errorMsg === '') {
            $bodySnippet = is_string($body) ? trim(wp_strip_all_tags($body)) : '';
            if ($bodySnippet !== '') {
                $errorMsg = substr($bodySnippet, 0, 240);
            } else {
                $errorMsg = __('Risposta Brevo vuota o non valida.', 'fp-tracking');
            }
        }
        if ($code === 401) {
            $errorMsg = __('Key not found. Verifica di aver copiato una API key Brevo attiva (SMTP & API > API Keys) senza spazi o virgolette.', 'fp-tracking');
        }
        return [
            'success' => false,
            'message' => sprintf('HTTP %d: %s', $code, $errorMsg ?: $body),
            'account' => null,
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
