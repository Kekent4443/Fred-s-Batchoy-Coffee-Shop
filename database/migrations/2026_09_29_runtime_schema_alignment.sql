-- Explicit, repeatable upgrade for schema pieces formerly repaired by PHP
-- ensure_* helpers during web requests. Import only after the earlier dated
-- migrations into a backed-up existing database; never import schema.sql over it.
-- No tables or business records are dropped or truncated.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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
    selector CHAR(24) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_remember_tokens_expires (expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identity-review columns are distinct from email_verified_at. Keep existing
-- review decisions and backfill only records with no identity-review status.
SET @user_status_type := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status');
SET @ddl := IF(@user_status_type NOT LIKE '%pending_verification%' OR @user_status_type NOT LIKE '%rejected%',
    'ALTER TABLE users MODIFY status ENUM(''active'', ''inactive'', ''pending_verification'', ''rejected'') NOT NULL DEFAULT ''active''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_status');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_status VARCHAR(30) NULL DEFAULT NULL AFTER valid_id_path', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_reviewed_by');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_reviewed_by INT UNSIGNED NULL AFTER verification_status', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_reviewed_name');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_reviewed_name VARCHAR(150) NULL AFTER verification_reviewed_by', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_reviewed_role');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_reviewed_role VARCHAR(50) NULL AFTER verification_reviewed_name', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_reviewed_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_reviewed_at DATETIME NULL AFTER verification_reviewed_role', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verification_notes');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE users ADD COLUMN verification_notes TEXT NULL AFTER verification_reviewed_at', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE users SET verification_status = CASE
    WHEN status = 'active' THEN 'approved'
    WHEN status = 'rejected' THEN 'rejected'
    WHEN status = 'pending_verification' THEN 'pending'
    ELSE verification_status END
WHERE role = 'customer' AND verification_status IS NULL;

SET @has_constraint := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_verification_reviewed_by');
SET @ddl := IF(@has_constraint = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_verification_reviewed_by FOREIGN KEY (verification_reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'proof_of_payment_path');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE transactions ADD COLUMN proof_of_payment_path VARCHAR(255) NULL AFTER gcash_reference', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'delivered_at');
SET @ddl := IF(@has_column = 0, 'ALTER TABLE deliveries ADD COLUMN delivered_at DATETIME NULL AFTER issue_notes', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS record_states (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_type VARCHAR(40) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    record_status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active',
    snapshot_data JSON NULL,
    archived_at DATETIME NULL,
    deleted_at DATETIME NULL,
    recovered_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_record_state (record_type, record_id),
    CONSTRAINT fk_record_states_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes expected by ensure_performance_indexes() but not supplied by the
-- older upgrade migrations. Existing indexes are left untouched.
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_status');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_users_role_status ON users (role, status)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_status_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_users_status_created ON users (status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_phone');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_users_phone ON users (phone)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_verification');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_users_verification ON users (verification_status, verification_reviewed_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_public_catalog');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_products_public_catalog ON products (status, is_available, category_id, stock_quantity)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_name');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_products_name ON products (name)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_customer_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_orders_customer_created ON orders (customer_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_staff_status');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_orders_staff_status ON orders (assigned_staff_id, order_status)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_statuses_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_orders_statuses_created ON orders (order_status, payment_status, delivery_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_transactions_status_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_transactions_status_created ON transactions (transaction_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_transactions_recorded_by');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_transactions_recorded_by ON transactions (recorded_by, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND INDEX_NAME = 'idx_deliveries_staff_status');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_deliveries_staff_status ON deliveries (assigned_staff_id, delivery_status)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND INDEX_NAME = 'idx_deliveries_status_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_deliveries_status_created ON deliveries (delivery_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_user_status_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_notifications_user_status_created ON notifications (user_id, status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_type_status_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_notifications_type_status_created ON notifications (type, status, created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_logs_created');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_audit_logs_created ON audit_logs (created_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND INDEX_NAME = 'idx_password_resets_user_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_password_resets_user_expires ON password_resets (user_id, expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND INDEX_NAME = 'idx_password_resets_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_password_resets_expires ON password_resets (expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_index := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_tokens' AND INDEX_NAME = 'idx_remember_tokens_expires');
SET @ddl := IF(@has_index = 0, 'CREATE INDEX idx_remember_tokens_expires ON remember_tokens (expires_at)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
