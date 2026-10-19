-- Assign products to shops so Manager product reads and writes can be scoped
-- server-side. Safe for an existing installation: no product rows are removed.
-- Existing unassigned products are attached to the first active shop, or the
-- first shop by ID when no shop is active. If no shop exists they remain NULL.

DELIMITER $$
CREATE PROCEDURE migrate_manager_product_shop_scope()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'products'
          AND column_name = 'shop_id'
    ) THEN
        ALTER TABLE products
            ADD COLUMN shop_id INT UNSIGNED NULL AFTER category_id;
    END IF;

    UPDATE products
    SET shop_id = (
        SELECT selected_shop.id
        FROM (
            SELECT id
            FROM shop_locations
            ORDER BY (status = 'active') DESC, id ASC
            LIMIT 1
        ) AS selected_shop
    )
    WHERE shop_id IS NULL
      AND EXISTS (SELECT 1 FROM shop_locations LIMIT 1);

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'products'
          AND index_name = 'idx_products_shop_catalog'
    ) THEN
        CREATE INDEX idx_products_shop_catalog
            ON products (shop_id, status, is_available, stock_quantity);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'products'
          AND constraint_name = 'fk_products_shop'
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
        ALTER TABLE products
            ADD CONSTRAINT fk_products_shop
            FOREIGN KEY (shop_id) REFERENCES shop_locations(id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$
CALL migrate_manager_product_shop_scope()$$
DROP PROCEDURE migrate_manager_product_shop_scope$$
DELIMITER ;
