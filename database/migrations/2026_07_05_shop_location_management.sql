CREATE TABLE IF NOT EXISTS shop_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_name VARCHAR(180) NOT NULL,
    complete_address TEXT NOT NULL,
    google_maps_url VARCHAR(500) NOT NULL,
    google_maps_embed_url VARCHAR(500) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    contact_number VARCHAR(40) NOT NULL,
    landmark TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shop_locations_status (status),
    INDEX idx_shop_locations_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS can_edit_shop_location TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_notes,
    ADD COLUMN IF NOT EXISTS assigned_shop_id INT UNSIGNED NULL AFTER can_edit_shop_location;

CREATE INDEX IF NOT EXISTS idx_users_location_permission ON users (role, can_edit_shop_location, assigned_shop_id);

INSERT INTO shop_locations (
    shop_name,
    complete_address,
    google_maps_url,
    google_maps_embed_url,
    latitude,
    longitude,
    contact_number,
    landmark,
    status,
    created_at,
    updated_at
)
SELECT
    'Fred''s Batchoy and Coffee Shop',
    'Exact shop location saved from the existing Google Maps pin.',
    'https://maps.app.goo.gl/n4Jg1tJdT8BXBvko8',
    'https://www.google.com/maps?q=10.1249048,123.2742754&z=17&output=embed',
    10.1249048,
    123.2742754,
    '09515882546',
    'Use the Google Maps pin for directions and nearby landmarks.',
    'active',
    NOW(),
    NOW()
WHERE NOT EXISTS (SELECT 1 FROM shop_locations LIMIT 1);

SET @shop_location_fk_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_assigned_shop'
);

SET @shop_location_fk_sql := IF(
    @shop_location_fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_assigned_shop FOREIGN KEY (assigned_shop_id) REFERENCES shop_locations(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE shop_location_fk_stmt FROM @shop_location_fk_sql;
EXECUTE shop_location_fk_stmt;
DEALLOCATE PREPARE shop_location_fk_stmt;
