<?php

declare(strict_types=1);

function database_column_exists(string $table, string $column): bool
{
    $database = db_find('SELECT DATABASE() AS name')['name'] ?? '';

    if ($database === '') {
        return false;
    }

    return db_find(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        'sss',
        [$database, $table, $column]
    ) !== null;
}

function database_index_exists(string $table, string $index): bool
{
    $database = db_find('SELECT DATABASE() AS name')['name'] ?? '';

    if ($database === '') {
        return false;
    }

    return db_find(
        'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        'sss',
        [$database, $table, $index]
    ) !== null;
}

function database_constraint_exists(string $table, string $constraint): bool
{
    $database = db_find('SELECT DATABASE() AS name')['name'] ?? '';

    if ($database === '') {
        return false;
    }

    return db_find(
        'SELECT CONSTRAINT_NAME
         FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
         LIMIT 1',
        'sss',
        [$database, $table, $constraint]
    ) !== null;
}

function database_duplicate_count(string $table, string $column): int
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column) ?? '';

    if ($table === '' || $column === '') {
        return 0;
    }

    $row = db_find(
        'SELECT COUNT(*) AS total
         FROM (
             SELECT `' . $column . '`
             FROM `' . $table . '`
             WHERE `' . $column . '` IS NOT NULL
             GROUP BY `' . $column . '`
             HAVING COUNT(*) > 1
         ) duplicates'
    );

    return (int) ($row['total'] ?? 0);
}

function ensure_pricing_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $tableAlreadyExists = db_find(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
        's',
        ['bulk_discount_rules']
    ) !== null;

    db_execute(
        'CREATE TABLE IF NOT EXISTS bulk_discount_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL,
            min_quantity INT UNSIGNED NOT NULL,
            max_quantity INT UNSIGNED NULL,
            discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bulk_discount_rules_status_qty (status, min_quantity, max_quantity)
        ) ENGINE=InnoDB'
    );

    foreach (['orders', 'transactions'] as $table) {
        $afterColumn = $table === 'orders' ? 'notes' : 'amount';

        if (!database_column_exists($table, 'subtotal_amount')) {
            db_execute('ALTER TABLE ' . $table . ' ADD COLUMN subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ' . $afterColumn);
        }

        if (!database_column_exists($table, 'discount_rate')) {
            db_execute('ALTER TABLE ' . $table . ' ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount');
        }

        if (!database_column_exists($table, 'discount_amount')) {
            db_execute('ALTER TABLE ' . $table . ' ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_rate');
        }
    }

    $existingRules = db_find('SELECT COUNT(*) AS total FROM bulk_discount_rules');

    // Seed only when the table is first created. An intentionally emptied rule
    // list must remain empty after its last rule is deleted.
    if (!$tableAlreadyExists && (int) ($existingRules['total'] ?? 0) === 0) {
        db_execute(
            'INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
             VALUES
             ("5 to 9 items", 5, 9, 5.00, "active", NOW(), NOW()),
             ("10 or more items", 10, 4294967295, 10.00, "active", NOW(), NOW())'
        );
    }

    $checked = true;
}

