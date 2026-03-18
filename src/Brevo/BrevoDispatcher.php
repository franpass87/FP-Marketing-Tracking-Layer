<?php

declare(strict_types=1);

namespace FPTracking\Brevo;

use FPTracking\Admin\Settings;

final class BrevoDispatcher {

    public function __construct(
        private readonly Settings $settings,
        private readonly BrevoClient $client,
        private readonly BrevoMapper $mapper
    ) {}

    public function is_enabled(): bool {
        return $this->client->is_enabled();
    }

    /**
     * @param array<string,mixed> $params
     */
    public function dispatch(string $event_name, array $params): bool {
        if (!$this->is_enabled()) {
            return false;
        }

        if (!$this->is_event_enabled($event_name)) {
            return true;
        }

        $mapped = $this->mapper->map($event_name, $params);
        if (!empty($mapped['skip'])) {
            return true;
        }

        return $this->client->send_event(
            $mapped['event_name'],
            $mapped['identifiers'],
            $mapped['event_properties'],
            $mapped['contact_properties']
        );
    }

    private function is_event_enabled(string $event_name): bool {
        $saved = get_option('fp_tracking_brevo_enabled_events', []);
        if (!is_array($saved) || $saved === []) {
            return true;
        }
        return in_array($event_name, array_map('strval', $saved), true);
    }
}

