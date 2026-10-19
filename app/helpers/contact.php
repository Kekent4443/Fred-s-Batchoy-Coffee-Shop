<?php

declare(strict_types=1);

function ensure_contact_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    if (!database_column_exists('shop_locations', 'email_address')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN email_address VARCHAR(150) NULL AFTER contact_number');
    }

    db_execute('UPDATE shop_locations SET email_address = ? WHERE email_address IS NULL OR email_address = "" OR email_address LIKE "%.local"', 's', [app_settings()['support_email']]);

    if (!database_column_exists('shop_locations', 'operating_hours')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN operating_hours VARCHAR(180) NULL AFTER email_address');
        db_execute('UPDATE shop_locations SET operating_hours = "Open daily. Contact us for today\'s exact schedule." WHERE operating_hours IS NULL OR operating_hours = ""');
    }

    if (!database_column_exists('shop_locations', 'facebook_label')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN facebook_label VARCHAR(120) NULL AFTER operating_hours');
    }

    if (!database_column_exists('shop_locations', 'facebook_url')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN facebook_url VARCHAR(500) NULL AFTER facebook_label');
    }

    if (!database_column_exists('shop_locations', 'support_heading')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN support_heading VARCHAR(180) NULL AFTER facebook_url');
    }

    if (!database_column_exists('shop_locations', 'support_description')) {
        db_execute('ALTER TABLE shop_locations ADD COLUMN support_description VARCHAR(500) NULL AFTER support_heading');
    }

    db_execute('UPDATE shop_locations SET facebook_label = "Facebook" WHERE facebook_label IS NULL OR facebook_label = ""');
    db_execute('UPDATE shop_locations SET facebook_url = "https://www.facebook.com/george.tabanao.543" WHERE facebook_url IS NULL OR facebook_url = ""');
    db_execute('UPDATE shop_locations SET support_heading = "Need help?" WHERE support_heading IS NULL OR support_heading = ""');
    db_execute('UPDATE shop_locations SET support_description = "Reach us for orders, questions, and customer support." WHERE support_description IS NULL OR support_description = ""');

    db_execute('CREATE TABLE IF NOT EXISTS contact_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        related_order_id INT UNSIGNED NULL,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(40) NOT NULL,
        subject VARCHAR(180) NOT NULL,
        category VARCHAR(60) NOT NULL,
        message TEXT NOT NULL,
        status ENUM("new", "in_progress", "resolved", "closed") NOT NULL DEFAULT "new",
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $checked = true;
}

function contact_categories(): array
{
    return [
        'product_inquiry' => 'Product inquiry',
        'order_concern' => 'Order concern',
        'payment_concern' => 'Payment concern',
        'delivery_concern' => 'Delivery concern',
        'account_concern' => 'Account concern',
        'technical_issue' => 'Technical issue',
        'general_inquiry' => 'General inquiry',
        'other' => 'Other',
    ];
}

