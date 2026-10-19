CREATE TABLE IF NOT EXISTS bulk_discount_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    min_quantity INT UNSIGNED NOT NULL,
    max_quantity INT UNSIGNED NULL,
    discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bulk_discount_rules_status_qty (status, min_quantity, max_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER notes,
    ADD COLUMN IF NOT EXISTS discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_rate;

ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount,
    ADD COLUMN IF NOT EXISTS discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_rate;

INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
SELECT '5 to 9 items', 5, 9, 5.00, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM bulk_discount_rules WHERE min_quantity = 5 AND max_quantity = 9);

INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
SELECT '10 or more items', 10, NULL, 10.00, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM bulk_discount_rules WHERE min_quantity = 10 AND max_quantity IS NULL);

UPDATE orders SET subtotal_amount = total_amount WHERE subtotal_amount = 0.00 AND total_amount > 0;
UPDATE transactions SET subtotal_amount = amount WHERE subtotal_amount = 0.00 AND amount > 0;
