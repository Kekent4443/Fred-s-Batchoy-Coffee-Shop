CREATE TABLE IF NOT EXISTS gcash_payment_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(20) NOT NULL,
    qr_code_path VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    updated_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gcash_accounts_status_updated (status, updated_at, id),
    INDEX idx_gcash_accounts_updated_by (updated_by),
    CONSTRAINT fk_gcash_accounts_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
