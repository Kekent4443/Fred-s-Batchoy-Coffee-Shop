-- Import this once into an existing installation after taking a database backup.
-- It is safe to run repeatedly and does not rewrite historical financial totals.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER verification_notes', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
    INDEX idx_registration_verification_expiry (expires_at),
    CONSTRAINT fk_registration_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing active customers were approved before email ownership verification
-- existed, so grandfather only those already-active accounts.
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at, NOW())
WHERE role = 'customer' AND status = 'active' AND email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS promo_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    description VARCHAR(255) NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    minimum_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    maximum_discount DECIMAL(10,2) NULL,
    usage_limit INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_promo_codes_code (code),
    INDEX idx_promo_codes_active_window (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'prepared_by_staff_id');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN prepared_by_staff_id INT UNSIGNED NULL AFTER customer_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'checkout_idempotency_key');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN checkout_idempotency_key CHAR(64) NULL AFTER assigned_staff_id', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'promo_code');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN promo_code VARCHAR(40) NULL AFTER discount_amount', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'promo_discount_amount');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promo_code', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'promo_code');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN promo_code VARCHAR(40) NULL AFTER discount_amount', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'promo_discount_amount');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promo_code', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'gcash_reference_hash');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN gcash_reference_hash CHAR(64) NULL AFTER gcash_reference', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill hashes only when a normalized legacy reference occurs once. Any
-- duplicate legacy references remain NULL for manual review instead of making
-- the migration fail or silently selecting a winner.
UPDATE transactions t
INNER JOIN (
    SELECT UPPER(REPLACE(REPLACE(REPLACE(TRIM(gcash_reference), ' ', ''), '-', ''), '.', '')) AS normalized_reference
    FROM transactions
    WHERE NULLIF(TRIM(gcash_reference), '') IS NOT NULL
    GROUP BY UPPER(REPLACE(REPLACE(REPLACE(TRIM(gcash_reference), ' ', ''), '-', ''), '.', ''))
    HAVING COUNT(*) = 1
) unique_refs
    ON unique_refs.normalized_reference = UPPER(REPLACE(REPLACE(REPLACE(TRIM(t.gcash_reference), ' ', ''), '-', ''), '.', ''))
SET t.gcash_reference_hash = SHA2(unique_refs.normalized_reference, 256)
WHERE t.gcash_reference_hash IS NULL;

CREATE TABLE IF NOT EXISTS payment_review_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    action ENUM('submitted', 'verified', 'rejected', 'resubmitted') NOT NULL,
    reference_no VARCHAR(120) NULL,
    proof_path VARCHAR(255) NULL,
    reviewed_by INT UNSIGNED NULL,
    reason TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_review_transaction_created (transaction_id, created_at),
    INDEX idx_payment_review_order_created (order_id, created_at),
    CONSTRAINT fk_payment_review_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_review_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_preparer_status');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_orders_preparer_status ON orders (prepared_by_staff_id, order_status)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uniq_orders_checkout_idempotency');
