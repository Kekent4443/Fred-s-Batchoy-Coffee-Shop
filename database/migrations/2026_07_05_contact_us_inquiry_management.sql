ALTER TABLE shop_locations
    ADD COLUMN IF NOT EXISTS email_address VARCHAR(150) NULL AFTER contact_number,
    ADD COLUMN IF NOT EXISTS operating_hours VARCHAR(180) NULL AFTER email_address,
    ADD COLUMN IF NOT EXISTS facebook_label VARCHAR(120) NULL AFTER operating_hours,
    ADD COLUMN IF NOT EXISTS facebook_url VARCHAR(500) NULL AFTER facebook_label,
    ADD COLUMN IF NOT EXISTS support_heading VARCHAR(180) NULL AFTER facebook_url,
    ADD COLUMN IF NOT EXISTS support_description VARCHAR(500) NULL AFTER support_heading;

UPDATE shop_locations
SET email_address = 'georgetabanao123@gmail.com'
WHERE email_address IS NULL OR email_address = '' OR email_address LIKE '%.local';

UPDATE shop_locations
SET operating_hours = 'Open daily. Contact us for today''s exact schedule.'
WHERE operating_hours IS NULL OR operating_hours = '';

UPDATE shop_locations
SET facebook_label = 'Facebook'
WHERE facebook_label IS NULL OR facebook_label = '';

UPDATE shop_locations
SET facebook_url = 'https://www.facebook.com/george.tabanao.543'
WHERE facebook_url IS NULL OR facebook_url = '';

UPDATE shop_locations
SET support_heading = 'Need help?'
WHERE support_heading IS NULL OR support_heading = '';

UPDATE shop_locations
SET support_description = 'Reach us for orders, questions, and customer support.'
WHERE support_description IS NULL OR support_description = '';

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    related_order_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    contact_number VARCHAR(40) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    category VARCHAR(60) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'new',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    internal_note TEXT NULL,
    response_message TEXT NULL,
    responded_by INT UNSIGNED NULL,
    responded_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contact_messages_user (user_id),
    INDEX idx_contact_messages_order (related_order_id),
    INDEX idx_contact_messages_status (status),
    INDEX idx_contact_messages_category (category),
    INDEX idx_contact_messages_created (created_at),
    CONSTRAINT fk_contact_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_messages_order FOREIGN KEY (related_order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_messages_responded_by FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