function contact_status_options(): array
{
    return [
        'new' => 'New',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

function contact_business_info(): array
{
    $shop = current_shop_location();
    $phone = trim((string) ($shop['contact_number'] ?? ''));
    $email = trim((string) ($shop['email_address'] ?? ''));
    if ($phone === '09171234567') {
        $phone = '';
    }
    if ($email === '' || strcasecmp($email, 'support@example.com') === 0) {
        $email = '';
    }

    return [
        'shop_name' => (string) ($shop['shop_name'] ?? app_settings()['brand_name']),
        'complete_address' => (string) ($shop['complete_address'] ?? ''),
        'contact_number' => $phone,
        'email_address' => $email,
        'operating_hours' => trim((string) ($shop['operating_hours'] ?? '')) !== '' ? (string) $shop['operating_hours'] : 'Open daily. Contact us for today\'s exact schedule.',
        'facebook_label' => trim((string) ($shop['facebook_label'] ?? '')) !== '' ? (string) $shop['facebook_label'] : 'Facebook',
        'facebook_url' => trim((string) ($shop['facebook_url'] ?? '')) !== '' ? (string) $shop['facebook_url'] : 'https://www.facebook.com/george.tabanao.543',
        'support_heading' => trim((string) ($shop['support_heading'] ?? '')) !== '' ? (string) $shop['support_heading'] : 'Need help?',
        'support_description' => trim((string) ($shop['support_description'] ?? '')) !== '' ? (string) $shop['support_description'] : 'Reach us for orders, questions, and customer support.',
        'landmark' => (string) ($shop['landmark'] ?? ''),
        'map_url' => shop_location_public_url($shop),
        'map_embed_url' => shop_location_embed_src($shop),
    ];
}

function validate_contact_text(string $value, string $field, int $maxLength, bool $required = true): string
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

function validate_contact_url(string $url, string $field): string
{
    $url = trim($url);

    if ($url === '') {
        throw new InvalidArgumentException($field . ' is required.');
    }

    if (preg_match('/<\s*(iframe|script)|javascript:|data:|[<>]/i', $url)) {
        throw new InvalidArgumentException($field . ' must be a safe URL only.');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException($field . ' must be a valid URL.');
    }

    $parts = parse_url($url);
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new InvalidArgumentException($field . ' must use HTTPS.');
    }

    return $url;
}

function contact_settings_payload_from_request(): array
{
    $email = strtolower(validate_contact_text((string) request('email_address', ''), 'Gmail/support email', 150));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid Gmail/support email.');
    }

    $phone = validate_contact_text((string) request('contact_number', ''), 'Phone number', 40);
    validate_phone_number($phone);

    return [
        'facebook_label' => validate_contact_text((string) request('facebook_label', ''), 'Facebook label', 120),
        'facebook_url' => validate_contact_url((string) request('facebook_url', ''), 'Facebook URL'),
        'email_address' => $email,
        'contact_number' => $phone,
        'support_heading' => validate_contact_text((string) request('support_heading', ''), 'Support heading', 180),
        'support_description' => validate_contact_text((string) request('support_description', ''), 'Support description', 500),
    ];
}

function can_manage_contact_settings(array $viewer): bool
{
    $shop = current_shop_location();

    return user_can_edit_shop_location($viewer, (int) ($shop['id'] ?? 0));
}

function contact_settings_audit_snapshot(array $shop): array
{
    return [
        'facebook_label' => $shop['facebook_label'] ?? '',
        'facebook_url' => $shop['facebook_url'] ?? '',
        'email_address' => $shop['email_address'] ?? '',
        'contact_number' => $shop['contact_number'] ?? '',
        'support_heading' => $shop['support_heading'] ?? '',
        'support_description' => $shop['support_description'] ?? '',
    ];
}

function save_contact_settings(array $viewer, array $payload): void
{
    ensure_contact_schema();

    $shop = current_shop_location();
    $shopId = (int) ($shop['id'] ?? 0);

    if ($shopId <= 0) {
        throw new RuntimeException('No active shop location was found.');
    }

    if (!can_manage_contact_settings($viewer)) {
        throw new RuntimeException('You do not have permission to update Contact Us details.');
    }

    $previous = fetch_shop_location_by_id($shopId) ?? $shop;

    db_execute(
        'UPDATE shop_locations
         SET facebook_label = ?, facebook_url = ?, email_address = ?, contact_number = ?, support_heading = ?, support_description = ?, updated_at = NOW()
         WHERE id = ?',
        'ssssssi',
        [
            $payload['facebook_label'],
            $payload['facebook_url'],
            $payload['email_address'],
            $payload['contact_number'],
            $payload['support_heading'],
            $payload['support_description'],
            $shopId,
        ]
    );

    $updated = fetch_shop_location_by_id($shopId) ?? [];
    $roleLabel = role_label((string) ($viewer['role'] ?? 'user'));
    $description = 'Contact Us settings updated by ' . (string) ($viewer['full_name'] ?? 'Unknown user') . ' (' . $roleLabel . ') for shop #' . $shopId
        . '. Previous: ' . json_encode(contact_settings_audit_snapshot($previous), JSON_UNESCAPED_SLASHES)
        . '. New: ' . json_encode(contact_settings_audit_snapshot($updated), JSON_UNESCAPED_SLASHES);

    log_audit(!empty($viewer['id']) ? (int) $viewer['id'] : null, 'contact_settings_updated', $description);
}

