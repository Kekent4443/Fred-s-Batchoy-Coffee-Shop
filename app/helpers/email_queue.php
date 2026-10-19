<?php

declare(strict_types=1);

const EMAIL_QUEUE_MAX_ATTEMPTS = 5;

function email_queue_column_info(string $column): ?array
{
    $database = (string) (db_find('SELECT DATABASE() AS name')['name'] ?? '');
    if ($database === '') {
        return null;
    }

    return db_find(
        'SELECT COLUMN_TYPE, IS_NULLABLE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "email_notifications" AND COLUMN_NAME = ? LIMIT 1',
        'ss',
        [$database, $column]
    );
}

function ensure_email_queue_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    if (!schema_maintenance_enabled()) {
        $checked = true;
        return;
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS email_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            password_reset_id INT UNSIGNED NULL,
            email VARCHAR(150) NOT NULL,
            recipient_name VARCHAR(150) NULL,
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            html_message MEDIUMTEXT NULL,
            message_type VARCHAR(50) NOT NULL DEFAULT "notification",
            dedupe_key CHAR(64) NULL,
            status ENUM("queued", "sent", "delivered", "deferred", "failed", "expired") NOT NULL DEFAULT "queued",
            attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claim_token CHAR(64) NULL,
            locked_until DATETIME NULL,
            provider_message_id VARCHAR(255) NULL,
            provider_response TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            failed_at DATETIME NULL,
            expires_at DATETIME NULL,
            UNIQUE KEY uniq_email_dedupe (dedupe_key),
            INDEX idx_email_queue_ready (status, next_attempt_at, locked_until),
            INDEX idx_email_provider_message (provider_message_id),
            INDEX idx_email_user (user_id)
        ) ENGINE=InnoDB'
    );

    $columns = [
        'password_reset_id' => 'ALTER TABLE email_notifications ADD COLUMN password_reset_id INT UNSIGNED NULL AFTER user_id',
        'recipient_name' => 'ALTER TABLE email_notifications ADD COLUMN recipient_name VARCHAR(150) NULL AFTER email',
        'html_message' => 'ALTER TABLE email_notifications ADD COLUMN html_message MEDIUMTEXT NULL AFTER message',
        'message_type' => 'ALTER TABLE email_notifications ADD COLUMN message_type VARCHAR(50) NOT NULL DEFAULT "notification" AFTER html_message',
        'dedupe_key' => 'ALTER TABLE email_notifications ADD COLUMN dedupe_key CHAR(64) NULL AFTER message_type',
        'attempt_count' => 'ALTER TABLE email_notifications ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
        'next_attempt_at' => 'ALTER TABLE email_notifications ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count',
        'claim_token' => 'ALTER TABLE email_notifications ADD COLUMN claim_token CHAR(64) NULL AFTER next_attempt_at',
        'locked_until' => 'ALTER TABLE email_notifications ADD COLUMN locked_until DATETIME NULL AFTER claim_token',
        'provider_message_id' => 'ALTER TABLE email_notifications ADD COLUMN provider_message_id VARCHAR(255) NULL AFTER locked_until',
        'last_attempt_at' => 'ALTER TABLE email_notifications ADD COLUMN last_attempt_at DATETIME NULL AFTER created_at',
        'delivered_at' => 'ALTER TABLE email_notifications ADD COLUMN delivered_at DATETIME NULL AFTER sent_at',
        'failed_at' => 'ALTER TABLE email_notifications ADD COLUMN failed_at DATETIME NULL AFTER delivered_at',
        'expires_at' => 'ALTER TABLE email_notifications ADD COLUMN expires_at DATETIME NULL AFTER failed_at',
    ];

    foreach ($columns as $column => $sql) {
        if (!database_column_exists('email_notifications', $column)) {
            db_execute($sql);
        }
    }

    db_execute('UPDATE email_notifications SET next_attempt_at = COALESCE(next_attempt_at, created_at, NOW()) WHERE next_attempt_at IS NULL');
    $nextAttemptColumn = email_queue_column_info('next_attempt_at');
    if (($nextAttemptColumn['IS_NULLABLE'] ?? 'YES') !== 'NO') {
        db_execute('ALTER TABLE email_notifications MODIFY next_attempt_at DATETIME NOT NULL');
    }

    $statusColumn = email_queue_column_info('status');
    $statusType = strtolower((string) ($statusColumn['COLUMN_TYPE'] ?? ''));
    if (!str_contains($statusType, "'delivered'") || !str_contains($statusType, "'expired'")) {
        db_execute('ALTER TABLE email_notifications MODIFY status ENUM("queued", "sent", "delivered", "deferred", "failed", "expired") NOT NULL DEFAULT "queued"');
    }

    if (!database_index_exists('email_notifications', 'uniq_email_dedupe')) {
        db_execute('CREATE UNIQUE INDEX uniq_email_dedupe ON email_notifications (dedupe_key)');
    }
    if (!database_index_exists('email_notifications', 'idx_email_queue_ready')) {
        db_execute('CREATE INDEX idx_email_queue_ready ON email_notifications (status, next_attempt_at, locked_until)');
    }
    if (!database_index_exists('email_notifications', 'idx_email_provider_message')) {
        db_execute('CREATE INDEX idx_email_provider_message ON email_notifications (provider_message_id)');
    }

    $checked = true;
}