function ensure_database_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $tables = [
        'users' => 'CREATE TABLE IF NOT EXISTS users (
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
            role ENUM("administrator", "manager", "staff", "rider", "customer") NOT NULL DEFAULT "customer",
            status ENUM("active", "inactive", "pending_verification", "rejected") NOT NULL DEFAULT "active",
            profile_image VARCHAR(255) NULL,
            valid_id_path VARCHAR(255) NULL,
            verification_status VARCHAR(30) NULL,
            verification_reviewed_by INT UNSIGNED NULL,
            verification_reviewed_name VARCHAR(150) NULL,
            verification_reviewed_role VARCHAR(50) NULL,
            verification_reviewed_at DATETIME NULL,
            verification_notes TEXT NULL,
            email_verified_at DATETIME NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'categories' => 'CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description TEXT NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'products' => 'CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            shop_id INT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            stock_quantity INT NOT NULL DEFAULT 0,
            image_url VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_products_shop_catalog (shop_id, status, is_available, stock_quantity)
        ) ENGINE=InnoDB',
        'orders' => 'CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference_no VARCHAR(50) NOT NULL UNIQUE,
            customer_id INT UNSIGNED NOT NULL,
            prepared_by_staff_id INT UNSIGNED NULL,
            assigned_staff_id INT UNSIGNED NULL,
            checkout_idempotency_key CHAR(64) NULL,
            order_status ENUM("pending", "processing", "confirmed", "assigned", "out_for_delivery", "completed", "cancelled") NOT NULL DEFAULT "pending",
            payment_method ENUM("gcash", "cash") NOT NULL DEFAULT "cash",
            payment_status ENUM("pending", "accepted", "paid", "failed", "cancelled") NOT NULL DEFAULT "pending",
            delivery_status ENUM("pending", "assigned", "out_for_delivery", "delivered", "issue_reported", "failed_delivery", "cancelled") NOT NULL DEFAULT "pending",
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
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'order_items' => 'CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'transactions' => 'CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            payment_method ENUM("gcash", "cash") NOT NULL DEFAULT "cash",
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            promo_code VARCHAR(40) NULL,
            promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            transaction_status ENUM("pending", "accepted", "completed", "failed", "cancelled") NOT NULL DEFAULT "pending",
            gcash_reference VARCHAR(120) NULL,
            gcash_reference_hash CHAR(64) NULL,
            proof_of_payment_path VARCHAR(255) NULL,
            recorded_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            rejection_reason TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'promo_codes' => 'CREATE TABLE IF NOT EXISTS promo_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            description VARCHAR(255) NULL,
            discount_type ENUM("percent", "fixed") NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            maximum_discount DECIMAL(10,2) NULL,
            usage_limit INT UNSIGNED NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_promo_codes_code (code),
            INDEX idx_promo_codes_active_window (status, starts_at, ends_at)
        ) ENGINE=InnoDB',
        'payment_review_history' => 'CREATE TABLE IF NOT EXISTS payment_review_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            action ENUM("submitted", "verified", "rejected", "resubmitted") NOT NULL,
            reference_no VARCHAR(120) NULL,
            proof_path VARCHAR(255) NULL,
            reviewed_by INT UNSIGNED NULL,
            reason TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_review_transaction_created (transaction_id, created_at),
            INDEX idx_payment_review_order_created (order_id, created_at)
        ) ENGINE=InnoDB',
        'deliveries' => 'CREATE TABLE IF NOT EXISTS deliveries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            assigned_staff_id INT UNSIGNED NULL,
            delivery_status ENUM("pending", "assigned", "out_for_delivery", "delivered", "issue_reported", "failed_delivery", "cancelled") NOT NULL DEFAULT "pending",
            delivery_address TEXT NOT NULL,
            delivery_landmark VARCHAR(255) NULL,
            notes TEXT NULL,
            issue_notes TEXT NULL,
            delivered_at DATETIME NULL,
            estimated_delivery_at DATETIME NULL,
            proof_photo_path VARCHAR(255) NULL,
            recipient_name VARCHAR(150) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'delivery_history' => 'CREATE TABLE IF NOT EXISTS delivery_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            previous_status VARCHAR(40) NULL,
            new_status VARCHAR(40) NOT NULL,
            changed_by INT UNSIGNED NULL,
            note TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'notifications' => 'CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            role VARCHAR(50) NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500) NULL,
            event_key VARCHAR(191) NULL,
            type ENUM("info", "success", "warning", "danger") NOT NULL DEFAULT "info",
            status ENUM("unread", "read") NOT NULL DEFAULT "unread",
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'email_notifications' => 'CREATE TABLE IF NOT EXISTS email_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            email VARCHAR(150) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            status ENUM("queued", "sent", "failed") NOT NULL DEFAULT "queued",
            provider_response TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL
        ) ENGINE=InnoDB',
        'rate_limits' => 'CREATE TABLE IF NOT EXISTS rate_limits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rate_key CHAR(64) NOT NULL,
            scope VARCHAR(80) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uniq_rate_key_scope (rate_key, scope)
        ) ENGINE=InnoDB',
        'report_logs' => 'CREATE TABLE IF NOT EXISTS report_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_name VARCHAR(180) NOT NULL,
            generated_by INT UNSIGNED NULL,
            role_scope VARCHAR(50) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'audit_logs' => 'CREATE TABLE IF NOT EXISTS audit_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(120) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
    ];

    foreach ($tables as $sql) {
        db_execute($sql);
    }

    ensure_customer_verification_schema();
    ensure_customer_delivery_address_schema();
    ensure_registration_verification_schema();
    ensure_conditional_user_deletion_schema();
    ensure_operational_feature_columns();
    ensure_password_reset_schema();
    ensure_record_management_tables();
    ensure_remember_tokens_table();
    ensure_notification_schema();

    $indexes = [
        ['transactions', 'uniq_transactions_order', 'order_id'],
        ['transactions', 'uniq_transactions_gcash_reference_hash', 'gcash_reference_hash'],
        ['deliveries', 'uniq_deliveries_order', 'order_id'],
        ['orders', 'uniq_orders_checkout_idempotency', 'checkout_idempotency_key'],
    ];

    foreach ($indexes as [$table, $index, $column]) {
        if (!database_index_exists($table, $index) && database_duplicate_count($table, $column) === 0) {
            db_execute('CREATE UNIQUE INDEX ' . $index . ' ON ' . $table . ' (' . $column . ')');
        }
    }

    $foreignKeys = [
        ['users', 'fk_users_verification_reviewed_by', 'ALTER TABLE users ADD CONSTRAINT fk_users_verification_reviewed_by FOREIGN KEY (verification_reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['products', 'fk_products_category', 'ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE'],
        ['orders', 'fk_orders_customer', 'ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE'],
        ['orders', 'fk_orders_preparer', 'ALTER TABLE orders ADD CONSTRAINT fk_orders_preparer FOREIGN KEY (prepared_by_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['orders', 'fk_orders_staff', 'ALTER TABLE orders ADD CONSTRAINT fk_orders_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['order_items', 'fk_order_items_order', 'ALTER TABLE order_items ADD CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE'],
        ['order_items', 'fk_order_items_product', 'ALTER TABLE order_items ADD CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE CASCADE'],
        ['transactions', 'fk_transactions_order', 'ALTER TABLE transactions ADD CONSTRAINT fk_transactions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE'],
        ['transactions', 'fk_transactions_recorded_by', 'ALTER TABLE transactions ADD CONSTRAINT fk_transactions_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['payment_review_history', 'fk_payment_review_transaction', 'ALTER TABLE payment_review_history ADD CONSTRAINT fk_payment_review_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE ON UPDATE CASCADE'],
        ['payment_review_history', 'fk_payment_review_order', 'ALTER TABLE payment_review_history ADD CONSTRAINT fk_payment_review_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE'],
        ['payment_review_history', 'fk_payment_review_user', 'ALTER TABLE payment_review_history ADD CONSTRAINT fk_payment_review_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['deliveries', 'fk_deliveries_order', 'ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE'],
        ['deliveries', 'fk_deliveries_staff', 'ALTER TABLE deliveries ADD CONSTRAINT fk_deliveries_staff FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['notifications', 'fk_notifications_user', 'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['email_notifications', 'fk_email_notifications_user', 'ALTER TABLE email_notifications ADD CONSTRAINT fk_email_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['report_logs', 'fk_report_logs_user', 'ALTER TABLE report_logs ADD CONSTRAINT fk_report_logs_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
        ['audit_logs', 'fk_audit_logs_user', 'ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'],
    ];

    foreach ($foreignKeys as [$table, $constraint, $sql]) {
        if (database_constraint_exists($table, $constraint)) {
            continue;
        }

        try {
            db_execute($sql);
        } catch (mysqli_sql_exception $exception) {
            if (!in_array((int) $exception->getCode(), [1005, 1022, 1215, 1452, 1826], true)) {
                throw $exception;
            }
        }
    }

    reconcile_order_support_records();

    $checked = true;
}

function reconcile_order_support_records(): void
{
    $statements = [
        'INSERT INTO transactions (order_id, payment_method, amount, transaction_status, gcash_reference, proof_of_payment_path, recorded_by, created_at, updated_at)
         SELECT o.id, o.payment_method, o.total_amount,
                CASE o.payment_status
                    WHEN "accepted" THEN "accepted"
                    WHEN "paid" THEN "completed"
                    WHEN "failed" THEN "failed"
                    WHEN "cancelled" THEN "cancelled"
                    ELSE "pending"
                END,
                NULL, NULL, o.assigned_staff_id, o.created_at, NOW()
         FROM orders o
         LEFT JOIN transactions t ON t.order_id = o.id
         WHERE t.id IS NULL',
        'INSERT INTO deliveries (order_id, assigned_staff_id, delivery_status, delivery_address, delivery_landmark, notes, issue_notes, delivered_at, created_at, updated_at)
         SELECT o.id, o.assigned_staff_id, o.delivery_status, COALESCE(NULLIF(u.address, ""), "Address not available"), NULL, o.notes, NULL,
                CASE WHEN o.delivery_status = "delivered" OR o.order_status = "completed" THEN o.updated_at ELSE NULL END,
                o.created_at, NOW()
         FROM orders o
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN deliveries d ON d.order_id = o.id
         WHERE d.id IS NULL',
    ];

    foreach ($statements as $sql) {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                db_execute($sql);
                break;
            } catch (mysqli_sql_exception $exception) {
                if (!in_array((int) $exception->getCode(), [1062, 1213], true) || $attempt === 3) {
                    throw $exception;
                }

                usleep(50000 * $attempt);
            }
        }
    }
}

function ensure_performance_indexes(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    ensure_rate_limit_schema();

    $indexes = [
        ['users', 'idx_users_role_status', 'CREATE INDEX idx_users_role_status ON users (role, status)'],
        ['users', 'idx_users_status_created', 'CREATE INDEX idx_users_status_created ON users (status, created_at)'],
        ['users', 'idx_users_phone', 'CREATE INDEX idx_users_phone ON users (phone)'],
        ['users', 'idx_users_verification', 'CREATE INDEX idx_users_verification ON users (verification_status, verification_reviewed_at)'],
        ['products', 'idx_products_public_catalog', 'CREATE INDEX idx_products_public_catalog ON products (status, is_available, category_id, stock_quantity)'],
        ['products', 'idx_products_name', 'CREATE INDEX idx_products_name ON products (name)'],
        ['orders', 'idx_orders_customer_created', 'CREATE INDEX idx_orders_customer_created ON orders (customer_id, created_at)'],
        ['orders', 'idx_orders_preparer_status', 'CREATE INDEX idx_orders_preparer_status ON orders (prepared_by_staff_id, order_status)'],
        ['orders', 'idx_orders_staff_status', 'CREATE INDEX idx_orders_staff_status ON orders (assigned_staff_id, order_status)'],
        ['orders', 'idx_orders_payment_created', 'CREATE INDEX idx_orders_payment_created ON orders (payment_status, created_at)'],
        ['orders', 'idx_orders_statuses_created', 'CREATE INDEX idx_orders_statuses_created ON orders (order_status, payment_status, delivery_status, created_at)'],
        ['order_items', 'idx_order_items_product_order', 'CREATE INDEX idx_order_items_product_order ON order_items (product_id, order_id)'],
        ['transactions', 'idx_transactions_status_created', 'CREATE INDEX idx_transactions_status_created ON transactions (transaction_status, created_at)'],
        ['transactions', 'idx_transactions_recorded_by', 'CREATE INDEX idx_transactions_recorded_by ON transactions (recorded_by, created_at)'],
        ['deliveries', 'idx_deliveries_staff_status', 'CREATE INDEX idx_deliveries_staff_status ON deliveries (assigned_staff_id, delivery_status)'],
        ['deliveries', 'idx_deliveries_status_created', 'CREATE INDEX idx_deliveries_status_created ON deliveries (delivery_status, created_at)'],
        ['notifications', 'idx_notifications_user_status_created', 'CREATE INDEX idx_notifications_user_status_created ON notifications (user_id, status, created_at)'],
        ['notifications', 'idx_notifications_type_status_created', 'CREATE INDEX idx_notifications_type_status_created ON notifications (type, status, created_at)'],
        ['notifications', 'idx_notifications_user_role_read_created', 'CREATE INDEX idx_notifications_user_role_read_created ON notifications (user_id, role, is_read, created_at)'],
        ['notifications', 'idx_notifications_created_at', 'CREATE INDEX idx_notifications_created_at ON notifications (created_at)'],
        ['notifications', 'uniq_notifications_event_recipient', 'CREATE UNIQUE INDEX uniq_notifications_event_recipient ON notifications (user_id, event_key)'],
        ['audit_logs', 'idx_audit_logs_created', 'CREATE INDEX idx_audit_logs_created ON audit_logs (created_at)'],
        ['audit_logs', 'idx_audit_logs_user_created', 'CREATE INDEX idx_audit_logs_user_created ON audit_logs (user_id, created_at)'],
        ['report_logs', 'idx_report_logs_generated_created', 'CREATE INDEX idx_report_logs_generated_created ON report_logs (generated_by, created_at)'],
        ['contact_messages', 'idx_contact_messages_status', 'CREATE INDEX idx_contact_messages_status ON contact_messages (status, is_read, created_at)'],
        ['payment_review_history', 'idx_payment_review_order_created', 'CREATE INDEX idx_payment_review_order_created ON payment_review_history (order_id, created_at)'],
        ['registration_verifications', 'idx_registration_verification_expiry', 'CREATE INDEX idx_registration_verification_expiry ON registration_verifications (expires_at)'],
        ['password_resets', 'idx_password_resets_user_expires', 'CREATE INDEX idx_password_resets_user_expires ON password_resets (user_id, expires_at)'],
        ['password_resets', 'idx_password_resets_user_created', 'CREATE INDEX idx_password_resets_user_created ON password_resets (user_id, created_at)'],
        ['password_resets', 'idx_password_resets_expires', 'CREATE INDEX idx_password_resets_expires ON password_resets (expires_at)'],
        ['remember_tokens', 'idx_remember_tokens_expires', 'CREATE INDEX idx_remember_tokens_expires ON remember_tokens (expires_at)'],
    ];

    foreach ($indexes as [$table, $index, $sql]) {
        if (database_index_exists($table, $index)) {
            continue;
        }

        try {
            db_execute($sql);
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1061) {
                throw $exception;
            }
        }
    }

    $checked = true;
}

function ensure_customer_verification_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $database = db_find('SELECT DATABASE() AS name')['name'] ?? '';

    if ($database === '') {
        return;
    }

    $roleColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME = "role" LIMIT 1',
        's',
        [$database]
    );

    if ($roleColumn && !str_contains((string) $roleColumn['COLUMN_TYPE'], "'rider'")) {
        db_execute(
            'ALTER TABLE users MODIFY role ENUM("administrator", "manager", "staff", "rider", "customer") NOT NULL DEFAULT "customer"'
        );
    }

    $statusColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME = "status" LIMIT 1',
        's',
        [$database]
    );

    if ($statusColumn && (!str_contains((string) $statusColumn['COLUMN_TYPE'], "'pending_verification'") || !str_contains((string) $statusColumn['COLUMN_TYPE'], "'rejected'"))) {
        db_execute(
            'ALTER TABLE users
             MODIFY status ENUM("active", "inactive", "pending_verification", "rejected") NOT NULL DEFAULT "active"'
        );
    }

    $columns = [
        'verification_status' => 'ALTER TABLE users ADD COLUMN verification_status VARCHAR(30) NULL DEFAULT NULL AFTER valid_id_path',
        'email_verified_at' => 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER verification_status',
        'verification_reviewed_by' => 'ALTER TABLE users ADD COLUMN verification_reviewed_by INT UNSIGNED NULL AFTER verification_status',
        'verification_reviewed_name' => 'ALTER TABLE users ADD COLUMN verification_reviewed_name VARCHAR(150) NULL DEFAULT NULL AFTER verification_reviewed_by',
        'verification_reviewed_role' => 'ALTER TABLE users ADD COLUMN verification_reviewed_role VARCHAR(50) NULL DEFAULT NULL AFTER verification_reviewed_name',
        'verification_reviewed_at' => 'ALTER TABLE users ADD COLUMN verification_reviewed_at DATETIME NULL AFTER verification_reviewed_role',
        'verification_notes' => 'ALTER TABLE users ADD COLUMN verification_notes TEXT NULL AFTER verification_reviewed_at',
    ];

    foreach ($columns as $column => $sql) {
        if (!database_column_exists('users', $column)) {
            try {
                db_execute($sql);
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }

    db_execute(
        'UPDATE users
         SET verification_status = CASE
                WHEN status = "active" THEN "approved"
                WHEN status = "rejected" THEN "rejected"
                WHEN status = "pending_verification" THEN "pending"
                ELSE verification_status
             END
         WHERE role = "customer" AND verification_status IS NULL'
    );

    $checked = true;
}

function ensure_registration_verification_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;
    if ($checked) {
        return;
    }

    ensure_customer_verification_schema();
    db_execute(
        'CREATE TABLE IF NOT EXISTS registration_verifications (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $checked = true;
}

function ensure_operational_feature_columns(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $database = db_find('SELECT DATABASE() AS name')['name'] ?? '';

    if ($database === '') {
        return;
    }

    $transactionProofColumn = db_find(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "transactions" AND COLUMN_NAME = "proof_of_payment_path" LIMIT 1',
        's',
        [$database]
    );

    if (!$transactionProofColumn) {
        try {
            db_execute('ALTER TABLE transactions ADD COLUMN proof_of_payment_path VARCHAR(255) NULL AFTER gcash_reference');
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }

    $transactionReviewColumns = [
        'reviewed_at' => 'ALTER TABLE transactions ADD COLUMN reviewed_at DATETIME NULL AFTER recorded_by',
        'rejection_reason' => 'ALTER TABLE transactions ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at',
    ];

    foreach ($transactionReviewColumns as $column => $sql) {
        if (!database_column_exists('transactions', $column)) {
            try {
                db_execute($sql);
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }

    $workflowColumns = [
        ['orders', 'prepared_by_staff_id', 'ALTER TABLE orders ADD COLUMN prepared_by_staff_id INT UNSIGNED NULL AFTER customer_id'],
        ['orders', 'checkout_idempotency_key', 'ALTER TABLE orders ADD COLUMN checkout_idempotency_key CHAR(64) NULL AFTER assigned_staff_id'],
        ['orders', 'promo_code', 'ALTER TABLE orders ADD COLUMN promo_code VARCHAR(40) NULL AFTER discount_amount'],
        ['orders', 'promo_discount_amount', 'ALTER TABLE orders ADD COLUMN promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promo_code'],
        ['transactions', 'promo_code', 'ALTER TABLE transactions ADD COLUMN promo_code VARCHAR(40) NULL AFTER discount_amount'],
        ['transactions', 'promo_discount_amount', 'ALTER TABLE transactions ADD COLUMN promo_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promo_code'],
        ['transactions', 'gcash_reference_hash', 'ALTER TABLE transactions ADD COLUMN gcash_reference_hash CHAR(64) NULL AFTER gcash_reference'],
    ];

    foreach ($workflowColumns as [$table, $column, $sql]) {
        if (!database_column_exists($table, $column)) {
            db_execute($sql);
        }
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS promo_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            description VARCHAR(255) NULL,
            discount_type ENUM("percent", "fixed") NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            maximum_discount DECIMAL(10,2) NULL,
            usage_limit INT UNSIGNED NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_promo_codes_code (code),
            INDEX idx_promo_codes_active_window (status, starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    db_execute(
        'CREATE TABLE IF NOT EXISTS payment_review_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            action ENUM("submitted", "verified", "rejected", "resubmitted") NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!database_index_exists('orders', 'uniq_orders_checkout_idempotency') && database_duplicate_count('orders', 'checkout_idempotency_key') === 0) {
        db_execute('CREATE UNIQUE INDEX uniq_orders_checkout_idempotency ON orders (checkout_idempotency_key)');
    }
    if (!database_index_exists('transactions', 'uniq_transactions_gcash_reference_hash') && database_duplicate_count('transactions', 'gcash_reference_hash') === 0) {
        db_execute('CREATE UNIQUE INDEX uniq_transactions_gcash_reference_hash ON transactions (gcash_reference_hash)');
    }

    $deliveryDateColumn = db_find(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "deliveries" AND COLUMN_NAME = "delivered_at" LIMIT 1',
        's',
        [$database]
    );

    if (!$deliveryDateColumn) {
        try {
            db_execute('ALTER TABLE deliveries ADD COLUMN delivered_at DATETIME NULL AFTER issue_notes');
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }

    $deliveryExtraColumns = [
        'estimated_delivery_at' => 'ALTER TABLE deliveries ADD COLUMN estimated_delivery_at DATETIME NULL AFTER delivered_at',
        'proof_photo_path' => 'ALTER TABLE deliveries ADD COLUMN proof_photo_path VARCHAR(255) NULL AFTER estimated_delivery_at',
        'recipient_name' => 'ALTER TABLE deliveries ADD COLUMN recipient_name VARCHAR(150) NULL AFTER proof_photo_path',
    ];

    foreach ($deliveryExtraColumns as $column => $sql) {
        if (!database_column_exists('deliveries', $column)) {
            try {
                db_execute($sql);
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS delivery_history (
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
        ) ENGINE=InnoDB'
    );

    $transactionStatusColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "transactions" AND COLUMN_NAME = "transaction_status" LIMIT 1',
        's',
        [$database]
    );

    if ($transactionStatusColumn && !str_contains((string) $transactionStatusColumn['COLUMN_TYPE'], "'accepted'")) {
        db_execute(
            'ALTER TABLE transactions
             MODIFY transaction_status ENUM("pending", "accepted", "completed", "failed", "cancelled") NOT NULL DEFAULT "pending"'
        );
    }

    $paymentStatusColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "orders" AND COLUMN_NAME = "payment_status" LIMIT 1',
        's',
        [$database]
    );

    if ($paymentStatusColumn && !str_contains((string) $paymentStatusColumn['COLUMN_TYPE'], "'accepted'")) {
        db_execute(
            'ALTER TABLE orders
             MODIFY payment_status ENUM("pending", "accepted", "paid", "failed", "cancelled") NOT NULL DEFAULT "pending"'
        );
    }

    $orderDeliveryStatusColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "orders" AND COLUMN_NAME = "delivery_status" LIMIT 1',
        's',
        [$database]
    );

    if ($orderDeliveryStatusColumn && !str_contains((string) $orderDeliveryStatusColumn['COLUMN_TYPE'], "'failed_delivery'")) {
        db_execute(
            'ALTER TABLE orders
             MODIFY delivery_status ENUM("pending", "assigned", "out_for_delivery", "delivered", "issue_reported", "failed_delivery", "cancelled") NOT NULL DEFAULT "pending"'
        );
    }

    $deliveryStatusColumn = db_find(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "deliveries" AND COLUMN_NAME = "delivery_status" LIMIT 1',
        's',
        [$database]
    );

    if ($deliveryStatusColumn && !str_contains((string) $deliveryStatusColumn['COLUMN_TYPE'], "'failed_delivery'")) {
        db_execute(
            'ALTER TABLE deliveries
             MODIFY delivery_status ENUM("pending", "assigned", "out_for_delivery", "delivered", "issue_reported", "failed_delivery", "cancelled") NOT NULL DEFAULT "pending"'
        );
    }

    $checked = true;
}

function ensure_record_management_tables(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS record_states (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_type VARCHAR(40) NOT NULL,
            record_id INT UNSIGNED NOT NULL,
            record_status ENUM("active", "archived", "deleted") NOT NULL DEFAULT "active",
            snapshot_data JSON NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL,
            recovered_at DATETIME NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_record_state (record_type, record_id),
            CONSTRAINT fk_record_states_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $checked = true;
}

function ensure_conditional_user_deletion_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    if (!database_column_exists('users', 'deleted_at')) {
        db_execute('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER status');
    }

    if (!database_column_exists('users', 'deleted_by')) {
        db_execute('ALTER TABLE users ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at');
    }

    if (!database_column_exists('users', 'deleted_previous_status')) {
        db_execute('ALTER TABLE users ADD COLUMN deleted_previous_status VARCHAR(30) NULL AFTER deleted_by');
    }

    if (!database_index_exists('users', 'idx_users_deleted_at')) {
        db_execute('CREATE INDEX idx_users_deleted_at ON users (deleted_at, status)');
    }

    if (!database_constraint_exists('users', 'fk_users_deleted_by')) {
        try {
            db_execute('ALTER TABLE users ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');
        } catch (mysqli_sql_exception $exception) {
            if (!in_array((int) $exception->getCode(), [1005, 1022, 1215, 1452, 1826], true)) {
                throw $exception;
            }
        }
    }

    $checked = true;
}

function assert_password_reset_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $requirements = [
        'users' => ['email_verified_at'],
        'password_resets' => [
            'id', 'user_id', 'email', 'token', 'purpose', 'code_hash', 'delivery_channel', 'destination',
            'expires_at', 'attempt_count', 'verified_at', 'verification_token_hash', 'is_used', 'used_at', 'created_at',
        ],
        'registration_verifications' => [
            'id', 'user_id', 'email', 'code_hash', 'attempt_count', 'expires_at', 'last_sent_at',
            'verified_at', 'created_at', 'updated_at',
        ],
        'email_notifications' => [
            'id', 'user_id', 'password_reset_id', 'email', 'recipient_name', 'subject', 'message', 'html_message',
            'message_type', 'dedupe_key', 'status', 'attempt_count', 'next_attempt_at', 'claim_token', 'locked_until',
            'provider_message_id', 'provider_response', 'created_at', 'last_attempt_at', 'sent_at', 'delivered_at',
            'failed_at', 'expires_at',
        ],
        'rate_limits' => ['id', 'rate_key', 'scope', 'attempts', 'first_attempt_at', 'last_attempt_at', 'expires_at'],
        'remember_tokens' => ['id', 'user_id', 'selector', 'token_hash', 'expires_at', 'created_at'],
        'login_history' => [
            'id', 'user_id', 'full_name', 'email', 'role', 'ip_address', 'user_agent', 'user_agent_hash',
            'login_source', 'new_ip', 'new_browser', 'login_at', 'logout_at', 'last_activity_at', 'end_reason',
        ],
    ];
    $rows = db_select(
        'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name IN ("users", "password_resets", "registration_verifications", "email_notifications",
                              "rate_limits", "remember_tokens", "login_history")'
    );
    $present = [];
    $types = [];
    foreach ($rows as $row) {
        // MySQL/MariaDB may preserve INFORMATION_SCHEMA field names as
        // uppercase even when a lowercase alias is used. Normalize result
        // keys before reading them so a valid schema is not reported as
        // entirely missing.
        $row = array_change_key_case($row, CASE_LOWER);
        $table = strtolower((string) ($row['table_name'] ?? ''));
        $column = strtolower((string) ($row['column_name'] ?? ''));
        if ($table === '' || $column === '') {
            continue;
        }
        $present[$table][$column] = true;
        $types[$table][$column] = strtolower((string) ($row['column_type'] ?? ''));
    }

    $missing = [];
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($present[$table][$column])) {
                $missing[] = $table . '.' . $column;
            }
        }
    }

    $queueStatusType = (string) ($types['email_notifications']['status'] ?? '');
    foreach (['queued', 'sent', 'delivered', 'deferred', 'failed', 'expired'] as $status) {
        if (!str_contains($queueStatusType, "'" . $status . "'")) {
            $missing[] = 'email_notifications.status:' . $status;
        }
    }

    $requiredIndexes = [
        ['password_resets', 'token'],
        ['registration_verifications', 'uniq_registration_verification_user'],
        ['email_notifications', 'uniq_email_dedupe'],
        ['email_notifications', 'idx_email_queue_ready'],
        ['rate_limits', 'uniq_rate_key_scope'],
        ['remember_tokens', 'selector'],
    ];
    foreach ($requiredIndexes as [$table, $index]) {
        if (!database_index_exists($table, $index)) {
            $missing[] = $table . '.index:' . $index;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Password recovery database schema is incomplete; missing or incompatible: '
            . implode(', ', $missing)
            . '. Apply the password recovery repair migration before serving reset requests.'
        );
    }

    $checked = true;
}

function ensure_password_reset_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        assert_password_reset_schema();
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            email VARCHAR(150) NOT NULL,
            token VARCHAR(255) NOT NULL UNIQUE,
            purpose VARCHAR(32) NOT NULL DEFAULT "password_reset",
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $columns = [
        'email' => 'ALTER TABLE password_resets ADD COLUMN email VARCHAR(150) NULL AFTER user_id',
        'purpose' => 'ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(32) NOT NULL DEFAULT "password_reset" AFTER token',
        'code_hash' => 'ALTER TABLE password_resets ADD COLUMN code_hash VARCHAR(255) NULL AFTER token',
        'delivery_channel' => 'ALTER TABLE password_resets ADD COLUMN delivery_channel VARCHAR(20) NULL AFTER code_hash',
        'destination' => 'ALTER TABLE password_resets ADD COLUMN destination VARCHAR(180) NULL AFTER delivery_channel',
        'attempt_count' => 'ALTER TABLE password_resets ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at',
        'verified_at' => 'ALTER TABLE password_resets ADD COLUMN verified_at DATETIME NULL AFTER attempt_count',
        'verification_token_hash' => 'ALTER TABLE password_resets ADD COLUMN verification_token_hash CHAR(64) NULL AFTER verified_at',
        'is_used' => 'ALTER TABLE password_resets ADD COLUMN is_used TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_token_hash',
        'used_at' => 'ALTER TABLE password_resets ADD COLUMN used_at DATETIME NULL AFTER expires_at',
    ];

    foreach ($columns as $column => $sql) {
        if (!database_column_exists('password_resets', $column)) {
            try {
                db_execute($sql);
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1060) {
                    throw $exception;
                }
            }
        }
    }

    db_execute(
        'UPDATE password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         SET pr.email = u.email
         WHERE pr.email IS NULL OR pr.email = ""'
    );

    db_execute('UPDATE password_resets SET is_used = 1 WHERE used_at IS NOT NULL AND is_used = 0');

    ensure_email_queue_schema();

    assert_password_reset_schema();

    $checked = true;
}

