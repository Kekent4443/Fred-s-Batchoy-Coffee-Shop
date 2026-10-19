ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS estimated_delivery_at DATETIME NULL AFTER delivered_at;
ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS proof_photo_path VARCHAR(255) NULL AFTER estimated_delivery_at;
ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS recipient_name VARCHAR(150) NULL AFTER proof_photo_path;

-- Riders are a separate operational role: staff prepare orders, riders deliver them.
ALTER TABLE users MODIFY role ENUM('administrator', 'manager', 'staff', 'rider', 'customer') NOT NULL DEFAULT 'customer';
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER recorded_by;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL AFTER reviewed_at;

CREATE TABLE IF NOT EXISTS delivery_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    changed_by INT UNSIGNED NULL,
    note TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_history_delivery_created (delivery_id, created_at),
    INDEX idx_delivery_history_order_created (order_id, created_at),
    CONSTRAINT fk_delivery_history_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_delivery_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_delivery_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
