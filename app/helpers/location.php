<?php

declare(strict_types=1);

function ensure_shop_location_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute('CREATE TABLE IF NOT EXISTS shop_locations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_name VARCHAR(180) NOT NULL,
        complete_address TEXT NOT NULL,
        google_maps_url VARCHAR(500) NOT NULL,
        google_maps_embed_url VARCHAR(500) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        contact_number VARCHAR(40) NOT NULL,
        landmark TEXT NULL,
        status ENUM("active", "inactive") NOT NULL DEFAULT "active",
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_shop_locations_status (status),
        INDEX idx_shop_locations_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    if (!database_column_exists('users', 'can_edit_shop_location')) {
        db_execute('ALTER TABLE users ADD COLUMN can_edit_shop_location TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_notes');
    }

    if (!database_column_exists('users', 'assigned_shop_id')) {
        db_execute('ALTER TABLE users ADD COLUMN assigned_shop_id INT UNSIGNED NULL AFTER can_edit_shop_location');
    }

    if (!database_index_exists('users', 'idx_users_location_permission')) {
        db_execute('CREATE INDEX idx_users_location_permission ON users (role, can_edit_shop_location, assigned_shop_id)');
    }

    if (!database_constraint_exists('users', 'fk_users_assigned_shop')) {
        try {
            db_execute('ALTER TABLE users ADD CONSTRAINT fk_users_assigned_shop FOREIGN KEY (assigned_shop_id) REFERENCES shop_locations(id) ON DELETE SET NULL ON UPDATE CASCADE');
        } catch (mysqli_sql_exception $exception) {
            if (!in_array((int) $exception->getCode(), [1005, 1022, 1215, 1452, 1826], true)) {
                throw $exception;
            }
        }
    }

    $existing = db_find('SELECT id FROM shop_locations LIMIT 1');

    if (!$existing) {
        db_execute(
            'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, google_maps_embed_url, latitude, longitude, contact_number, landmark, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "active", NOW(), NOW())',
            'ssssddss',
            [
                "Fred's Batchoy and Coffee Shop",
                'Exact shop location saved from the existing Google Maps pin.',
                'https://maps.app.goo.gl/n4Jg1tJdT8BXBvko8',
                'https://www.google.com/maps?q=10.1249048,123.2742754&z=17&output=embed',
                10.1249048,
                123.2742754,
                '09515882546',
                'Use the Google Maps pin for directions and nearby landmarks.',
            ]
        );
    }

    $checked = true;
}

function shop_location_default(): array
{
    return [
        'id' => 0,
        'shop_name' => "Fred's Batchoy and Coffee Shop",
        'complete_address' => 'Exact shop location saved from the existing Google Maps pin.',
        'google_maps_url' => 'https://maps.app.goo.gl/n4Jg1tJdT8BXBvko8',
        'google_maps_embed_url' => 'https://www.google.com/maps?q=10.1249048,123.2742754&z=17&output=embed',
        'latitude' => '10.1249048',
        'longitude' => '123.2742754',
        'contact_number' => '09515882546',
        'landmark' => 'Use the Google Maps pin for directions and nearby landmarks.',
        'status' => 'active',
    ];
}

function fetch_shop_locations(bool $includeInactive = false): array
{
    ensure_shop_location_schema();

    $sql = 'SELECT * FROM shop_locations';
    if (!$includeInactive) {
        $sql .= ' WHERE status = "active"';
    }

    return db_select($sql . ' ORDER BY status ASC, shop_name ASC, id ASC');
}

function fetch_shop_location_by_id(int $id): ?array
{
    ensure_shop_location_schema();

    return db_find('SELECT * FROM shop_locations WHERE id = ? LIMIT 1', 'i', [$id]);
}

function current_shop_location(): array
{
    ensure_shop_location_schema();

    return db_find('SELECT * FROM shop_locations WHERE status = "active" ORDER BY id ASC LIMIT 1') ?? shop_location_default();
}

function shop_location_user_settings(int $userId): array
{
    ensure_shop_location_schema();

    return db_find(
        'SELECT id, role, can_edit_shop_location, assigned_shop_id FROM users WHERE id = ? LIMIT 1',
        'i',
        [$userId]
    ) ?? [];
}

