-- Repeatable, non-destructive password-recovery schema alignment.
-- Apply to an existing backed-up database; do not import schema.sql or seed.sql.
-- This migration never drops/truncates a table and never deletes business data.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at');
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(255) NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'password_reset',
    code_hash VARCHAR(255) NULL,
    delivery_channel VARCHAR(20) NULL,
    destination VARCHAR(180) NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    verification_token_hash CHAR(64) NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY token (token),
    INDEX idx_password_resets_user_expires (user_id, expires_at),
    INDEX idx_password_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'email');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN email VARCHAR(150) NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(32) NOT NULL DEFAULT ''password_reset'' AFTER token', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'code_hash');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN code_hash VARCHAR(255) NULL AFTER purpose', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'delivery_channel');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN delivery_channel VARCHAR(20) NULL AFTER code_hash', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'destination');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN destination VARCHAR(180) NULL AFTER delivery_channel', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'attempt_count');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'verified_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN verified_at DATETIME NULL AFTER attempt_count', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'verification_token_hash');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN verification_token_hash CHAR(64) NULL AFTER verified_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'is_used');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN is_used TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_token_hash', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'used_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL AFTER is_used', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE password_resets pr INNER JOIN users u ON u.id = pr.user_id
SET pr.email = u.email WHERE pr.email IS NULL OR pr.email = '';
UPDATE password_resets SET purpose = 'password_reset' WHERE purpose IS NULL OR purpose = '';
UPDATE password_resets SET is_used = 1 WHERE used_at IS NOT NULL AND is_used = 0;

CREATE TABLE IF NOT EXISTS registration_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    last_sent_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_registration_verification_user (user_id),
    INDEX idx_registration_verification_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    password_reset_id INT UNSIGNED NULL,
    email VARCHAR(150) NOT NULL,
    recipient_name VARCHAR(150) NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    html_message MEDIUMTEXT NULL,
    message_type VARCHAR(50) NOT NULL DEFAULT 'notification',
    dedupe_key CHAR(64) NULL,
    status ENUM('queued','sent','delivered','deferred','failed','expired') NOT NULL DEFAULT 'queued',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'password_reset_id');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN password_reset_id INT UNSIGNED NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'recipient_name');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN recipient_name VARCHAR(150) NULL AFTER email', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'html_message');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN html_message MEDIUMTEXT NULL AFTER message', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'message_type');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN message_type VARCHAR(50) NOT NULL DEFAULT ''notification'' AFTER html_message', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'dedupe_key');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN dedupe_key CHAR(64) NULL AFTER message_type', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'attempt_count');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'next_attempt_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'claim_token');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN claim_token CHAR(64) NULL AFTER next_attempt_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'locked_until');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN locked_until DATETIME NULL AFTER claim_token', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'provider_message_id');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN provider_message_id VARCHAR(255) NULL AFTER locked_until', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'provider_response');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN provider_response TEXT NULL AFTER provider_message_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'last_attempt_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN last_attempt_at DATETIME NULL AFTER created_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'delivered_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN delivered_at DATETIME NULL AFTER sent_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'failed_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN failed_at DATETIME NULL AFTER delivered_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'expires_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN expires_at DATETIME NULL AFTER failed_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE email_notifications SET next_attempt_at = COALESCE(next_attempt_at, created_at, NOW()) WHERE next_attempt_at IS NULL;
ALTER TABLE email_notifications MODIFY next_attempt_at DATETIME NOT NULL;
SET @queue_status_type := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'status');
SET @ddl := IF(@queue_status_type NOT LIKE '%''delivered''%' OR @queue_status_type NOT LIKE '%''deferred''%' OR @queue_status_type NOT LIKE '%''expired''%',
    'ALTER TABLE email_notifications MODIFY status ENUM(''queued'',''sent'',''delivered'',''deferred'',''failed'',''expired'') NOT NULL DEFAULT ''queued''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'uniq_email_dedupe');
SET @dedupe_duplicates := (SELECT COUNT(*) FROM (SELECT dedupe_key FROM email_notifications WHERE dedupe_key IS NOT NULL GROUP BY dedupe_key HAVING COUNT(*) > 1) d);
SET @ddl := IF(@has_index = 0 AND @dedupe_duplicates = 0, 'CREATE UNIQUE INDEX uniq_email_dedupe ON email_notifications (dedupe_key)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_queue_ready');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_email_queue_ready ON email_notifications (status, next_attempt_at, locked_until)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_provider_message');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_email_provider_message ON email_notifications (provider_message_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key CHAR(64) NOT NULL,
    scope VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uniq_rate_key_scope (rate_key, scope),
    INDEX idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY selector (selector),
    INDEX idx_remember_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    login_source VARCHAR(20) NOT NULL DEFAULT 'password',
    new_ip TINYINT(1) NOT NULL DEFAULT 0,
    new_browser TINYINT(1) NOT NULL DEFAULT 0,
    login_at DATETIME(6) NOT NULL,
    logout_at DATETIME(6) NULL,
    last_activity_at DATETIME(6) NOT NULL,
    end_reason VARCHAR(20) NULL,
    INDEX idx_login_history_login (login_at, id),
    INDEX idx_login_history_logout (logout_at, id),
    INDEX idx_login_history_role_login (role, login_at),
    INDEX idx_login_history_user_login (user_id, login_at),
    INDEX idx_login_history_activity (logout_at, last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard indexes used by ON DUPLICATE KEY and the queue worker. They are
-- added only when doing so cannot fail because of duplicate live records.
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_verifications' AND INDEX_NAME = 'uniq_registration_verification_user');
SET @registration_duplicates := (SELECT COUNT(*) FROM (SELECT user_id FROM registration_verifications GROUP BY user_id HAVING COUNT(*) > 1) d);
SET @ddl := IF(@has_index = 0 AND @registration_duplicates = 0, 'CREATE UNIQUE INDEX uniq_registration_verification_user ON registration_verifications (user_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND INDEX_NAME = 'idx_password_resets_user_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_password_resets_user_expires ON password_resets (user_id, expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND INDEX_NAME = 'idx_password_resets_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_password_resets_expires ON password_resets (expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
