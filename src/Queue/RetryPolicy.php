<?php

declare(strict_types=1);

namespace FPTracking\Queue;

final class RetryPolicy {

    /**
     * @var array<int, int>
     */
    private const BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 3600,
        5 => 21600,
    ];

    public function next_delay_seconds(int $attempt): int {
        if ($attempt <= 0) {
            return self::BACKOFF_SECONDS[1];
        }

        return self::BACKOFF_SECONDS[$attempt] ?? 21600;
    }
}