function user_can_edit_shop_location(array $viewer, int $shopId): bool
{
    $role = (string) ($viewer['role'] ?? '');

    if ($role === 'administrator') {
        return true;
    }

    if ($role !== 'manager' || empty($viewer['id'])) {
        return false;
    }

    $settings = shop_location_user_settings((int) $viewer['id']);

    if ((int) ($settings['can_edit_shop_location'] ?? 0) !== 1) {
        return false;
    }

    $assignedShopId = (int) ($settings['assigned_shop_id'] ?? 0);

    if ($assignedShopId > 0) {
        return $assignedShopId === $shopId;
    }

    return count(fetch_shop_locations(false)) <= 1;
}

function validate_shop_location_text(string $value, string $field, int $maxLength, bool $required = true): string
{
    $value = trim($value);

    if ($required && $value === '') {
        throw new InvalidArgumentException($field . ' is required.');
    }

    if ($value !== '' && strlen($value) > $maxLength) {
        throw new InvalidArgumentException($field . ' is too long.');
    }

    return $value;
}

function validate_shop_location_coordinate(string $value, string $field, float $min, float $max): ?float
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException($field . ' must be a valid number.');
    }

    $number = (float) $value;

    if ($number < $min || $number > $max) {
        throw new InvalidArgumentException($field . ' is outside the valid range.');
    }

    return $number;
}

