<?php

declare(strict_types=1);

namespace FPTracking\Queue;

use wpdb;

final class EventQueueRepository {

    private const TABLE_SUFFIX = 'fp_tracking_event_queue';
    private const SCHEMA_VERSION = '1';
    private const SCHEMA_OPTION = 'fp_tracking_queue_schema_version';

    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function table_name(): string {
        return $this->db->prefix . self::TABLE_SUFFIX;
    }

    public function ensure_schema(): void {
        $current = (string) get_option(self::SCHEMA_OPTION, '');
        if ($current === self::SCHEMA_VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table_name();
        $charset = $this->db->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(100) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            next_attempt_at DATETIME NOT NULL,
            worker_token VARCHAR(64) DEFAULT NULL,
            locked_at DATETIME DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_status_next_attempt (status, next_attempt_at),
            KEY idx_worker_token (worker_token)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    public function enqueue(string $event_name, array $payload, int $max_attempts = 5): bool {
        $this->ensure_schema();
        $now = current_time('mysql');

        return (bool) $this->db->insert(
            $this->table_name(),
            [
                'event_name'      => sanitize_text_field($event_name),
                'payload'         => wp_json_encode($payload),
                'status'          => 'pending',
                'attempts'        => 0,
                'max_attempts'    => max(1, $max_attempts),
                'next_attempt_at' => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<int, array{id:int,event_name:string,payload:array,attempts:int,max_attempts:int}>
     */
    public function claim_batch(int $limit = 25): array {
        $this->ensure_schema();
        $limit = max(1, min($limit, 100));
        $token = wp_generate_uuid4();
        $table = $this->table_name();
        $now = current_time('mysql');

        $this->release_stuck(15 * MINUTE_IN_SECONDS);

        $sql = "UPDATE {$table}
                SET status = 'processing', worker_token = %s, locked_at = %s, updated_at = %s
                WHERE status IN ('pending','failed')
                  AND next_attempt_at <= %s
                  AND (worker_token IS NULL OR worker_token = '')
                ORDER BY id ASC
                LIMIT {$limit}";
        $this->db->query($this->db->prepare($sql, $token, $now, $now, $now));

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id, event_name, payload, attempts, max_attempts
                 FROM {$table}
                 WHERE worker_token = %s AND status = 'processing'
                 ORDER BY id ASC",
                $token
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (array $row): ?array {
                $decoded = json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($decoded)) {
                    $decoded = [];
                }

                return [
                    'id'           => (int) $row['id'],
                    'event_name'   => (string) $row['event_name'],
                    'payload'      => $decoded,
                    'attempts'     => (int) $row['attempts'],
                    'max_attempts' => (int) $row['max_attempts'],
                ];
            },
            $rows
        )));
    }

    public function mark_sent(int $id): void {
        $this->update_status($id, 'sent', null, null, true);
    }

    public function mark_retry(int $id, int $attempts, int $next_delay_seconds, string $error_message): void {
        $next = gmdate('Y-m-d H:i:s', time() + max(60, $next_delay_seconds));
        $this->update_status($id, 'failed', $attempts, $next, true, $error_message);
    }

    public function mark_dead(int $id, int $attempts, string $error_message): void {
        $this->update_status($id, 'dead', $attempts, null, true, $error_message);
    }

    public function retry_failed(int $limit = 200): int {
        $this->ensure_schema();
        $table = $this->table_name();
        $now = current_time('mysql');
        $limit = max(1, min($limit, 1000));

        $sql = "UPDATE {$table}
                SET status='pending', worker_token=NULL, locked_at=NULL, next_attempt_at=%s, updated_at=%s
                WHERE status IN ('failed','dead')
                ORDER BY id DESC
                LIMIT {$limit}";

        $result = $this->db->query($this->db->prepare($sql, $now, $now));
        return is_int($result) ? $result : 0;
    }

    public function release_stuck(int $older_than_seconds): void {
        $this->ensure_schema();
        $table = $this->table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(60, $older_than_seconds));
        $now = current_time('mysql');

        $sql = "UPDATE {$table}
                SET status='pending', worker_token=NULL, locked_at=NULL, next_attempt_at=%s, updated_at=%s
                WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < %s";
        $this->db->query($this->db->prepare($sql, $now, $now, $cutoff));
    }

    /**
     * @return array{pending:int,processing:int,failed:int,dead:int,sent_24h:int,failed_24h:int,sent_7d:int,failed_7d:int}
     */
    public function stats(): array {
        $this->ensure_schema();
        $table = $this->table_name();
        $nowMinus24 = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $rows = $this->db->get_results(
            "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $stats = [
            'pending'    => 0,
            'processing' => 0,
            'failed'     => 0,
            'dead'       => 0,
            'sent_24h'   => 0,
            'failed_24h' => 0,
            'sent_7d'    => 0,
            'failed_7d'  => 0,
        ];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? '');
                if (isset($stats[$status])) {
                    $stats[$status] = (int) ($row['c'] ?? 0);
                }
            }
        }

        $sent24 = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status='sent' AND updated_at >= %s",
                $nowMinus24
            )
        );
        $failed24 = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status IN ('failed','dead') AND updated_at >= %s",
                $nowMinus24
            )
        );
        $nowMinus7d = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $sent7d = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status='sent' AND updated_at >= %s",
                $nowMinus7d
            )
        );
        $failed7d = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status IN ('failed','dead') AND updated_at >= %s",
                $nowMinus7d
            )
        );
        $stats['sent_24h'] = (int) $sent24;
        $stats['failed_24h'] = (int) $failed24;
        $stats['sent_7d'] = (int) $sent7d;
        $stats['failed_7d'] = (int) $failed7d;

        return $stats;
    }

    private function update_status(
        int $id,
        string $status,
        ?int $attempts = null,
        ?string $next_attempt = null,
        bool $clear_worker = false,
        string $error_message = ''
    ): void {
        $data = [
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ];
        $format = ['%s', '%s'];

        if ($attempts !== null) {
            $data['attempts'] = max(0, $attempts);
            $format[] = '%d';
        }

        if ($next_attempt !== null) {
            $data['next_attempt_at'] = $next_attempt;
            $format[] = '%s';
        }

        if ($clear_worker) {
            $data['worker_token'] = null;
            $data['locked_at'] = null;
            $format[] = '%s';
            $format[] = '%s';
        }

        if ($error_message !== '') {
            $data['last_error'] = sanitize_text_field($error_message);
            $format[] = '%s';
        }

        $this->db->update(
            $this->table_name(),
            $data,
            ['id' => $id],
            $format,
            ['%d']
        );
    }
}