SET @ddl := IF(@has_index = 0, 'CREATE UNIQUE INDEX uniq_orders_checkout_idempotency ON orders (checkout_idempotency_key)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_payment_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_orders_payment_created ON orders (payment_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'uniq_transactions_gcash_reference_hash');
SET @ddl := IF(@has_index = 0, 'CREATE UNIQUE INDEX uniq_transactions_gcash_reference_hash ON transactions (gcash_reference_hash)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_product_order');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_order_items_product_order ON order_items (product_id, order_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND INDEX_NAME = 'idx_contact_status_read_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_contact_status_read_created ON contact_messages (status, is_read, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_logs_user_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_audit_logs_user_created ON audit_logs (user_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_logs' AND INDEX_NAME = 'idx_report_logs_generated_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_report_logs_generated_created ON report_logs (generated_by, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND INDEX_NAME = 'idx_password_resets_user_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_password_resets_user_created ON password_resets (user_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_preparer');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE orders ADD CONSTRAINT fk_orders_preparer FOREIGN KEY (prepared_by_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Remove duplicate legacy handoffs before enforcing one handoff per trigger.
DELETE newer
FROM support_handoffs newer
INNER JOIN support_handoffs older
    ON older.trigger_message_id = newer.trigger_message_id AND older.id < newer.id;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_handoffs' AND INDEX_NAME = 'uniq_support_handoffs_trigger');
SET @ddl := IF(@has_index = 0, 'CREATE UNIQUE INDEX uniq_support_handoffs_trigger ON support_handoffs (trigger_message_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE support_conversations
SET status = 'pending_staff', updated_at = NOW()
WHERE status = 'open'
  AND last_customer_message_at IS NOT NULL
  AND (last_human_reply_at IS NULL OR last_customer_message_at > last_human_reply_at);

-- Complete the upgrade from the original application schema. Normal web
-- requests deliberately do not run DDL, so every column used by authentication,
-- receipts, notifications, and the mail queue is installed here once.
ALTER TABLE users
    MODIFY COLUMN role ENUM('administrator','manager','staff','rider','customer') NOT NULL DEFAULT 'customer';

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_by');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN deleted_by INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_previous_status');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN deleted_previous_status VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'tax_rate');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'tax_amount');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'service_fee');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'delivery_fee');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE orders
    MODIFY COLUMN order_status ENUM('pending','processing','confirmed','assigned','out_for_delivery','completed','cancelled') NOT NULL DEFAULT 'pending',
    MODIFY COLUMN payment_status ENUM('pending','accepted','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    MODIFY COLUMN delivery_status ENUM('pending','assigned','out_for_delivery','delivered','issue_reported','failed_delivery','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE deliveries
    MODIFY COLUMN delivery_status ENUM('pending','assigned','out_for_delivery','delivered','issue_reported','failed_delivery','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE transactions
    MODIFY COLUMN transaction_status ENUM('pending','accepted','completed','failed','cancelled') NOT NULL DEFAULT 'pending';

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'reviewed_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN reviewed_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'rejection_reason');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN rejection_reason TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'role');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE notifications ADD COLUMN role VARCHAR(50) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'link');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE notifications ADD COLUMN link VARCHAR(500) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'event_key');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE notifications ADD COLUMN event_key VARCHAR(191) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'is_read');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'read_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE notifications SET is_read = IF(status = 'read', 1, 0), read_at = IF(status = 'read', COALESCE(read_at, updated_at, created_at), NULL);

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'email');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN email VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'attempt_count');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'verified_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN verified_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'verification_token_hash');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN verification_token_hash CHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'is_used');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE password_resets ADD COLUMN is_used TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE password_resets pr INNER JOIN users u ON u.id = pr.user_id SET pr.email = u.email WHERE pr.email IS NULL OR pr.email = '';
UPDATE password_resets SET is_used = 1 WHERE used_at IS NOT NULL;
ALTER TABLE password_resets MODIFY COLUMN email VARCHAR(150) NOT NULL;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'password_reset_id');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN password_reset_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'recipient_name');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN recipient_name VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'html_message');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN html_message MEDIUMTEXT NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'message_type');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN message_type VARCHAR(50) NOT NULL DEFAULT ''notification''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'dedupe_key');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN dedupe_key CHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'attempt_count');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'next_attempt_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN next_attempt_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'claim_token');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN claim_token CHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'locked_until');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN locked_until DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'provider_message_id');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN provider_message_id VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'last_attempt_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN last_attempt_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'delivered_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN delivered_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'failed_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN failed_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND COLUMN_NAME = 'expires_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE email_notifications ADD COLUMN expires_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE email_notifications SET recipient_name = COALESCE(NULLIF(recipient_name, ''), email), next_attempt_at = COALESCE(next_attempt_at, created_at, NOW());
ALTER TABLE email_notifications
    MODIFY COLUMN status ENUM('queued','sent','delivered','deferred','failed','expired') NOT NULL DEFAULT 'queued',
    MODIFY COLUMN next_attempt_at DATETIME NOT NULL;

CREATE TABLE IF NOT EXISTS receipt_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    business_name VARCHAR(150) NOT NULL,
    business_address VARCHAR(255) NOT NULL,
    contact_number VARCHAR(60) NOT NULL,
    contact_email VARCHAR(150) NULL,
    social_media VARCHAR(150) NULL,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    footer_message VARCHAR(500) NOT NULL,
    paper_width ENUM('58mm','80mm') NOT NULL DEFAULT '80mm',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_settings_updated_by (updated_by),
    CONSTRAINT fk_receipt_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO receipt_settings
    (id, business_name, business_address, contact_number, contact_email, tax_rate, service_fee, delivery_fee, footer_message, paper_width, updated_by)
VALUES
    (1, 'Fred''s Batchoy', 'Business address not configured', 'Contact number not configured', 'support@example.com', 12.00, 0.00, 0.00, 'Thank you for your order!', '80mm', NULL);

CREATE TABLE IF NOT EXISTS gcash_payment_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(20) NOT NULL,
    qr_code_path VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    updated_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gcash_accounts_status_updated (status, updated_at, id),
    INDEX idx_gcash_accounts_updated_by (updated_by),
    CONSTRAINT fk_gcash_accounts_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the old staff-preparation attribution. Active delivery assignments
-- to a non-rider are returned to the ready queue because those users can no
-- longer legally perform rider-only actions.
UPDATE orders o
INNER JOIN users u ON u.id = o.assigned_staff_id AND u.role = 'staff'
SET o.prepared_by_staff_id = COALESCE(o.prepared_by_staff_id, o.assigned_staff_id);
UPDATE deliveries d
INNER JOIN orders o ON o.id = d.order_id
INNER JOIN users u ON u.id = d.assigned_staff_id AND u.role <> 'rider'
SET d.assigned_staff_id = NULL, d.delivery_status = 'assigned', d.updated_at = NOW(),
    o.assigned_staff_id = NULL, o.order_status = 'assigned', o.delivery_status = 'assigned', o.updated_at = NOW()
