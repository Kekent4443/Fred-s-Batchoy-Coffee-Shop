-- Adds persistent verification state to the existing password-reset table.
-- Safe to run once on localhost or InfinityFree/phpMyAdmin.
ALTER TABLE password_resets
    ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at,
    ADD COLUMN verified_at DATETIME NULL AFTER attempt_count,
    ADD COLUMN verification_token_hash CHAR(64) NULL AFTER verified_at,
    ADD COLUMN is_used TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_token_hash;

UPDATE password_resets
SET is_used = 1
WHERE used_at IS NOT NULL;
