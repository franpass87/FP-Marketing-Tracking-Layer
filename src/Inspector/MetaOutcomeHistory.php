<?php

declare(strict_types=1);

namespace FPTracking\Inspector;

/**
 * Stores recent Meta CAPI request outcomes for admin diagnostics.
 */
final class MetaOutcomeHistory {

    private const OPTION_KEY = 'fp_tracking_meta_capi_outcomes';
    private const MAX_ITEMS = 100;

    /**
     * Records a sanitized Meta CAPI outcome without raw payloads or tokens.
     *
     * @param array<int,array<string,mixed>> $events Sent Meta events.
     * @param mixed $decoded Decoded Meta API response body.
     * @return void
     */
    public function record(bool $ok, int $http_status, array $events, mixed $decoded = null, string $error = '', string $test_event_code = ''): void {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            $items = [];
        }

        array_unshift($items, [
            'timestamp' => current_time('mysql'),
            'ok' => $ok,
            'http_status' => $http_status,
            'events_count' => count($events),
            'events_received' => $this->events_received($decoded, $ok ? count($events) : 0),
            'event_names' => $this->event_names($events),
            'test_event_code' => $test_event_code !== '' ? sanitize_text_field($test_event_code) : '',
            'error' => $error !== '' ? substr(sanitize_text_field($error), 0, 300) : '',
            'fbtrace_id' => $this->fbtrace_id($decoded),
        ]);

        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }

        update_option(self::OPTION_KEY, $items);
    }

    /**
     * Returns recent Meta CAPI outcomes for admin display.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array {
        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            return [];
        }

        return array_slice($items, 0, max(1, min($limit, self::MAX_ITEMS)));
    }

    /**
     * @param mixed $decoded Decoded Meta response.
     */
    private function events_received(mixed $decoded, int $fallback): int {
        if (is_array($decoded) && isset($decoded['events_received'])) {
            return max(0, (int) $decoded['events_received']);
        }

        return max(0, $fallback);
    }

    /**
     * @param array<int,array<string,mixed>> $events Sent Meta events.
     * @return list<string>
     */
    private function event_names(array $events): array {
        $names = [];

        foreach ($events as $event) {
            $name = isset($event['event_name']) ? sanitize_text_field((string) $event['event_name']) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param mixed $decoded Decoded Meta response.
     */
    private function fbtrace_id(mixed $decoded): string {
        if (is_array($decoded) && isset($decoded['fbtrace_id'])) {
            return sanitize_text_field((string) $decoded['fbtrace_id']);
        }

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            return sanitize_text_field((string) ($decoded['error']['fbtrace_id'] ?? ''));
        }

        return '';
    }
}
