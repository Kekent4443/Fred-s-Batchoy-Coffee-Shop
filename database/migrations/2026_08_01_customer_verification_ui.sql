-- Customer verification interface update
-- Compatible with phpMyAdmin and standard MySQL/MariaDB installations.
--
-- This release removes the duplicate "Review ID" interface control, removes
-- registration-email verification from the customer identity-review flow,
-- and improves the action layout. It does not change stored verification
-- data, database columns, indexes, foreign keys, or status values.
--
-- Import this file while the project's configured database is selected in
-- phpMyAdmin. No USE statement is included so shared-hosting database names
-- and prefixes remain untouched.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Deliberate no-op: identity review uses the existing users verification
-- fields and no longer reads registration email-verification records. This
-- result row gives phpMyAdmin a clear successful-import confirmation without
-- requiring schema privileges.
SELECT
    'Customer identity-verification update complete; no database changes required.'
    AS migration_result;
