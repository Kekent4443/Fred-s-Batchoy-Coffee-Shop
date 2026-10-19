-- Consolidated customer-email and bulk-discount data upgrade.
-- Import this file once in phpMyAdmin after selecting the existing application
-- database. It is safe to re-import, but back up the database first.
-- Requires the existing users, registration_verifications, password_resets,
-- email_notifications, and bulk_discount_rules tables from the earlier application migrations.
-- No customer, order, or audit records are deleted.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Older installations relied on ensure_password_reset_schema() during HTTP
-- requests for these columns. Install them explicitly before the data upgrade.
SET @has_reset_code_hash := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'code_hash'
);
SET @reset_code_hash_ddl := IF(@has_reset_code_hash = 0,
    'ALTER TABLE password_resets ADD COLUMN code_hash VARCHAR(255) NULL AFTER token', 'SELECT 1');
PREPARE reset_code_hash_stmt FROM @reset_code_hash_ddl;
EXECUTE reset_code_hash_stmt;
DEALLOCATE PREPARE reset_code_hash_stmt;

SET @has_reset_delivery_channel := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'delivery_channel'
);
SET @reset_delivery_channel_ddl := IF(@has_reset_delivery_channel = 0,
    'ALTER TABLE password_resets ADD COLUMN delivery_channel VARCHAR(20) NULL AFTER code_hash', 'SELECT 1');
PREPARE reset_delivery_channel_stmt FROM @reset_delivery_channel_ddl;
EXECUTE reset_delivery_channel_stmt;
DEALLOCATE PREPARE reset_delivery_channel_stmt;

SET @has_reset_destination := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'destination'
);
SET @reset_destination_ddl := IF(@has_reset_destination = 0,
    'ALTER TABLE password_resets ADD COLUMN destination VARCHAR(180) NULL AFTER delivery_channel', 'SELECT 1');
PREPARE reset_destination_stmt FROM @reset_destination_ddl;
EXECUTE reset_destination_stmt;
DEALLOCATE PREPARE reset_destination_stmt;

SET @has_reset_used_at := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'used_at'
);
SET @reset_used_at_ddl := IF(@has_reset_used_at = 0,
    'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL AFTER expires_at', 'SELECT 1');
PREPARE reset_used_at_stmt FROM @reset_used_at_ddl;
EXECUTE reset_used_at_stmt;
DEALLOCATE PREPARE reset_used_at_stmt;

SET @has_reset_purpose := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'purpose'
);
SET @reset_purpose_ddl := IF(
    @has_reset_purpose = 0,
    'ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(32) NOT NULL DEFAULT ''password_reset'' AFTER token',
    'SELECT 1'
);
PREPARE reset_purpose_stmt FROM @reset_purpose_ddl;
EXECUTE reset_purpose_stmt;
DEALLOCATE PREPARE reset_purpose_stmt;

-- A historical migration marked some active customers verified without an
-- ownership code. Clear only unproven verification flags; keep accounts and
-- genuine verification evidence unchanged. These customers can verify later.
UPDATE users u
LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
SET u.email_verified_at = NULL
WHERE u.role = 'customer' AND u.email_verified_at IS NOT NULL
  AND (rv.id IS NULL OR rv.verified_at IS NULL);

-- Preserve outstanding non-customer reset links. Customer recovery now
-- requires proof of the registered email through a purpose-bound code.
UPDATE password_resets pr
INNER JOIN users u ON u.id = pr.user_id
SET pr.purpose = 'legacy_link'
WHERE u.role <> 'customer' AND pr.code_hash IS NULL
  AND pr.purpose = 'password_reset';

UPDATE password_resets pr
INNER JOIN users u ON u.id = pr.user_id
SET pr.is_used = 1, pr.used_at = COALESCE(pr.used_at, NOW())
WHERE u.role = 'customer' AND pr.is_used = 0
  AND pr.verified_at IS NULL AND pr.code_hash IS NULL;

UPDATE password_resets pr
INNER JOIN users u ON u.id = pr.user_id
SET pr.is_used = 1, pr.used_at = COALESCE(pr.used_at, NOW()),
    pr.code_hash = NULL, pr.verification_token_hash = NULL
WHERE u.role = 'customer' AND u.email_verified_at IS NULL AND pr.is_used = 0;

UPDATE email_notifications en
INNER JOIN password_resets pr ON pr.id = en.password_reset_id
SET en.status = 'expired', en.failed_at = NOW(), en.message = '', en.html_message = NULL,
    en.claim_token = NULL, en.locked_until = NULL
WHERE pr.is_used = 1 AND en.status IN ('queued', 'deferred');

-- A delivered or expired verification code must not remain in the mail queue body.
UPDATE email_notifications
SET message = '', html_message = NULL
WHERE message_type IN ('registration_verification', 'password_reset', 'admin_password_reset')
  AND status IN ('sent', 'delivered', 'expired', 'failed')
  AND (message <> '' OR html_message IS NOT NULL);

-- Preserve existing open-ended discount tiers without changing the schema.
-- INT UNSIGNED's upper bound is the largest accepted quantity.
UPDATE bulk_discount_rules
SET max_quantity = 4294967295, updated_at = NOW()
WHERE max_quantity IS NULL;

-- Existing equal/reversed ranges cannot remain active. Keep their records for review.
UPDATE bulk_discount_rules
SET status = 'inactive', updated_at = NOW()
WHERE status = 'active' AND max_quantity <= min_quantity;
