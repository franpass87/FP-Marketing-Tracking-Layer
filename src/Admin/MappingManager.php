<?php

declare(strict_types=1);

namespace FPTracking\Admin;

final class MappingManager {

    private const EXPORT_KEYS = [
        'fp_tracking_event_rules',
        'fp_tracking_brevo_mapping',
        'fp_tracking_brevo_enabled_events',
        'fp_tracking_validation_warnings',
    ];

    public function export_json(): string {
        $payload = [
            'version' => 1,
            'exported_at' => gmdate('c'),
            'data' => [],
        ];

        foreach (self::EXPORT_KEYS as $key) {
            $payload['data'][$key] = get_option($key);
        }

        return (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function import_json(string $json): bool {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return false;
        }

        foreach (self::EXPORT_KEYS as $key) {
            if (array_key_exists($key, $decoded['data'])) {
                update_option($key, $decoded['data'][$key]);
            }
        }

        return true;
    }
}