function validate_contact_order(?array $viewer, int $orderId): ?int
{
    if ($orderId <= 0) {
        return null;
    }

    if (!$viewer || ($viewer['role'] ?? '') !== 'customer') {
        throw new InvalidArgumentException('Related order is only available for logged-in customers.');
    }

    $order = db_find(
        'SELECT id FROM orders WHERE id = ? AND customer_id = ? LIMIT 1',
        'ii',
        [$orderId, (int) $viewer['id']]
    );

    if (!$order) {
        throw new InvalidArgumentException('Please select one of your own orders.');
    }

    return $orderId;
}

function contact_payload_from_request(?array $viewer): array
{
    $category = (string) request('category', 'general_inquiry');

    if (!array_key_exists($category, contact_categories())) {
        throw new InvalidArgumentException('Please select a valid concern category.');
    }

    $email = strtolower(validate_contact_text((string) request('email', ''), 'Email address', 150));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    $message = validate_contact_text((string) request('message', ''), 'Message', 3000);

    if (strlen($message) < 10) {
        throw new InvalidArgumentException('Please enter a more complete message.');
    }

    return [
        'user_id' => $viewer ? (int) $viewer['id'] : null,
        'related_order_id' => validate_contact_order($viewer, (int) request('related_order_id', 0)),
        'full_name' => validate_contact_text((string) request('full_name', ''), 'Full name', 150),
        'email' => $email,
        'contact_number' => validate_contact_text((string) request('contact_number', ''), 'Contact number', 40),
        'subject' => validate_contact_text((string) request('subject', ''), 'Subject', 180),
        'category' => $category,
        'message' => $message,
    ];
}