function scrub_terminal_security_email_bodies(int $limit = 100): int
{
    ensure_email_queue_schema();
    $limit = min(500, max(1, $limit));
    db_execute(
        'UPDATE email_notifications
         SET message = "", html_message = NULL
         WHERE (password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"))
           AND status IN ("sent", "delivered", "expired", "failed")
           AND (message <> "" OR html_message IS NOT NULL)
         ORDER BY id ASC
         LIMIT ' . $limit
    );

    return max(0, get_db()->affected_rows);
}

function enqueue_email(
    ?int $userId,
    string $email,
    string $recipientName,
    string $subject,
    string $message,
    ?string $htmlMessage = null,
    string $messageType = 'notification',
    ?string $dedupeKey = null,
    ?string $expiresAt = null,
    ?int $passwordResetId = null
): array {
    ensure_email_queue_schema();

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['queued' => false, 'id' => 0];
    }

    $dedupeHash = $dedupeKey !== null && trim($dedupeKey) !== '' ? hash('sha256', $dedupeKey) : null;

    try {
        db_execute(
            'INSERT INTO email_notifications
                (user_id, password_reset_id, email, recipient_name, subject, message, html_message, message_type,
                 dedupe_key, status, attempt_count, next_attempt_at, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "queued", 0, NOW(), NOW(), ?)',
            'iissssssss',
            [$userId, $passwordResetId, $email, $recipientName, $subject, $message, $htmlMessage, $messageType, $dedupeHash, $expiresAt]
        );

        return ['queued' => true, 'id' => db_last_id(), 'duplicate' => false];
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1062 || $dedupeHash === null) {
            throw $exception;
        }

        $existing = db_find('SELECT id FROM email_notifications WHERE dedupe_key = ? LIMIT 1', 's', [$dedupeHash]);
        return ['queued' => true, 'id' => (int) ($existing['id'] ?? 0), 'duplicate' => true];
    }
}

function claim_email_queue_batch(int $batchSize = 10, ?int $emailId = null): array
{
    ensure_email_queue_schema();
    $batchSize = min(50, max(1, $batchSize));
    $emailId = $emailId !== null ? max(1, $emailId) : null;
    $claimToken = bin2hex(random_bytes(32));

    db_execute(
        'UPDATE email_notifications
         SET status = "expired", failed_at = NOW(), provider_response = "Email expired before delivery.",
             message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), "", message),
             html_message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), NULL, html_message),
             claim_token = NULL, locked_until = NULL
         WHERE (status = "queued" OR (status = "deferred" AND sent_at IS NULL))
           AND expires_at IS NOT NULL AND expires_at <= NOW()
         LIMIT 100'
    );

    $targetSql = $emailId !== null ? ' AND id = ' . $emailId : '';
    $sql = 'UPDATE email_notifications
            SET claim_token = ?, locked_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
            WHERE (status = "queued" OR (status = "deferred" AND sent_at IS NULL))
              AND next_attempt_at <= NOW()
              AND (expires_at IS NULL OR expires_at > NOW())
              AND (claim_token IS NULL OR locked_until < NOW())' . $targetSql . '
            ORDER BY id ASC
            LIMIT ' . $batchSize;
    db_execute($sql, 's', [$claimToken]);

    return db_select(
        'SELECT * FROM email_notifications WHERE claim_token = ? AND locked_until >= NOW() ORDER BY id ASC',
        's',
        [$claimToken]
    );
}