WHERE d.delivery_status NOT IN ('delivered','cancelled','failed_delivery')
  AND o.order_status NOT IN ('completed','cancelled');

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_deleted_at');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_users_deleted_at ON users (deleted_at, status)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_user_role_read_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_notifications_user_role_read_created ON notifications (user_id, role, is_read, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_created_at');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_notifications_created_at ON notifications (created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'uniq_notifications_event_recipient');
SET @ddl := IF(@has_index = 0, 'CREATE UNIQUE INDEX uniq_notifications_event_recipient ON notifications (user_id, event_key)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'uniq_email_dedupe');
SET @ddl := IF(@has_index = 0, 'CREATE UNIQUE INDEX uniq_email_dedupe ON email_notifications (dedupe_key)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_queue_ready');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_email_queue_ready ON email_notifications (status, next_attempt_at, locked_until)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_provider_message');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_email_provider_message ON email_notifications (provider_message_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_password_reset');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_email_password_reset ON email_notifications (password_reset_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_limits' AND INDEX_NAME = 'idx_rate_limits_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_rate_limits_expires ON rate_limits (expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_order_items_order');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_order_items_order ON order_items (order_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_history' AND INDEX_NAME = 'idx_delivery_history_delivery_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_delivery_history_delivery_created ON delivery_history (delivery_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_history' AND INDEX_NAME = 'idx_delivery_history_order_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_delivery_history_order_created ON delivery_history (order_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Normalize orphaned nullable references before adding the missing foreign keys.
UPDATE users u LEFT JOIN users actor ON actor.id = u.deleted_by SET u.deleted_by = NULL WHERE u.deleted_by IS NOT NULL AND actor.id IS NULL;
UPDATE email_notifications e LEFT JOIN password_resets p ON p.id = e.password_reset_id SET e.password_reset_id = NULL WHERE e.password_reset_id IS NOT NULL AND p.id IS NULL;
DELETE h FROM delivery_history h LEFT JOIN deliveries d ON d.id = h.delivery_id LEFT JOIN orders o ON o.id = h.order_id WHERE d.id IS NULL OR o.id IS NULL;
UPDATE delivery_history h LEFT JOIN users u ON u.id = h.changed_by SET h.changed_by = NULL WHERE h.changed_by IS NOT NULL AND u.id IS NULL;

SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_deleted_by');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE users ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND CONSTRAINT_NAME = 'fk_email_notifications_password_reset');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE email_notifications ADD CONSTRAINT fk_email_notifications_password_reset FOREIGN KEY (password_reset_id) REFERENCES password_resets(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_history' AND CONSTRAINT_NAME = 'fk_delivery_history_delivery');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE delivery_history ADD CONSTRAINT fk_delivery_history_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_history' AND CONSTRAINT_NAME = 'fk_delivery_history_order');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE delivery_history ADD CONSTRAINT fk_delivery_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_history' AND CONSTRAINT_NAME = 'fk_delivery_history_user');
SET @ddl := IF(@has_constraint = 0, 'ALTER TABLE delivery_history ADD CONSTRAINT fk_delivery_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Remove legacy duplicate indexes after their canonical replacements exist.
-- This keeps upgraded databases aligned with fresh installs and avoids paying
-- the write cost for two indexes with the same leading columns.
SET @contact_index_columns := (
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_messages'
      AND INDEX_NAME = 'idx_contact_messages_status'
);
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND INDEX_NAME = 'idx_contact_messages_status');
SET @ddl := IF(@contact_index_columns <> 'status,is_read,created_at' AND @has_index > 0, 'ALTER TABLE contact_messages DROP INDEX idx_contact_messages_status', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND INDEX_NAME = 'idx_contact_status_read_created');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE contact_messages DROP INDEX idx_contact_status_read_created', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND INDEX_NAME = 'idx_contact_messages_status');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_contact_messages_status ON contact_messages (status, is_read, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_notifications' AND INDEX_NAME = 'idx_email_status');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE email_notifications DROP INDEX idx_email_status', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_handoffs' AND INDEX_NAME = 'idx_support_handoffs_trigger');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE support_handoffs DROP INDEX idx_support_handoffs_trigger', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'fk_audit_logs_user');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE audit_logs DROP INDEX fk_audit_logs_user', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'fk_order_items_order');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE order_items DROP INDEX fk_order_items_order', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'fk_order_items_product');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE order_items DROP INDEX fk_order_items_product', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_logs' AND INDEX_NAME = 'fk_report_logs_user');
SET @ddl := IF(@has_index > 0, 'ALTER TABLE report_logs DROP INDEX fk_report_logs_user', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
