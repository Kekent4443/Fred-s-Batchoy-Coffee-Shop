<?php

declare(strict_types=1);

function ensure_receipt_settings_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute('CREATE TABLE IF NOT EXISTS receipt_settings (
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
        paper_width ENUM("58mm", "80mm") NOT NULL DEFAULT "58mm",
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_receipt_settings_updated_by (updated_by),
        CONSTRAINT fk_receipt_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $defaults = app_settings();
    db_execute(
        'INSERT IGNORE INTO receipt_settings
         (id, business_name, business_address, contact_number, contact_email, social_media, tax_rate, service_fee, delivery_fee, footer_message, paper_width, updated_by, created_at, updated_at)
         VALUES (1, ?, ?, ?, ?, NULL, 12.00, 0.00, 0.00, ?, "58mm", NULL, NOW(), NOW())',
        'sssss',
        [
            (string) ($defaults['brand_name'] ?? APP_NAME),
            'Business address not configured',
            'Contact number not configured',
            (string) ($defaults['support_email'] ?? ''),
            'Thank you for your order!',
        ]
    );

    $columns = [
        'tax_rate' => 'DECIMAL(5,2) NOT NULL DEFAULT 12.00 AFTER discount_amount',
        'tax_amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate',
        'service_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount',
        'delivery_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER service_fee',
    ];

    foreach ($columns as $column => $definition) {
        if (!database_column_exists('orders', $column)) {
            db_execute('ALTER TABLE orders ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    $checked = true;
}

function receipt_settings(): array
{
    ensure_receipt_settings_schema();

    $settings = db_find('SELECT * FROM receipt_settings WHERE id = 1 LIMIT 1') ?? [];
    // Older installations were seeded at 80mm. Treat only an untouched seed
    // as the new 58mm default; preserve an administrator's saved 80mm choice.
    if (($settings['paper_width'] ?? null) === '80mm'
        && empty($settings['updated_by'])
        && !empty($settings['created_at'])
        && ($settings['created_at'] ?? null) === ($settings['updated_at'] ?? null)) {
        $settings['paper_width'] = '58mm';
    }

    return $settings;
}

function save_receipt_settings(array $viewer, array $payload): void
{
    ensure_receipt_settings_schema();

    if (($viewer['role'] ?? '') !== 'administrator' || empty($viewer['id'])) {
        throw new RuntimeException('Only the Administrator can update receipt settings.');
    }

    $businessName = trim((string) ($payload['business_name'] ?? ''));
    $businessAddress = trim((string) ($payload['business_address'] ?? ''));
    $contactNumber = trim((string) ($payload['contact_number'] ?? ''));
    $contactEmail = trim((string) ($payload['contact_email'] ?? ''));
    $socialMedia = trim((string) ($payload['social_media'] ?? ''));
    $footerMessage = trim((string) ($payload['footer_message'] ?? ''));
    $paperWidth = require_allowed_value((string) ($payload['paper_width'] ?? '58mm'), ['58mm', '80mm'], 'Select a valid thermal paper width.');
    $taxRate = round((float) ($payload['tax_rate'] ?? 0), 2);
    $serviceFee = round((float) ($payload['service_fee'] ?? 0), 2);
    $deliveryFee = round((float) ($payload['delivery_fee'] ?? 0), 2);

    if ($businessName === '' || mb_strlen($businessName) > 150) {
        throw new InvalidArgumentException('Business name is required and must be 150 characters or fewer.');
    }
    if ($businessAddress === '' || mb_strlen($businessAddress) > 255) {
        throw new InvalidArgumentException('Business address is required and must be 255 characters or fewer.');
    }
    if ($contactNumber === '' || mb_strlen($contactNumber) > 60) {
        throw new InvalidArgumentException('Contact information is required and must be 60 characters or fewer.');
    }
    if ($contactEmail !== '' && (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($contactEmail) > 150)) {
        throw new InvalidArgumentException('Enter a valid contact email address.');
    }
    if (mb_strlen($socialMedia) > 150 || $footerMessage === '' || mb_strlen($footerMessage) > 500) {
        throw new InvalidArgumentException('Social media must be 150 characters or fewer and the footer message must be 1 to 500 characters.');
    }
    if ($taxRate < 0 || $taxRate > 100 || $serviceFee < 0 || $deliveryFee < 0 || $serviceFee > 999999.99 || $deliveryFee > 999999.99) {
        throw new InvalidArgumentException('Tax must be between 0% and 100%, and fees must be valid non-negative amounts.');
    }

    $db = get_db();
    $db->begin_transaction();
    try {
        db_execute(
            'UPDATE receipt_settings
             SET business_name = ?, business_address = ?, contact_number = ?, contact_email = ?, social_media = ?, tax_rate = ?, service_fee = ?, delivery_fee = ?, footer_message = ?, paper_width = ?, updated_by = ?, updated_at = NOW()
             WHERE id = 1',
            'sssssdddssi',
            [$businessName, $businessAddress, $contactNumber, $contactEmail !== '' ? $contactEmail : null, $socialMedia !== '' ? $socialMedia : null, $taxRate, $serviceFee, $deliveryFee, $footerMessage, $paperWidth, (int) $viewer['id']]
        );
        log_audit((int) $viewer['id'], 'receipt_settings_updated', 'Updated thermal receipt business, tax, fee, footer, and paper-width settings.');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
