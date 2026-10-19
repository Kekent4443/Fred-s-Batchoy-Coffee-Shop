
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS email_notifications;
DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS remember_tokens;
DROP TABLE IF EXISTS record_states;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS failed_logins;
DROP TABLE IF EXISTS login_history;
DROP TABLE IF EXISTS report_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS registration_verifications;
DROP TABLE IF EXISTS support_internal_notes;
DROP TABLE IF EXISTS support_handoffs;
DROP TABLE IF EXISTS support_messages;
DROP TABLE IF EXISTS support_faqs;
DROP TABLE IF EXISTS support_conversations;
DROP TABLE IF EXISTS bulk_discount_rules;
DROP TABLE IF EXISTS contact_messages;
DROP TABLE IF EXISTS delivery_history;
DROP TABLE IF EXISTS payment_review_history;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS promo_codes;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS gcash_payment_accounts;
DROP TABLE IF EXISTS receipt_settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS shop_locations;


CREATE TABLE shop_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_name VARCHAR(180) NOT NULL,
    complete_address TEXT NOT NULL,
    google_maps_url VARCHAR(500) NOT NULL,
    google_maps_embed_url VARCHAR(500) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    contact_number VARCHAR(40) NOT NULL,
    email_address VARCHAR(150) NULL,
    operating_hours VARCHAR(180) NULL,
    facebook_label VARCHAR(120) NULL,
    facebook_url VARCHAR(500) NULL,
    support_heading VARCHAR(180) NULL,
    support_description VARCHAR(500) NULL,
    landmark TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shop_locations_status (status),
    INDEX idx_shop_locations_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    delivery_street VARCHAR(255) NULL,
    delivery_barangay VARCHAR(120) NULL,
    delivery_city VARCHAR(120) NULL,
    delivery_province VARCHAR(120) NULL,
    delivery_landmark VARCHAR(255) NULL,
    role ENUM('administrator', 'manager', 'staff', 'rider', 'customer') NOT NULL DEFAULT 'customer',
    status ENUM('active', 'inactive', 'pending_verification', 'rejected') NOT NULL DEFAULT 'active',
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    deleted_previous_status VARCHAR(30) NULL,
    profile_image VARCHAR(255) NULL,
    valid_id_path VARCHAR(255) NULL,
    verification_status VARCHAR(30) NULL,
    verification_reviewed_by INT UNSIGNED NULL,
    verification_reviewed_name VARCHAR(150) NULL,
    verification_reviewed_role VARCHAR(50) NULL,
    verification_reviewed_at DATETIME NULL,
    verification_notes TEXT NULL,
    email_verified_at DATETIME NULL,
    can_edit_shop_location TINYINT(1) NOT NULL DEFAULT 0,
    assigned_shop_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status),
    INDEX idx_users_status_created (status, created_at),
    INDEX idx_users_phone (phone),
    INDEX idx_users_verification (verification_status, verification_reviewed_at),
    INDEX idx_users_location_permission (role, can_edit_shop_location, assigned_shop_id),
    INDEX idx_users_deleted_at (deleted_at, status),
    CONSTRAINT fk_users_assigned_shop FOREIGN KEY (assigned_shop_id) REFERENCES shop_locations(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_users_verification_reviewed_by FOREIGN KEY (verification_reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registration_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    last_sent_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_registration_verification_user (user_id),
    INDEX idx_registration_verification_expiry (expires_at),
    CONSTRAINT fk_registration_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gcash_payment_accounts (
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

CREATE TABLE receipt_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    business_name VARCHAR(150) NOT NULL,
    business_address VARCHAR(255) NOT NULL,
    contact_number VARCHAR(60) NOT NULL,
    contact_email VARCHAR(150) NULL,
    social_media VARCHAR(150) NULL,
    site_logo VARCHAR(255) NULL,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    footer_message VARCHAR(500) NOT NULL,
    paper_width ENUM('58mm', '80mm') NOT NULL DEFAULT '58mm',
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receipt_settings_updated_by (updated_by),
    CONSTRAINT fk_receipt_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO receipt_settings (id, business_name, business_address, contact_number, contact_email, social_media, tax_rate, service_fee, delivery_fee, footer_message, paper_width, updated_by)
VALUES (1, 'Fred''s Batchoy', 'Business address not configured', 'Contact number not configured', 'georgetabanao123@gmail.com', NULL, 12.00, 0.00, 0.00, 'Thank you for your order!', '58mm', NULL);

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    shop_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    stock_quantity INT NOT NULL DEFAULT 0,
    image_url VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_public_catalog (status, is_available, category_id, stock_quantity),
    INDEX idx_products_shop_catalog (shop_id, status, is_available, stock_quantity),
    INDEX idx_products_name (name),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE,
    CONSTRAINT fk_products_shop FOREIGN KEY (shop_id) REFERENCES shop_locations(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bulk_discount_rules (
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

CREATE TABLE promo_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    description VARCHAR(255) NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    minimum_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    maximum_discount DECIMAL(10,2) NULL,
    usage_limit INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_promo_codes_code (code),
    INDEX idx_promo_codes_active_window (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    prepared_by_staff_id INT UNSIGNED NULL,
    assigned_staff_id INT UNSIGNED NULL COMMENT 'Legacy column name; stores the assigned rider user id',
    checkout_idempotency_key CHAR(64) NULL,
    order_status ENUM('pending', 'processing', 'confirmed', 'assigned', 'out_for_delivery', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    payment_method ENUM('gcash', 'cash') NOT NULL DEFAULT 'cash',
    payment_status ENUM('pending', 'accepted', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    delivery_status ENUM('pending', 'assigned', 'out_for_delivery', 'delivered', 'issue_reported', 'failed_delivery', 'cancelled') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    promo_code VARCHAR(40) NULL,
    promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_customer_created (customer_id, created_at),
    INDEX idx_orders_preparer_status (prepared_by_staff_id, order_status),
    INDEX idx_orders_staff_status (assigned_staff_id, order_status),
    UNIQUE KEY uniq_orders_checkout_idempotency (checkout_idempotency_key),
    INDEX idx_orders_payment_created (payment_status, created_at),
    INDEX idx_orders_statuses_created (order_status, payment_status, delivery_status, created_at),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE,
    CONSTRAINT fk_orders_preparer FOREIGN KEY (prepared_by_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_orders_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
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
    INDEX idx_contact_messages_status (status, is_read, created_at),
    INDEX idx_contact_messages_category (category),
    INDEX idx_contact_messages_created (created_at),
    CONSTRAINT fk_contact_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_messages_order FOREIGN KEY (related_order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_messages_responded_by FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product_order (product_id, order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    payment_method ENUM('gcash', 'cash') NOT NULL DEFAULT 'cash',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    promo_code VARCHAR(40) NULL,
    promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    transaction_status ENUM('pending', 'accepted', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    gcash_reference VARCHAR(120) NULL,
    gcash_reference_hash CHAR(64) NULL,
    proof_of_payment_path VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transactions_order (order_id),
    UNIQUE KEY uniq_transactions_gcash_reference_hash (gcash_reference_hash),
    INDEX idx_transactions_status_created (transaction_status, created_at),
    INDEX idx_transactions_recorded_by (recorded_by, created_at),
    CONSTRAINT fk_transactions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_review_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    action ENUM('submitted', 'verified', 'rejected', 'resubmitted') NOT NULL,
    reference_no VARCHAR(120) NULL,
    proof_path VARCHAR(255) NULL,
    reviewed_by INT UNSIGNED NULL,
    reason TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_review_transaction_created (transaction_id, created_at),
    INDEX idx_payment_review_order_created (order_id, created_at),
    CONSTRAINT fk_payment_review_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_review_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    assigned_staff_id INT UNSIGNED NULL,
    delivery_status ENUM('pending', 'assigned', 'out_for_delivery', 'delivered', 'issue_reported', 'failed_delivery', 'cancelled') NOT NULL DEFAULT 'pending',
    delivery_address TEXT NOT NULL,
    delivery_landmark VARCHAR(255) NULL,
    notes TEXT NULL,
    issue_notes TEXT NULL,
    delivered_at DATETIME NULL,
    estimated_delivery_at DATETIME NULL,
    proof_photo_path VARCHAR(255) NULL,
    recipient_name VARCHAR(150) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_deliveries_order (order_id),
    INDEX idx_deliveries_staff_status (assigned_staff_id, delivery_status),
    INDEX idx_deliveries_status_created (delivery_status, created_at),
    CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_deliveries_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE delivery_history (
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

CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    role VARCHAR(50) NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(500) NULL,
    event_key VARCHAR(191) NULL,
    type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
    status ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_status_created (user_id, status, created_at),
    INDEX idx_notifications_type_status_created (type, status, created_at),
    INDEX idx_notifications_user_role_read_created (user_id, role, is_read, created_at),
    INDEX idx_notifications_created_at (created_at),
    UNIQUE KEY uniq_notifications_event_recipient (user_id, event_key),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    assigned_user_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    category VARCHAR(60) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    automation_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_customer_message_at DATETIME NULL,
    last_human_reply_at DATETIME NULL,
    last_auto_reply_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    INDEX idx_support_conversations_customer (customer_id, status, created_at),
    INDEX idx_support_conversations_assigned (assigned_user_id, status, updated_at),
    INDEX idx_support_conversations_order (order_id),
    INDEX idx_support_conversations_product (product_id),
    INDEX idx_support_conversations_status (status, updated_at),
    INDEX idx_support_conversations_last_customer (last_customer_message_at),
    CONSTRAINT fk_support_conversations_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_conversations_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_support_conversations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_support_conversations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NULL,
    sender_type VARCHAR(40) NOT NULL,
    message TEXT NOT NULL,
    is_automated TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    reply_to_message_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_messages_conversation (conversation_id, id),
    INDEX idx_support_messages_read (conversation_id, is_read, sender_type),
    INDEX idx_support_messages_created (created_at),
    UNIQUE KEY uniq_support_auto_reply (conversation_id, reply_to_message_id, is_automated),
    CONSTRAINT fk_support_messages_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_support_messages_reply_to FOREIGN KEY (reply_to_message_id) REFERENCES support_messages(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_faqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(60) NOT NULL,
    question VARCHAR(255) NOT NULL,
    keywords TEXT NOT NULL,
    answer TEXT NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_support_faq_question (question),
    INDEX idx_support_faqs_category_status (category, status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_handoffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    trigger_message_id INT UNSIGNED NOT NULL,
    assigned_user_id INT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_support_handoffs_conversation (conversation_id, status),
    UNIQUE KEY uniq_support_handoffs_trigger (trigger_message_id),
    INDEX idx_support_handoffs_assigned (assigned_user_id, status),
    CONSTRAINT fk_support_handoffs_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_handoffs_trigger FOREIGN KEY (trigger_message_id) REFERENCES support_messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_handoffs_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_internal_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_internal_notes_conversation (conversation_id, created_at),
    CONSTRAINT fk_support_internal_notes_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_internal_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    purpose VARCHAR(32) NOT NULL DEFAULT 'password_reset',
    code_hash VARCHAR(255) NULL,
    delivery_channel VARCHAR(20) NULL,
    destination VARCHAR(180) NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    verification_token_hash CHAR(64) NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user_expires (user_id, expires_at),
    INDEX idx_password_resets_user_created (user_id, created_at),
    INDEX idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    password_reset_id INT UNSIGNED NULL,
    email VARCHAR(150) NOT NULL,
    recipient_name VARCHAR(150) NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    html_message MEDIUMTEXT NULL,
    message_type VARCHAR(50) NOT NULL DEFAULT 'notification',
    dedupe_key CHAR(64) NULL,
    status ENUM('queued', 'sent', 'delivered', 'deferred', 'failed', 'expired') NOT NULL DEFAULT 'queued',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    claim_token CHAR(64) NULL,
    locked_until DATETIME NULL,
    provider_message_id VARCHAR(255) NULL,
    provider_response TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    failed_at DATETIME NULL,
    expires_at DATETIME NULL,
    UNIQUE KEY uniq_email_dedupe (dedupe_key),
    INDEX idx_email_user (user_id),
    INDEX idx_email_password_reset (password_reset_id),
    INDEX idx_email_queue_ready (status, next_attempt_at, locked_until),
    INDEX idx_email_provider_message (provider_message_id),
    CONSTRAINT fk_email_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_email_notifications_password_reset FOREIGN KEY (password_reset_id) REFERENCES password_resets(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key CHAR(64) NOT NULL,
    scope VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uniq_rate_key_scope (rate_key, scope),
    INDEX idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(180) NOT NULL,
    generated_by INT UNSIGNED NULL,
    role_scope VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_logs_generated_created (generated_by, created_at),
    CONSTRAINT fk_report_logs_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_created (created_at),
    INDEX idx_audit_logs_user_created (user_id, created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    login_source VARCHAR(20) NOT NULL DEFAULT 'password',
    new_ip TINYINT(1) NOT NULL DEFAULT 0,
    new_browser TINYINT(1) NOT NULL DEFAULT 0,
    login_at DATETIME(6) NOT NULL,
    logout_at DATETIME(6) NULL,
    last_activity_at DATETIME(6) NOT NULL,
    end_reason VARCHAR(20) NULL,
    INDEX idx_login_history_login (login_at, id),
    INDEX idx_login_history_logout (logout_at, id),
    INDEX idx_login_history_role_login (role, login_at),
    INDEX idx_login_history_user_login (user_id, login_at),
    INDEX idx_login_history_activity (logout_at, last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE failed_logins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    email_tried VARCHAR(150) NOT NULL,
    role_tried VARCHAR(30) NULL,
    failure_reason VARCHAR(40) NOT NULL,
    lock_triggered TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    attempted_at DATETIME(6) NOT NULL,
    INDEX idx_failed_logins_attempted (attempted_at, id),
    INDEX idx_failed_logins_role_attempted (role_tried, attempted_at),
    INDEX idx_failed_logins_email_attempted (email_tried, attempted_at),
    INDEX idx_failed_logins_user_attempted (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE record_states (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_type VARCHAR(40) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    record_status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active',
    snapshot_data JSON NULL,
    archived_at DATETIME NULL,
    deleted_at DATETIME NULL,
    recovered_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_record_state (record_type, record_id),
    CONSTRAINT fk_record_states_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_remember_tokens_expires (expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    checksum CHAR(64) NOT NULL,
    batch INT UNSIGNED NOT NULL DEFAULT 1,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Database-level defense for the single primary Administrator invariant.
-- Application validation remains authoritative when hosting does not grant
-- TRIGGER privileges, but supported MySQL/MariaDB installations also reject
-- direct SQL attempts.
DELIMITER $$

CREATE TRIGGER users_single_administrator_before_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.role = 'administrator'
       AND EXISTS (SELECT 1 FROM users WHERE role = 'administrator') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one Administrator account is allowed.';
    END IF;
END$$

CREATE TRIGGER users_single_administrator_before_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.role <> 'administrator' AND NEW.role = 'administrator' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existing accounts cannot be promoted to Administrator.';
    END IF;

    IF OLD.role = 'administrator'
       AND (NEW.role <> 'administrator' OR NEW.status <> 'active' OR NEW.deleted_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The primary Administrator cannot be demoted, deactivated, or deleted.';
    END IF;
END$$

CREATE TRIGGER users_single_administrator_before_delete
BEFORE DELETE ON users
FOR EACH ROW
BEGIN
    IF OLD.role = 'administrator' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The primary Administrator cannot be deleted.';
    END IF;
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