function process_email_queue_batch(int $batchSize = 10, ?int $emailId = null): array
{
    $jobs = claim_email_queue_batch($batchSize, $emailId);
    $summary = ['claimed' => count($jobs), 'sent' => 0, 'deferred' => 0, 'failed' => 0, 'expired' => 0];
    $senderFile = APP_ROOT . '/includes/send_email.php';
    require_once $senderFile;

    foreach ($jobs as $job) {
        $id = (int) $job['id'];
        $claimToken = (string) $job['claim_token'];

        if (!empty($job['expires_at']) && strtotime((string) $job['expires_at']) <= time()) {
            db_execute(
                'UPDATE email_notifications
                 SET status = "expired", failed_at = NOW(),
                     message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), "", message),
                     html_message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), NULL, html_message),
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ? AND claim_token = ?',
                'is',
                [$id, $claimToken]
            );
            $summary['expired']++;
            continue;
        }

        $attempt = (int) $job['attempt_count'] + 1;
        db_execute(
            'UPDATE email_notifications SET attempt_count = ?, last_attempt_at = NOW() WHERE id = ? AND claim_token = ?',
            'iis',
            [$attempt, $id, $claimToken]
        );

        $htmlBody = trim((string) ($job['html_message'] ?? ''));
        if ($htmlBody === '') {
            $htmlBody = nl2br(htmlspecialchars((string) $job['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $result = send_email_with_result(
            (string) $job['email'],
            (string) ($job['recipient_name'] ?: $job['email']),
            (string) $job['subject'],
            $htmlBody,
            $id
        );

        if (!empty($result['success'])) {
            db_execute(
                'UPDATE email_notifications
                 SET status = "sent", provider_message_id = ?, provider_response = "Accepted by Brevo SMTP.",
                     sent_at = NOW(),
                     message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), "", message),
                     html_message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), NULL, html_message),
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ? AND claim_token = ?',
                'sis',
                [(string) ($result['message_id'] ?? ''), $id, $claimToken]
            );
            $summary['sent']++;
            continue;
        }

        $error = substr((string) ($result['error'] ?? 'Email delivery failed.'), 0, 1000);
        $temporary = !empty($result['temporary']);
        $canRetry = $temporary && $attempt < EMAIL_QUEUE_MAX_ATTEMPTS
            && (empty($job['expires_at']) || strtotime((string) $job['expires_at']) > time() + 60);

        if ($canRetry) {
            $delays = [60, 300, 900, 3600];
            $delay = $delays[min($attempt - 1, count($delays) - 1)];
            db_execute(
                'UPDATE email_notifications
                 SET status = "deferred", provider_response = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ' . $delay . ' SECOND),
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ? AND claim_token = ?',
                'sis',
                [$error, $id, $claimToken]
            );
            $summary['deferred']++;
        } else {
            db_execute(
                'UPDATE email_notifications
                 SET status = "failed", provider_response = ?, failed_at = NOW(),
                     message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), "", message),
                     html_message = IF(password_reset_id IS NOT NULL OR message_type IN ("password_reset", "admin_password_reset", "registration_verification"), NULL, html_message),
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ? AND claim_token = ?',
                'sis',
                [$error, $id, $claimToken]
            );
            $summary['failed']++;
        }
    }

    return $summary;
}

function process_email_queue_opportunistically(bool $force = false): ?array
{
    if (PHP_SAPI === 'cli' || !APP_WEB_EMAIL_QUEUE_ENABLED) {
        return null;
    }

    require_once APP_ROOT . '/app/config/mail.php';
    if (!transactional_mail_is_configured()) {
        return null;
    }

    $result = run_throttled_maintenance(
        'web-email-queue',
        APP_WEB_EMAIL_QUEUE_INTERVAL_SECONDS,
        static function (): array {
            scrub_terminal_security_email_bodies(100);

            // One SMTP delivery per request keeps the shared-host fallback
            // bounded even when the provider is slow or temporarily offline.
            return process_email_queue_batch(1);
        },
        $force
    );

    return is_array($result) ? $result : null;
}

function schedule_opportunistic_email_queue_processing(bool $force = false): void
{
    static $scheduled = false;

    if (PHP_SAPI === 'cli' || !APP_WEB_EMAIL_QUEUE_ENABLED) {
        return;
    }

    $GLOBALS['freds_force_web_email_queue'] = !empty($GLOBALS['freds_force_web_email_queue']) || $force;

    if ($scheduled) {
        return;
    }

    $scheduled = true;
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['freds_skip_web_email_queue'])) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            process_email_queue_opportunistically(!empty($GLOBALS['freds_force_web_email_queue']));
        } catch (Throwable $exception) {
            error_log('Opportunistic email queue processing failed: ' . $exception->getMessage());
        }
    });
}

function record_brevo_delivery_event(array $event): bool
{
    ensure_email_queue_schema();
    $eventName = strtolower(trim((string) ($event['event'] ?? '')));
    $messageId = trim((string) ($event['message-id'] ?? $event['message_id'] ?? ''), '<>');
    $custom = (string) ($event['X-Mailin-custom'] ?? $event['x-mailin-custom'] ?? '');
    $queueId = preg_match('/queue_id:(\d+)/', $custom, $matches) === 1 ? (int) $matches[1] : 0;

    $status = match ($eventName) {
        'delivered' => 'delivered',
        'deferred', 'soft_bounce', 'softbounce' => 'deferred',
        'hard_bounce', 'hardbounce', 'blocked', 'invalid', 'error', 'spam' => 'failed',
        'sent', 'request' => 'sent',
        default => null,
    };
    if ($status === null || ($queueId < 1 && $messageId === '')) {
        return false;
    }

    $reason = substr((string) ($event['reason'] ?? ('Brevo webhook: ' . $eventName)), 0, 1000);
    $where = $queueId > 0 ? 'id = ?' : 'provider_message_id = ?';
    $value = $queueId > 0 ? $queueId : $messageId;

    return db_execute(
        'UPDATE email_notifications
         SET status = IF(status = "delivered", "delivered", ?), provider_response = ?,
             delivered_at = IF(? = "delivered", NOW(), delivered_at),
             failed_at = IF(? = "failed", NOW(), failed_at)
         WHERE ' . $where . ' AND status <> "expired"',
        $queueId > 0 ? 'ssssi' : 'sssss',
        [$status, $reason, $status, $status, $value]
    );
}
