<?php

declare(strict_types=1);

namespace FPTracking\Queue;

use FPTracking\ServerSide\ServerSideDispatcher;

final class QueueWorker {

    public function __construct(
        private readonly EventQueueRepository $queue,
        private readonly ServerSideDispatcher $dispatcher,
        private readonly RetryPolicy $retryPolicy
    ) {}

    public function run(int $batch_size = 25): void {
        $jobs = $this->queue->claim_batch($batch_size);
        if ($jobs === []) {
            return;
        }

        foreach ($jobs as $job) {
            $id = (int) $job['id'];
            $attempts = (int) $job['attempts'] + 1;
            $maxAttempts = (int) $job['max_attempts'];
            $eventName = (string) $job['event_name'];
            $payload = (array) $job['payload'];

            try {
                $result = $this->dispatcher->dispatch_with_result($eventName, $payload);
                if ($result['ok']) {
                    $this->queue->mark_sent($id);
                    continue;
                }

                $error = (string) ($result['error'] ?? 'Dispatch failed');
                if ($attempts >= $maxAttempts) {
                    $this->queue->mark_dead($id, $attempts, $error);
                    continue;
                }

                $delay = $this->retryPolicy->next_delay_seconds($attempts);
                $this->queue->mark_retry($id, $attempts, $delay, $error);
            } catch (\Throwable $e) {
                if ($attempts >= $maxAttempts) {
                    $this->queue->mark_dead($id, $attempts, $e->getMessage());
                    continue;
                }

                $delay = $this->retryPolicy->next_delay_seconds($attempts);
                $this->queue->mark_retry($id, $attempts, $delay, $e->getMessage());
            }
        }
    }
}

