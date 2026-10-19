<?php

declare(strict_types=1);

/**
 * The legacy users.address column remains the canonical single-line fallback
 * used by existing profile pages. These helpers keep the checkout's structured
 * fields and the delivery snapshot consistent without attempting to guess how
 * an older free-form address should be split.
 */
function ensure_customer_delivery_address_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $userColumns = [
        'delivery_street' => 'ALTER TABLE users ADD COLUMN delivery_street VARCHAR(255) NULL AFTER address',
        'delivery_barangay' => 'ALTER TABLE users ADD COLUMN delivery_barangay VARCHAR(120) NULL AFTER delivery_street',
        'delivery_city' => 'ALTER TABLE users ADD COLUMN delivery_city VARCHAR(120) NULL AFTER delivery_barangay',
        'delivery_province' => 'ALTER TABLE users ADD COLUMN delivery_province VARCHAR(120) NULL AFTER delivery_city',
        'delivery_landmark' => 'ALTER TABLE users ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_province',
    ];

    foreach ($userColumns as $column => $sql) {
        if (!database_column_exists('users', $column)) {
            db_execute($sql);
        }
    }

    if (!database_column_exists('deliveries', 'delivery_landmark')) {
        db_execute('ALTER TABLE deliveries ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_address');
    }

    $checked = true;
}

function normalize_customer_delivery_address_value(mixed $value): string
{
    $value = trim((string) $value);

    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

function customer_delivery_address_parts(array $source): array
{
    return [
        'street' => normalize_customer_delivery_address_value($source['street'] ?? $source['delivery_street'] ?? ''),
        'barangay' => normalize_customer_delivery_address_value($source['barangay'] ?? $source['delivery_barangay'] ?? ''),
        'city' => normalize_customer_delivery_address_value($source['city'] ?? $source['delivery_city'] ?? ''),
        'province' => normalize_customer_delivery_address_value($source['province'] ?? $source['delivery_province'] ?? ''),
        'landmark' => normalize_customer_delivery_address_value($source['landmark'] ?? $source['delivery_landmark'] ?? ''),
    ];
}

function customer_delivery_address_component_key(string $value): string
{
    $value = mb_strtolower(normalize_customer_delivery_address_value($value));

    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
}

function customer_delivery_address_has_component_segment(string $value, string $component): bool
{
    $component = customer_delivery_address_component_key($component);

    if ($component === '') {
        return false;
    }

    foreach (preg_split('/[,;\r\n]+/u', $value) ?: [] as $segment) {
        if (customer_delivery_address_component_key($segment) === $component) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, string> keyed by the checkout form field name
 */
function customer_delivery_address_errors(array $parts): array
{
    $parts = customer_delivery_address_parts($parts);
    $fields = [
        'street' => ['field' => 'delivery_street', 'label' => 'Street Address', 'max' => 255],
        'barangay' => ['field' => 'delivery_barangay', 'label' => 'Barangay', 'max' => 120],
        'city' => ['field' => 'delivery_city', 'label' => 'City / Municipality', 'max' => 120],
        'province' => ['field' => 'delivery_province', 'label' => 'Province', 'max' => 120],
        'landmark' => ['field' => 'delivery_landmark', 'label' => 'Landmark / Additional Address Info', 'max' => 255],
    ];
    $errors = [];
    $seen = [];

    foreach ($fields as $key => $definition) {
        $value = $parts[$key];

        if ($key !== 'landmark' && $value === '') {
            $errors[$definition['field']] = $definition['label'] . ' is required.';
            continue;
        }

        if ($value !== '' && mb_strlen($value) > $definition['max']) {
            $errors[$definition['field']] = $definition['label'] . ' must be ' . $definition['max'] . ' characters or fewer.';
            continue;
        }

        if ($key === 'landmark' || $value === '') {
            continue;
        }

        $normalized = customer_delivery_address_component_key($value);
        if ($normalized !== '' && isset($seen[$normalized])) {
            $errors[$definition['field']] = $definition['label'] . ' repeats the ' . $seen[$normalized] . '. Enter each address component only once.';
            continue;
        }

        $seen[$normalized] = strtolower($definition['label']);
    }

    // A comma-separated legacy address pasted into Street Address is a common
    // source of duplicated city/province values. Flag only whole segments, not
    // incidental words such as a street named after a barangay.
    foreach (['barangay', 'city', 'province'] as $key) {
        if ($parts[$key] !== '' && customer_delivery_address_has_component_segment($parts['street'], $parts[$key])) {
            $errors['delivery_street'] = 'Street Address already includes the ' . strtolower($fields[$key]['label']) . '. Keep that value in its own field.';
            break;
        }
    }

    return $errors;
}

function compose_customer_delivery_address(array $parts): string
{
    $parts = customer_delivery_address_parts($parts);
    $address = [];
    $seen = [];

    foreach (['street', 'barangay', 'city', 'province'] as $key) {
        $value = $parts[$key];
        $normalized = customer_delivery_address_component_key($value);

        if ($value !== '' && $normalized !== '' && !isset($seen[$normalized])) {
            $address[] = $value;
            $seen[$normalized] = true;
        }
    }

    return implode(', ', $address);
}

function customer_delivery_address_is_complete(array $parts): bool
{
    foreach (['street', 'barangay', 'city', 'province'] as $key) {
        if (normalize_customer_delivery_address_value($parts[$key] ?? '') === '') {
            return false;
        }
    }

    return customer_delivery_address_errors($parts) === [];
}

/**
 * @return array{parts: array<string, string>, legacy_address: string, is_structured: bool}
 */
function customer_saved_delivery_address(array $user): array
{
    $parts = customer_delivery_address_parts([
        'delivery_street' => $user['delivery_street'] ?? '',
        'delivery_barangay' => $user['delivery_barangay'] ?? '',
        'delivery_city' => $user['delivery_city'] ?? '',
        'delivery_province' => $user['delivery_province'] ?? '',
        'delivery_landmark' => $user['delivery_landmark'] ?? '',
    ]);
    $structured = customer_delivery_address_is_complete($parts);

    return [
        'parts' => $structured ? $parts : customer_delivery_address_parts([]),
        'legacy_address' => $structured ? '' : normalize_customer_delivery_address_value($user['address'] ?? ''),
        'is_structured' => $structured,
    ];
}

function save_customer_delivery_address(int $customerId, array $parts): void
{
    $parts = customer_delivery_address_parts($parts);
    $errors = customer_delivery_address_errors($parts);

    if ($errors !== []) {
        throw new InvalidArgumentException(reset($errors) ?: 'Please complete your delivery address.');
    }

    $address = compose_customer_delivery_address($parts);

    db_execute(
        'UPDATE users
         SET address = ?, delivery_street = ?, delivery_barangay = ?, delivery_city = ?, delivery_province = ?, delivery_landmark = ?, updated_at = NOW()
         WHERE id = ? AND role = "customer" AND deleted_at IS NULL',
        'ssssssi',
        [$address, $parts['street'], $parts['barangay'], $parts['city'], $parts['province'], $parts['landmark'] !== '' ? $parts['landmark'] : null, $customerId]
    );
}
