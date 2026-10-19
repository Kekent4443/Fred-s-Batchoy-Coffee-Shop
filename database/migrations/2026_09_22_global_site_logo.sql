-- Adds the optional global branding logo without changing existing records.
SET @site_logo_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'receipt_settings'
      AND COLUMN_NAME = 'site_logo'
);

SET @site_logo_migration_sql := IF(
    @site_logo_column_exists = 0,
    'ALTER TABLE receipt_settings ADD COLUMN site_logo VARCHAR(255) NULL AFTER social_media',
    'SELECT 1'
);

PREPARE site_logo_migration_statement FROM @site_logo_migration_sql;
EXECUTE site_logo_migration_statement;
DEALLOCATE PREPARE site_logo_migration_statement;
