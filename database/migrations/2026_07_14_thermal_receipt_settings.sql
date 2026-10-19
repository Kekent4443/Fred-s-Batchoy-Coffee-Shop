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
    paper_width ENUM('58mm', '80mm') NOT NULL DEFAULT '80mm',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_settings_updated_by (updated_by),
    CONSTRAINT fk_receipt_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO receipt_settings
    (id, business_name, business_address, contact_number, contact_email, social_media, tax_rate, service_fee, delivery_fee, footer_message, paper_width, updated_by)
VALUES
    (1, 'Fred''s Batchoy', 'Business address not configured', 'Contact number not configured', 'georgetabanao123@gmail.com', NULL, 12.00, 0.00, 0.00, 'Thank you for your order!', '80mm', NULL);

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00 AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
    ADD COLUMN IF NOT EXISTS service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER service_fee;

UPDATE orders
SET tax_rate = 12.00
WHERE tax_rate = 0.00 AND total_amount > 0;

UPDATE orders
SET tax_amount = ROUND(total_amount - (total_amount / (1 + (tax_rate / 100))), 2)
WHERE tax_amount = 0.00 AND tax_rate > 0 AND total_amount > 0;
