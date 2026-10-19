-- Converts email_notifications into a leased, retryable email queue.
ALTER TABLE email_notifications
    ADD COLUMN password_reset_id INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN recipient_name VARCHAR(150) NULL AFTER email,
    ADD COLUMN html_message MEDIUMTEXT NULL AFTER message,
    ADD COLUMN message_type VARCHAR(50) NOT NULL DEFAULT 'notification' AFTER html_message,
    ADD COLUMN dedupe_key CHAR(64) NULL AFTER message_type,
    MODIFY status ENUM('queued', 'sent', 'delivered', 'deferred', 'failed', 'expired') NOT NULL DEFAULT 'queued',
    ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count,
    ADD COLUMN claim_token CHAR(64) NULL AFTER next_attempt_at,
    ADD COLUMN locked_until DATETIME NULL AFTER claim_token,
    ADD COLUMN provider_message_id VARCHAR(255) NULL AFTER locked_until,
    ADD COLUMN last_attempt_at DATETIME NULL AFTER created_at,
    ADD COLUMN delivered_at DATETIME NULL AFTER sent_at,
    ADD COLUMN failed_at DATETIME NULL AFTER delivered_at,
    ADD COLUMN expires_at DATETIME NULL AFTER failed_at;

UPDATE email_notifications
SET next_attempt_at = COALESCE(next_attempt_at, created_at, NOW())
WHERE next_attempt_at IS NULL;

ALTER TABLE email_notifications
    MODIFY next_attempt_at DATETIME NOT NULL,
    ADD UNIQUE KEY uniq_email_dedupe (dedupe_key),
    ADD INDEX idx_email_queue_ready (status, next_attempt_at, locked_until),
    ADD INDEX idx_email_provider_message (provider_message_id);
