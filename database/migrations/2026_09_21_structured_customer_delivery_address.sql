-- Structured customer delivery-address upgrade
--
-- Import this file with the existing application database selected in
-- phpMyAdmin. Existing users.address and delivery records are preserved.
-- Legacy free-form addresses are intentionally not split automatically because
-- their street/barangay/city/province boundaries cannot be inferred safely.
-- It uses INFORMATION_SCHEMA checks rather than ADD COLUMN IF NOT EXISTS so
-- it is safe to import repeatedly on both MySQL and MariaDB.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @address_schema = DATABASE();

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'delivery_street') = 0,
    'ALTER TABLE users ADD COLUMN delivery_street VARCHAR(255) NULL AFTER address',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'delivery_barangay') = 0,
    'ALTER TABLE users ADD COLUMN delivery_barangay VARCHAR(120) NULL AFTER delivery_street',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'delivery_city') = 0,
    'ALTER TABLE users ADD COLUMN delivery_city VARCHAR(120) NULL AFTER delivery_barangay',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'delivery_province') = 0,
    'ALTER TABLE users ADD COLUMN delivery_province VARCHAR(120) NULL AFTER delivery_city',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'delivery_landmark') = 0,
    'ALTER TABLE users ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_province',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SET @address_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @address_schema AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'delivery_landmark') = 0,
    'ALTER TABLE deliveries ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_address',
    'DO 0'
);
PREPARE address_statement FROM @address_sql;
EXECUTE address_statement;
DEALLOCATE PREPARE address_statement;

SELECT 'Structured customer delivery-address upgrade complete.' AS migration_result;
