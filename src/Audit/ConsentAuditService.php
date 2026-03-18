<?php

declare(strict_types=1);

namespace FPTracking\Audit;

final class ConsentAuditService {

    private const OPTION_KEY = 'fp_tracking_consent_audit';

    /**
     * @param array<string,bool> $states
     */
    public function record_update(array $states, string $event, int $revision): void {
        $data = get_option(self::OPTION_KEY, [
            'total_updates' => 0,
            'events' => [],
            'purposes' => [],
            'last_revision' => 0,
            'last_update_at' => '',
        ]);
        if (!is_array($data)) {
            $data = [];
        }

        $data['total_updates'] = (int) ($data['total_updates'] ?? 0) + 1;

        $events = (array) ($data['events'] ?? []);
        $eventKey = sanitize_key($event);
        $events[$eventKey] = (int) ($events[$eventKey] ?? 0) + 1;
        $data['events'] = $events;

        $purposes = (array) ($data['purposes'] ?? []);
        foreach ($states as $purpose => $granted) {
            $k = sanitize_key((string) $purpose);
            if (!isset($purposes[$k])) {
                $purposes[$k] = ['granted' => 0, 'denied' => 0];
            }
            if ($granted) {
                $purposes[$k]['granted'] = (int) ($purposes[$k]['granted'] ?? 0) + 1;
            } else {
                $purposes[$k]['denied'] = (int) ($purposes[$k]['denied'] ?? 0) + 1;
            }
        }
        $data['purposes'] = $purposes;

        $data['last_revision'] = $revision;
        $data['last_update_at'] = current_time('mysql');

        update_option(self::OPTION_KEY, $data);
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array {
        $data = get_option(self::OPTION_KEY, []);
        return is_array($data) ? $data : [];
    }
}