function validate_google_maps_url(string $url, string $field, bool $required = true, bool $embedOnly = false): string
{
    $url = trim($url);

    if ($url === '') {
        if ($required) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return '';
    }

    if (preg_match('/<\s*(iframe|script)|javascript:|data:|[<>]/i', $url)) {
        throw new InvalidArgumentException($field . ' must be a safe Google Maps URL only. Do not paste iframe or script code.');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException($field . ' must be a valid URL.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = strtolower((string) ($parts['path'] ?? ''));

    if ($scheme !== 'https') {
        throw new InvalidArgumentException($field . ' must use HTTPS.');
    }

    $allowedHosts = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl'];

    if (!in_array($host, $allowedHosts, true)) {
        throw new InvalidArgumentException($field . ' must be a Google Maps URL.');
    }

    if ($embedOnly) {
        if (!in_array($host, ['google.com', 'www.google.com', 'maps.google.com'], true) || !str_contains($path, '/maps')) {
            throw new InvalidArgumentException($field . ' must be a Google Maps embed URL.');
        }

        return $url;
    }

    if (!str_contains($host . $path, 'maps') && $host !== 'goo.gl') {
        throw new InvalidArgumentException($field . ' must point to Google Maps.');
    }

    return $url;
}

function shop_location_embed_src(array $shop): string
{
    $embedUrl = trim((string) ($shop['google_maps_embed_url'] ?? ''));

    if ($embedUrl !== '') {
        try {
            return validate_google_maps_url($embedUrl, 'Google Maps embed URL', false, true);
        } catch (InvalidArgumentException) {
            error_log('Invalid saved shop embed URL for shop #' . (string) ($shop['id'] ?? 'unknown'));
        }
    }

    $latitude = trim((string) ($shop['latitude'] ?? ''));
    $longitude = trim((string) ($shop['longitude'] ?? ''));

    if ($latitude !== '' && $longitude !== '') {
        return 'https://www.google.com/maps?q=' . rawurlencode($latitude . ',' . $longitude) . '&z=17&output=embed';
    }

    return shop_location_default()['google_maps_embed_url'];
}

function shop_location_public_url(array $shop): string
{
    $mapsUrl = trim((string) ($shop['google_maps_url'] ?? ''));

    if ($mapsUrl !== '') {
        try {
            return validate_google_maps_url($mapsUrl, 'Google Maps public link');
        } catch (InvalidArgumentException) {
            error_log('Invalid saved shop public map URL for shop #' . (string) ($shop['id'] ?? 'unknown'));
        }
    }

    $latitude = trim((string) ($shop['latitude'] ?? ''));
    $longitude = trim((string) ($shop['longitude'] ?? ''));

    if ($latitude !== '' && $longitude !== '') {
        return 'https://www.google.com/maps?q=' . rawurlencode($latitude . ',' . $longitude);
    }

    return shop_location_default()['google_maps_url'];
}

function shop_location_payload_from_request(bool $editing = true): array
{
    $latitude = validate_shop_location_coordinate((string) post_scalar('latitude', ''), 'Latitude', -90, 90);
    $longitude = validate_shop_location_coordinate((string) post_scalar('longitude', ''), 'Longitude', -180, 180);
    $embedUrl = validate_google_maps_url((string) post_scalar('google_maps_embed_url', ''), 'Google Maps embed URL', false, true);

    if ($embedUrl === '' && $latitude !== null && $longitude !== null) {
        $embedUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $latitude . ',' . (string) $longitude) . '&z=17&output=embed';
    }

    if ($embedUrl === '') {
        throw new InvalidArgumentException('Provide a Google Maps embed URL or valid latitude and longitude.');
    }

    return [
        'shop_name' => validate_shop_location_text((string) post_scalar('shop_name', ''), 'Shop name', 180),
        'complete_address' => validate_shop_location_text((string) post_scalar('complete_address', ''), 'Complete address', 1000),
        'google_maps_url' => validate_google_maps_url((string) post_scalar('google_maps_url', ''), 'Google Maps public link'),
        'google_maps_embed_url' => $embedUrl,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'contact_number' => validate_shop_location_text((string) post_scalar('contact_number', ''), 'Contact number', 40),
        'landmark' => validate_shop_location_text((string) post_scalar('landmark', ''), 'Landmark or description', 1000, false),
        'status' => $editing
            ? require_allowed_value((string) post_scalar('status', ''), ['active', 'inactive'], 'Please select a valid shop status.')
            : 'active',
    ];
}

function save_shop_location(int $shopId, array $viewer, array $payload): void
{
    ensure_shop_location_schema();

    $previous = fetch_shop_location_by_id($shopId);

    if (!$previous) {
        throw new InvalidArgumentException('Shop location was not found.');
    }

    if (!user_can_edit_shop_location($viewer, $shopId)) {
        throw new RuntimeException('You do not have permission to update this shop location.');
    }

    // Manager forms carry status only to display the existing value. A forged
    // hidden field must not grant managers the administrator's status control.
    $status = ($viewer['role'] ?? '') === 'administrator'
        ? require_allowed_value((string) ($payload['status'] ?? ''), ['active', 'inactive'], 'Please select a valid shop status.')
        : (string) $previous['status'];

    db_execute(
        'UPDATE shop_locations
         SET shop_name = ?, complete_address = ?, google_maps_url = ?, google_maps_embed_url = ?, latitude = ?, longitude = ?, contact_number = ?, landmark = ?, status = ?, updated_at = NOW()
         WHERE id = ?',
        'ssssddsssi',
        [
            $payload['shop_name'],
            $payload['complete_address'],
            $payload['google_maps_url'],
            $payload['google_maps_embed_url'],
            $payload['latitude'],
            $payload['longitude'],
            $payload['contact_number'],
            $payload['landmark'],
            $status,
            $shopId,
        ]
    );

    $updated = fetch_shop_location_by_id($shopId) ?? [];
    $roleLabel = role_label((string) ($viewer['role'] ?? 'user'));
    $description = 'Google Maps Shop Location Management update by ' . (string) ($viewer['full_name'] ?? 'Unknown user') . ' (' . $roleLabel . ') for shop #' . $shopId
        . '. Previous: ' . json_encode(shop_location_audit_snapshot($previous), JSON_UNESCAPED_SLASHES)
        . '. New: ' . json_encode(shop_location_audit_snapshot($updated), JSON_UNESCAPED_SLASHES);

    log_audit(!empty($viewer['id']) ? (int) $viewer['id'] : null, 'shop_location_updated', $description);
}

function shop_location_audit_snapshot(array $shop): array
{
    return [
        'shop_name' => $shop['shop_name'] ?? '',
        'complete_address' => $shop['complete_address'] ?? '',
        'google_maps_url' => $shop['google_maps_url'] ?? '',
        'google_maps_embed_url' => $shop['google_maps_embed_url'] ?? '',
        'latitude' => $shop['latitude'] ?? null,
        'longitude' => $shop['longitude'] ?? null,
        'contact_number' => $shop['contact_number'] ?? '',
        'landmark' => $shop['landmark'] ?? '',
        'status' => $shop['status'] ?? '',
    ];
}

function add_shop_location(array $viewer, array $payload): int
{
    ensure_shop_location_schema();

    if (($viewer['role'] ?? '') !== 'administrator') {
        throw new RuntimeException('Only administrators can add new shop locations.');
    }

    db_execute(
        'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, google_maps_embed_url, latitude, longitude, contact_number, landmark, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        'ssssddss' . 's',
        [
            $payload['shop_name'],
            $payload['complete_address'],
            $payload['google_maps_url'],
            $payload['google_maps_embed_url'],
            $payload['latitude'],
            $payload['longitude'],
            $payload['contact_number'],
            $payload['landmark'],
            'active',
        ]
    );

    $newId = db_last_id();

    log_audit(
        !empty($viewer['id']) ? (int) $viewer['id'] : null,
        'shop_location_added',
        'New shop location added by ' . (string) ($viewer['full_name'] ?? 'Unknown') . ': ' . $payload['shop_name'] . ' (shop #' . $newId . ').'
    );

    return $newId;
}

function shop_location_deletion_reason(array $shop, array $locations, int $assignedUsers): ?string
{
    if (count($locations) <= 1) {
        return 'At least one shop location must remain.';
    }

    if (($shop['status'] ?? '') === 'active') {
        $activeCount = count(array_filter($locations, static fn (array $location): bool => ($location['status'] ?? '') === 'active'));
        if ($activeCount <= 1) {
            return 'Activate another shop location before deleting the only active location.';
        }
    }

    if ($assignedUsers > 0) {
        return 'Reassign or unassign users linked to this shop before deleting it.';
    }

    return null;
}

function delete_shop_location(int $shopId, array $viewer): void
{
    ensure_shop_location_schema();

    if (($viewer['role'] ?? '') !== 'administrator') {
        throw new RuntimeException('Only administrators can delete shop locations.');
    }
    if ($shopId <= 0) {
        throw new InvalidArgumentException('Shop location was not found.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $locations = db_select('SELECT * FROM shop_locations ORDER BY id FOR UPDATE');
        $shop = null;
        foreach ($locations as $location) {
            if ((int) $location['id'] === $shopId) {
                $shop = $location;
                break;
            }
        }
        if ($shop === null) {
            throw new InvalidArgumentException('Shop location was not found.');
        }

        $assignedUsers = (int) (db_find('SELECT COUNT(*) AS total FROM users WHERE assigned_shop_id = ?', 'i', [$shopId])['total'] ?? 0);
        $reason = shop_location_deletion_reason($shop, $locations, $assignedUsers);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        db_execute('DELETE FROM shop_locations WHERE id = ?', 'i', [$shopId]);
        log_audit(
            !empty($viewer['id']) ? (int) $viewer['id'] : null,
            'shop_location_deleted',
            'Shop location #' . $shopId . ' deleted by ' . (string) ($viewer['full_name'] ?? 'Unknown')
                . '. Previous: ' . json_encode(shop_location_audit_snapshot($shop), JSON_UNESCAPED_SLASHES)
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function update_manager_location_permission(int $managerId, int $shopId, bool $canEdit, array $admin): void
{
    ensure_shop_location_schema();

    if (($admin['role'] ?? '') !== 'administrator') {
        throw new RuntimeException('Only administrators can manage location-editing permission.');
    }

    $manager = db_find('SELECT id, full_name, role FROM users WHERE id = ? AND role = "manager" LIMIT 1', 'i', [$managerId]);

    if (!$manager) {
        throw new InvalidArgumentException('Manager account was not found.');
    }

    if ($shopId > 0 && !fetch_shop_location_by_id($shopId)) {
        throw new InvalidArgumentException('Assigned shop was not found.');
    }

    $assignedShopId = $shopId > 0 ? $shopId : null;

    db_execute(
        'UPDATE users SET can_edit_shop_location = ?, assigned_shop_id = ?, updated_at = NOW() WHERE id = ? AND role = "manager"',
        'iii',
        [$canEdit ? 1 : 0, $assignedShopId, $managerId]
    );

    log_audit(
        (int) $admin['id'],
        'shop_location_permission_updated',
        'Administrator ' . (string) $admin['full_name'] . ' ' . ($canEdit ? 'granted' : 'removed') . ' shop location edit permission for manager ' . (string) $manager['full_name'] . '. Assigned shop: ' . ($assignedShopId === null ? 'All active shops' : (string) $assignedShopId)
    );

    create_notification(
        $managerId,
        'Shop Location Permission Updated',
        $canEdit ? 'You can now update your assigned shop location.' : 'Your shop location editing permission has been removed.',
        $canEdit ? 'success' : 'warning'
    );
}
