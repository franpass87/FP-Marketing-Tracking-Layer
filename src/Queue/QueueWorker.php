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

        try {
            $results = $this->dispatcher->dispatch_batch_with_result($jobs);
        } catch (\Throwable $e) {
            foreach ($jobs as $job) {
                $this->mark_failed_job($job, $e->getMessage());
            }
            return;
        }

        foreach ($jobs as $job) {
            $id = (int) $job['id'];

            $result = $results[$id] ?? [
                'ok' => false,
                'error' => 'Dispatch result missing',
            ];

            if (!empty($result['ok'])) {
                $this->queue->mark_sent($id);
                continue;
            }

            $this->mark_failed_job($job, (string) ($result['error'] ?? 'Dispatch failed'));
        }
    }

    /**
     * Marks a claimed queue job for retry or dead status.
     *
     * @param array{id:int,event_name:string,payload:array,attempts:int,max_attempts:int} $job Queue job.
     * @param string $error Error message to persist.
     * @return void
     */
    private function mark_failed_job(array $job, string $error): void {
        $id = (int) $job['id'];
        $attempts = (int) $job['attempts'] + 1;
        $maxAttempts = (int) $job['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $this->queue->mark_dead($id, $attempts, $error);
            return;
        }

        $delay = $this->retryPolicy->next_delay_seconds($attempts);
        $this->queue->mark_retry($id, $attempts, $delay, $error);
    }
}

