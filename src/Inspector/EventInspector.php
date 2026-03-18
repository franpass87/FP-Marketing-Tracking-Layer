<?php

declare(strict_types=1);

namespace FPTracking\Inspector;

final class EventInspector {

    private const OPTION_KEY = 'fp_tracking_event_inspector';
    private const MAX_ITEMS = 300;

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $warnings
     */
    public function record(string $event_name, array $payload, array $warnings = [], int $sample_rate = 10): void {
        $sample_rate = max(1, min($sample_rate, 100));
        if (random_int(1, 100) > $sample_rate) {
            return;
        }

        $items = get_option(self::OPTION_KEY, []);
        if (!is_array($items)) {
            $items = [];
        }

        array_unshift($items, [
            'timestamp' => current_time('mysql'),
            'event' => sanitize_key($event_name),
            'payload' => $this->sanitize_payload($payload),
            'warnings' => array_values(array_map('sanitize_text_field', $warnings)),
        ]);

        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }

        update_option(self::OPTION_KEY, $items);
    }

    /**
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
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitize_payload(array $payload): array {
        if (isset($payload['user_data']) && is_array($payload['user_data'])) {
            $masked = [];
            foreach ($payload['user_data'] as $k => $v) {
                $masked[sanitize_key((string) $k)] = '[masked]';
            }
            $payload['user_data'] = $masked;
        }
        return $payload;
    }
}

