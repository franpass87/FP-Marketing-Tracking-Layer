<?php

declare(strict_types=1);

namespace FPTracking\Health;

use FPTracking\Queue\EventQueueRepository;

final class EventHealthService {

    public function __construct(private readonly EventQueueRepository $queue) {}

    /**
     * @return array{pending:int,processing:int,failed:int,dead:int,sent_24h:int,failed_24h:int}
     */
    public function get_queue_stats(): array {
        return $this->queue->stats();
    }

    public function run_heartbeat_check(): void {
        $stats = $this->get_queue_stats();
        $failed = (int) ($stats['failed'] ?? 0);
        $dead = (int) ($stats['dead'] ?? 0);

        if (($failed + $dead) < 25) {
            return;
        }

        $to = get_option('admin_email');
        if (!is_string($to) || $to === '') {
            return;
        }

        $subject = '[FP Tracking] Queue alert';
        $message = sprintf(
            "Queue health warning:\n- failed: %d\n- dead: %d\n- pending: %d\n- sent_24h: %d\n- failed_24h: %d",
            $failed,
            $dead,
            (int) ($stats['pending'] ?? 0),
            (int) ($stats['sent_24h'] ?? 0),
            (int) ($stats['failed_24h'] ?? 0)
        );

        wp_mail($to, $subject, $message);
    }
}

