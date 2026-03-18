<?php

declare(strict_types=1);

namespace FPTracking\Validation;

use FPTracking\Catalog\EventCatalog;

final class EventValidator {

    private const WARNINGS_OPTION = 'fp_tracking_validation_warnings';
    private const MAX_WARNINGS = 200;

    /**
     * @param array<string,mixed> $params
     * @return array<int,string>
     */
    public function validate(string $event_name, array $params): array {
        $warnings = [];

        $requires = EventCatalog::required_fields_for($event_name);

        foreach ($requires as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                $warnings[] = sprintf('Campo richiesto mancante: %s', $field);
            }
        }

        if (($params['value'] ?? null) !== null && !is_numeric($params['value'])) {
            $warnings[] = 'Il campo value deve essere numerico.';
        }

        if ($warnings !== []) {
            $this->store_warnings($event_name, $warnings);
        }

        return $warnings;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_recent_warnings(int $limit = 20): array {
        $items = get_option(self::WARNINGS_OPTION, []);
        if (!is_array($items)) {
            return [];
        }
        return array_slice($items, 0, max(1, min($limit, self::MAX_WARNINGS)));
    }

    /**
     * @param array<int,string> $warnings
     */
    private function store_warnings(string $event_name, array $warnings): void {
        $existing = get_option(self::WARNINGS_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        array_unshift($existing, [
            'timestamp' => current_time('mysql'),
            'event' => sanitize_key($event_name),
            'warnings' => array_values(array_map('sanitize_text_field', $warnings)),
        ]);

        if (count($existing) > self::MAX_WARNINGS) {
            $existing = array_slice($existing, 0, self::MAX_WARNINGS);
        }

        update_option(self::WARNINGS_OPTION, $existing);
    }
}