function submit_contact_message(array $payload): int
{
    ensure_contact_schema();
    $database = get_db();
    $database->begin_transaction();

    try {
        if (!empty($payload['user_id'])) {
            $user = db_find('SELECT id, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $payload['user_id']]);
            if (!$user || ($user['status'] ?? '') !== 'active' || !empty($user['deleted_at'])) {
                throw new InvalidArgumentException('Your account is not available to submit this inquiry.');
            }
        }

        db_execute(
            'INSERT INTO contact_messages (user_id, related_order_id, full_name, email, contact_number, subject, category, message, status, is_read, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "new", 0, NOW(), NOW())',
            'iissssss',
            [$payload['user_id'], $payload['related_order_id'], $payload['full_name'], $payload['email'], $payload['contact_number'], $payload['subject'], $payload['category'], $payload['message']]
        );
        $messageId = db_last_id();
        notify_contact_recipients($messageId, $payload);
        log_audit($payload['user_id'], 'contact_message_submitted', 'Contact inquiry #' . $messageId . ' submitted by ' . $payload['full_name']);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    return $messageId;
}

function notify_contact_recipients(int $messageId, array $payload): void
{
    $categoryLabel = contact_categories()[$payload['category']] ?? 'General inquiry';
    $notice = $payload['full_name'] . ' submitted a ' . $categoryLabel . ' on ' . format_display_datetime('now') . ': ' . $payload['subject'];
    notify_active_roles(['administrator', 'manager', 'staff'], 'New Contact Inquiry #' . $messageId, $notice, 'info');
}

function fetch_contact_messages(array $viewer, array $filters = []): array
{
    ensure_contact_schema();

    $sql = 'SELECT cm.*, u.role AS user_role, o.reference_no
            FROM contact_messages cm
            LEFT JOIN users u ON u.id = cm.user_id
            LEFT JOIN orders o ON o.id = cm.related_order_id
            WHERE 1=1';
    $types = '';
    $params = [];

    if (($viewer['role'] ?? '') === 'customer') {
        $sql .= ' AND cm.user_id = ?';
        $types .= 'i';
        $params[] = (int) $viewer['id'];
    }

    $category = (string) ($filters['category'] ?? '');
    if ($category !== '' && array_key_exists($category, contact_categories())) {
        $sql .= ' AND cm.category = ?';
        $types .= 's';
        $params[] = $category;
    }

    $status = (string) ($filters['status'] ?? '');
    if ($status !== '' && array_key_exists($status, contact_status_options())) {
        $sql .= ' AND cm.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    $date = (string) ($filters['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND DATE(cm.created_at) = ?';
        $types .= 's';
        $params[] = $date;
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (cm.full_name LIKE ? OR cm.email LIKE ? OR cm.subject LIKE ? OR cm.message LIKE ? OR o.reference_no LIKE ?)';
        $types .= 'sssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY cm.created_at DESC LIMIT 100';

    return db_select($sql, $types, $params);
}

function fetch_contact_message_for_viewer(int $id, array $viewer): ?array
{
    ensure_contact_schema();

    $sql = 'SELECT cm.*, u.role AS user_role, o.reference_no
            FROM contact_messages cm
            LEFT JOIN users u ON u.id = cm.user_id
            LEFT JOIN orders o ON o.id = cm.related_order_id
            WHERE cm.id = ?';
    $types = 'i';
    $params = [$id];

    if (($viewer['role'] ?? '') === 'customer') {
        $sql .= ' AND cm.user_id = ?';
        $types .= 'i';
        $params[] = (int) $viewer['id'];
    }

    $sql .= ' LIMIT 1';

    return db_find($sql, $types, $params);
}

function update_contact_message(int $id, array $viewer, string $status, string $note, string $response, bool $sendReply): void
{
    if (!in_array((string) ($viewer['role'] ?? ''), ['administrator', 'manager', 'staff'], true)) {
        throw new RuntimeException('You do not have permission to manage contact messages.');
    }

    if (!array_key_exists($status, contact_status_options())) {
        throw new InvalidArgumentException('Please select a valid message status.');
    }

    $note = trim($note);
    $response = trim($response);
    if ($sendReply && $response === '') {
        throw new InvalidArgumentException('Enter a response before sending a reply.');
    }

    $database = get_db();
    $database->begin_transaction();
    $queuedReply = false;

    try {
        $actor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $viewer['id']]);
        if (!$actor || ($actor['status'] ?? '') !== 'active' || !empty($actor['deleted_at'])
            || !in_array((string) ($actor['role'] ?? ''), ['administrator', 'manager', 'staff'], true)) {
            throw new RuntimeException('You do not have permission to manage contact messages.');
        }

        $message = db_find('SELECT * FROM contact_messages WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
        if (!$message) {
            throw new InvalidArgumentException('Contact message was not found.');
        }

        db_execute(
            'UPDATE contact_messages
             SET status = ?, is_read = 1, internal_note = ?, response_message = ?, responded_by = ?,
                 responded_at = IF(? <> "", NOW(), responded_at), updated_at = NOW()
             WHERE id = ?',
            'sssisi',
            [$status, $note, $response, (int) $viewer['id'], $response, $id]
        );

        if ($sendReply) {
            $queuedReply = send_email_notification(
                !empty($message['user_id']) ? (int) $message['user_id'] : null,
                (string) $message['email'],
                'Re: ' . (string) $message['subject'],
                $response,
                '<p>' . nl2br(e($response)) . '</p>'
            );
            if (!$queuedReply) {
                throw new RuntimeException('Unable to queue the contact reply email.');
            }
            if (!empty($message['user_id'])) {
                create_notification((int) $message['user_id'], 'Contact Inquiry Reply', 'Support replied to your inquiry #' . $id . '.', 'info');
            }
        }

        log_audit((int) $viewer['id'], 'contact_message_updated', 'Updated contact inquiry #' . $id . ' to ' . $status . ($sendReply ? ' and queued a reply.' : '.'));
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    if ($queuedReply && function_exists('schedule_opportunistic_email_queue_processing')) {
        schedule_opportunistic_email_queue_processing(true);
    }
}
