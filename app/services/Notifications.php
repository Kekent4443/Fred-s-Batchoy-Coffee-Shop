<?php

declare(strict_types=1);

function create_notification(
    ?int $userId,
    string $title,
    string $message,
    string $type = 'info',
    ?string $link = null,
    ?string $eventKey = null,
    ?string $knownRecipientRole = null
): void
{
    $type = in_array($type, ['success', 'info', 'warning', 'danger'], true) ? $type : 'info';
    $title = trim($title);
    $message = trim($message);

    if ($title === '' || $message === '') {
        return;
    }

    $rawLink = trim((string) $link);
    $link = $rawLink !== '' ? notification_normalize_route($rawLink) : '';
    if ($rawLink !== '' && $link === '') {
        notification_log_invalid_target($rawLink, 'create_notification');
    }
    $eventKey = $eventKey !== null ? trim($eventKey) : '';

    $recipientRole = $knownRecipientRole;
    if ($recipientRole === null && $userId !== null) {
        $recipient = db_find('SELECT role FROM users WHERE id = ? LIMIT 1', 'i', [$userId]);
        $recipientRole = $recipient['role'] ?? null;
    }

    if ($userId === null) {
        try {
            db_execute(
                'INSERT INTO notifications (user_id, role, title, message, link, event_key, type, status, is_read, created_at, updated_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, "unread", 0, NOW(), NOW())',
                'ssssss',
                [$recipientRole, $title, $message, $link !== '' ? $link : null, $eventKey !== '' ? $eventKey : null, $type]
            );
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1062 || $eventKey === '') {
                throw $exception;
            }
        }
        return;
    }

    try {
        db_execute(
            'INSERT INTO notifications (user_id, role, title, message, link, event_key, type, status, is_read, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "unread", 0, NOW(), NOW())',
            'issssss',
            [$userId, $recipientRole, $title, $message, $link !== '' ? $link : null, $eventKey !== '' ? $eventKey : null, $type]
        );
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1062 || $eventKey === '') {
            throw $exception;
        }
    }
}

function system_notification_recipient_ids(string $audience): array
{
    if (!in_array($audience, ['all', 'customer', 'staff', 'manager'], true)) {
        throw new InvalidArgumentException('Please select a valid notification audience.');
    }

    $rows = $audience === 'all'
        ? db_select('SELECT id FROM users WHERE status = "active" AND deleted_at IS NULL')
        : db_select('SELECT id FROM users WHERE role = ? AND status = "active" AND deleted_at IS NULL', 's', [$audience]);

    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

function notify_active_roles(array $roles, string $title, string $message, string $type = 'info', array $excludeUserIds = [], ?string $link = null, ?string $eventKey = null): void
{
    $roles = array_values(array_unique(array_intersect(array_map('strval', $roles), all_roles())));
    if ($roles === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $recipients = db_select(
        'SELECT id, role FROM users
         WHERE role IN (' . $placeholders . ') AND status = "active" AND deleted_at IS NULL',
        str_repeat('s', count($roles)),
        $roles
    );
    $exclude = array_fill_keys(array_map('intval', $excludeUserIds), true);

    foreach ($recipients as $recipient) {
        $recipientId = (int) ($recipient['id'] ?? 0);
        if ($recipientId <= 0 || isset($exclude[$recipientId])) {
            continue;
        }

        create_notification($recipientId, $title, $message, $type, $link, $eventKey, (string) $recipient['role']);
    }
}

function ensure_notification_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    $columns = [
        'role' => 'ALTER TABLE notifications ADD COLUMN role VARCHAR(50) NULL AFTER user_id',
        'link' => 'ALTER TABLE notifications ADD COLUMN link VARCHAR(500) NULL AFTER message',
        'event_key' => 'ALTER TABLE notifications ADD COLUMN event_key VARCHAR(191) NULL AFTER link',
        'is_read' => 'ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
        'read_at' => 'ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read',
    ];

    foreach ($columns as $column => $sql) {
        if (!database_column_exists('notifications', $column)) {
            db_execute($sql);
        }
    }

    // Keep rows created before the migration compatible with the new state fields.
    db_execute('UPDATE notifications SET is_read = CASE WHEN status = "read" THEN 1 ELSE 0 END WHERE is_read <> CASE WHEN status = "read" THEN 1 ELSE 0 END');
    db_execute('UPDATE notifications SET read_at = COALESCE(read_at, updated_at) WHERE status = "read" AND read_at IS NULL');
    db_execute('UPDATE notifications n INNER JOIN users u ON u.id = n.user_id SET n.role = u.role WHERE n.role IS NULL');

    $checked = true;
}

function fetch_notifications(?int $userId = null, int $limit = 10): array
{
    $limitSql = $limit > 0 ? ' LIMIT ?' : '';

    if ($userId === null) {
        $sql = 'SELECT n.*, u.full_name, u.role AS user_role' . priority_select_sql('notification') . '
                FROM notifications n
                LEFT JOIN users u ON u.id = n.user_id
                ORDER BY n.created_at DESC, n.id DESC' . $limitSql;

        if ($limit > 0) {
            return db_select($sql, 'i', [$limit]);
        }

        return db_select($sql);
    }

    $sql = 'SELECT n.*, u.full_name, u.role AS user_role' . priority_select_sql('notification') . '
            FROM notifications n
            LEFT JOIN users u ON u.id = n.user_id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC, n.id DESC' . $limitSql;

    if ($limit > 0) {
        return db_select(
            $sql,
            'ii',
            [$userId, $limit]
        );
    }

    return db_select(
        $sql,
        'i',
        [$userId]
    );
}

function notification_type_options(): array
{
    return [
        '' => 'All',
        'success' => 'Success',
        'info' => 'Information',
        'warning' => 'Warning',
    ];
}

function notification_type_label(string $type): string
{
    return match ($type) {
        'success' => 'Success',
        'warning' => 'Warning',
        'danger' => 'Warning',
        default => 'Information',
    };
}

function notification_type_icon(string $type): string
{
    return match ($type) {
        'success' => '✓',
        'warning', 'danger' => '!',
        default => 'i',
    };
}

function normalize_notification_type_filter(string $type): string
{
    return array_key_exists($type, notification_type_options()) ? $type : '';
}

function notification_scope_where(?int $scopeUserId, string $tableAlias = 'n'): array
{
    if ($scopeUserId === null) {
        return ['', '', []];
    }

    return [' WHERE ' . $tableAlias . '.user_id = ?', 'i', [$scopeUserId]];
}

function fetch_notifications_filtered(?int $userId = null, string $type = '', int $limit = 100): array
{
    $type = normalize_notification_type_filter($type);
    [$where, $types, $params] = notification_scope_where($userId, 'n');

    if ($type !== '') {
        if ($type === 'warning') {
            $where .= $where === '' ? ' WHERE n.type IN ("warning", "danger")' : ' AND n.type IN ("warning", "danger")';
        } else {
            $where .= $where === '' ? ' WHERE n.type = ?' : ' AND n.type = ?';
            $types .= 's';
            $params[] = $type;
        }
    }

    $limitSql = $limit > 0 ? ' LIMIT ?' : '';
    if ($limit > 0) {
        $types .= 'i';
        $params[] = $limit;
    }

    return db_select(
        'SELECT n.*, u.full_name, u.role AS user_role' . priority_select_sql('notification') . '
         FROM notifications n
         LEFT JOIN users u ON u.id = n.user_id'
         . $where .
        ' ORDER BY n.created_at DESC, n.id DESC' . $limitSql,
        $types,
        $params
    );
}

/**
 * Returns page-level notification counters without depending on the paginated
 * notification list. All four counters come from one aggregate query so a
 * page cannot display totals derived from only its first 100 rows.
 */
function notification_metrics(?int $userId = null, string $type = ''): array
{
    $type = normalize_notification_type_filter($type);
    [$where, $types, $params] = notification_scope_where($userId, 'n');

    if ($type === 'warning') {
        $where .= $where === '' ? ' WHERE n.type IN ("warning", "danger")' : ' AND n.type IN ("warning", "danger")';
    } elseif ($type !== '') {
        $where .= $where === '' ? ' WHERE n.type = ?' : ' AND n.type = ?';
        $types .= 's';
        $params[] = $type;
    }

    $row = db_find(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN n.status = "unread" THEN 1 ELSE 0 END), 0) AS unread,
                COALESCE(SUM(CASE WHEN n.status = "read" THEN 1 ELSE 0 END), 0) AS `read`,
                COALESCE(SUM(CASE WHEN n.created_at >= CURRENT_DATE AND n.created_at < CURRENT_DATE + INTERVAL 1 DAY THEN 1 ELSE 0 END), 0) AS today
         FROM notifications n' . $where,
        $types,
        $params
    ) ?? [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'unread' => (int) ($row['unread'] ?? 0),
        'read' => (int) ($row['read'] ?? 0),
        'today' => (int) ($row['today'] ?? 0),
    ];
}

function unread_notification_count(?int $userId = null): int
{
    if ($userId === null) {
        $row = db_find('SELECT COUNT(*) AS total FROM notifications WHERE status = "unread"');

        return (int) ($row['total'] ?? 0);
    }

    $row = db_find(
        'SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND status = "unread"',
        'i',
        [$userId]
    );

    return (int) ($row['total'] ?? 0);
}

function unread_notification_text_count(?int $userId, array $needles): int
{
    $needles = array_values(array_filter(array_map(
        static fn (string $needle): string => trim(strtolower($needle)),
        $needles
    )));

    if ($needles === []) {
        return 0;
    }

    $sql = 'SELECT COUNT(*) AS total FROM notifications WHERE status = "unread"';
    $types = '';
    $params = [];

    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    $parts = [];
    foreach ($needles as $needle) {
        $parts[] = 'LOWER(CONCAT(title, " ", message)) LIKE ?';
        $types .= 's';
        $params[] = '%' . $needle . '%';
    }

    $sql .= ' AND (' . implode(' OR ', $parts) . ')';
    $row = db_find($sql, $types, $params);

    return (int) ($row['total'] ?? 0);
}

function contact_unread_count_for_user(array $viewer): int
{
    $role = (string) ($viewer['role'] ?? '');

    if (!in_array($role, ['administrator', 'manager', 'staff', 'rider', 'customer'], true)) {
        return 0;
    }

    if ($role === 'customer') {
        $row = db_find(
            'SELECT COUNT(*) AS total FROM contact_messages WHERE user_id = ? AND is_read = 0',
            'i',
            [(int) $viewer['id']]
        );

        return (int) ($row['total'] ?? 0);
    }

    $row = db_find('SELECT COUNT(*) AS total FROM contact_messages WHERE is_read = 0');

    return (int) ($row['total'] ?? 0);
}

function sidebar_menu_unread_count(array $viewer, string $label, string $path): int
{
    $key = sidebar_menu_badge_key($label, $path);
    if ($key === '') {
        return 0;
    }

    return (int) (notification_menu_badge_counts($viewer)[$key]['count'] ?? 0);
}

function sidebar_menu_badge_key(string $label, string $path): string
{
    $labelText = strtolower($label . ' ' . $path);

    return match (true) {
        str_contains($labelText, 'notification') => 'notifications',
        str_contains($labelText, 'support') => 'support',
        str_contains($labelText, 'contact messages') => 'contact_messages',
        str_contains($labelText, 'customer verification') => 'customer_verification',
        str_contains($labelText, 'transaction') => 'transactions',
        str_contains($labelText, 'deliver') => 'deliveries',
        str_contains($labelText, 'order') => 'orders',
        default => '',
    };
}

function notification_unread_category_counts(array $viewer): array
{
    $userId = notification_scope_user_id($viewer);
    if ($userId === null) {
        return ['notifications' => 0, 'customer_verification' => 0, 'transactions' => 0, 'deliveries' => 0, 'orders' => 0];
    }

    // The bell reflects unread events. Operational menu badges are calculated
    // separately from current work queues, not from words in historic titles.
    $unread = unread_notification_count($userId);

    return [
        'notifications' => $unread,
        'customer_verification' => 0,
        'transactions' => 0,
        'deliveries' => 0,
        'orders' => 0,
    ];
}

function notification_menu_badge_counts(array $viewer, ?array $notificationCounts = null): array
{
    $counts = $notificationCounts ?? notification_unread_category_counts($viewer);
    $role = (string) ($viewer['role'] ?? '');
    $viewerId = (int) ($viewer['id'] ?? 0);

    if (in_array($role, ['administrator', 'manager', 'staff'], true)) {
        $counts['customer_verification'] = (int) (db_find(
            'SELECT COUNT(*) AS total FROM users
             WHERE role = "customer" AND status = "pending_verification"
               AND verification_status = "pending" AND deleted_at IS NULL'
        )['total'] ?? 0);
        $counts['transactions'] = (int) (db_find(
            'SELECT COUNT(*) AS total FROM transactions t
             INNER JOIN orders o ON o.id = t.order_id
             WHERE t.payment_method = "gcash" AND t.transaction_status = "pending"
               AND COALESCE(t.proof_of_payment_path, "") <> ""
               AND o.order_status <> "cancelled"'
        )['total'] ?? 0);
        $orderSql = 'SELECT COUNT(*) AS total FROM orders o
                     WHERE o.order_status IN ("pending", "confirmed", "processing")
                       AND o.delivery_status NOT IN ("assigned", "out_for_delivery", "delivered", "cancelled", "issue_reported", "failed_delivery")
                       AND o.payment_status NOT IN ("failed", "cancelled")';
        if ($role === 'staff') {
            $orderSql .= ' AND (o.prepared_by_staff_id IS NULL OR o.prepared_by_staff_id = ?)';
            $counts['orders'] = (int) (db_find($orderSql, 'i', [$viewerId])['total'] ?? 0);
        } else {
            $counts['orders'] = (int) (db_find($orderSql)['total'] ?? 0);
        }
    }

    if (in_array($role, ['administrator', 'manager'], true)) {
        $counts['deliveries'] = (int) (db_find(
            'SELECT COUNT(*) AS total FROM deliveries d
             INNER JOIN orders o ON o.id = d.order_id
             WHERE o.order_status <> "cancelled"
               AND (d.delivery_status IN ("issue_reported", "failed_delivery")
                    OR (d.delivery_status = "assigned" AND d.assigned_staff_id IS NULL
                        AND (o.payment_method <> "gcash" OR o.payment_status IN ("accepted", "paid"))))'
        )['total'] ?? 0);
    } elseif ($role === 'rider' && $viewerId > 0) {
        $counts['deliveries'] = (int) (db_find(
            'SELECT COUNT(*) AS total FROM deliveries d
             INNER JOIN orders o ON o.id = d.order_id
             WHERE d.assigned_staff_id = ? AND d.delivery_status IN ("assigned", "out_for_delivery")
               AND (o.payment_method <> "gcash" OR o.payment_status IN ("accepted", "paid"))',
            'i',
            [$viewerId]
        )['total'] ?? 0);
    }

    // Support and contact menus use their canonical work queues. Adding their
    // corresponding notification rows would count one event two or three times.
    $counts['support'] = in_array($role, ['administrator', 'manager', 'staff', 'customer'], true)
        ? support_pending_count_for_user($viewer)
        : 0;
    $counts['contact_messages'] = in_array($role, ['administrator', 'manager', 'staff'], true)
        ? contact_unread_count_for_user($viewer)
        : 0;

    foreach ($counts as $key => $count) {
        $count = max(0, (int) $count);
        $counts[$key] = ['count' => $count, 'label' => notification_count_label($count)];
    }

    return $counts;
}

function mark_all_notifications_read(?int $userId = null, string $type = ''): int
{
    $type = normalize_notification_type_filter($type);

    if ($userId === null && $type === '') {
        $result = get_db()->query('UPDATE notifications SET status = "read", is_read = 1, read_at = COALESCE(read_at, NOW()), updated_at = NOW() WHERE status = "unread"');
        return $result ? get_db()->affected_rows : 0;
    }

    $sql = 'UPDATE notifications SET status = "read", updated_at = NOW() WHERE status = "unread"';
    $types = '';
    $params = [];

    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    if ($type !== '') {
        if ($type === 'warning') {
            $sql .= ' AND type IN ("warning", "danger")';
        } else {
            $sql .= ' AND type = ?';
            $types .= 's';
            $params[] = $type;
        }
    }

    $sql = str_replace('SET status = "read", updated_at = NOW()', 'SET status = "read", is_read = 1, read_at = COALESCE(read_at, NOW()), updated_at = NOW()', $sql);
    db_execute($sql, $types, $params);

    return (int) (db_find('SELECT ROW_COUNT() AS total')['total'] ?? 0);
}

function notification_count_label(int $count): string
{
    return $count > 99 ? '99+' : (string) max(0, $count);
}

/**
 * Shared, request-cached badge state for the current notification owner.
 *
 * Keeping the count and its display label together prevents headers, sidebars,
 * drawers, and JSON endpoints from calculating subtly different values.
 */
function notification_badge_state(array $viewer, bool $includeMenuCounts = false, bool $refresh = false): array
{
    static $baseCache = [];
    static $menuCache = [];

    $scopeUserId = notification_scope_user_id($viewer);
    if ($scopeUserId === null) {
        return [
            'scope_user_id' => null,
            'unread_count' => 0,
            'unread_label' => '0',
            'menu_counts' => [],
        ];
    }

    $cacheKey = (string) $scopeUserId;
    if ($includeMenuCounts && ($refresh || !isset($menuCache[$cacheKey]))) {
        $categoryCounts = notification_unread_category_counts($viewer);
        $unreadCount = (int) ($categoryCounts['notifications'] ?? 0);
        $baseCache[$cacheKey] = [
            'scope_user_id' => $scopeUserId,
            'unread_count' => $unreadCount,
            'unread_label' => notification_count_label($unreadCount),
        ];
        $menuCache[$cacheKey] = notification_menu_badge_counts($viewer, $categoryCounts);
    } elseif ($refresh || !isset($baseCache[$cacheKey])) {
        $unreadCount = unread_notification_count($scopeUserId);
        $baseCache[$cacheKey] = [
            'scope_user_id' => $scopeUserId,
            'unread_count' => $unreadCount,
            'unread_label' => notification_count_label($unreadCount),
        ];
        unset($menuCache[$cacheKey]);
    }

    $state = $baseCache[$cacheKey];
    if ($includeMenuCounts) {
        if (!isset($menuCache[$cacheKey])) {
            $menuCache[$cacheKey] = notification_menu_badge_counts($viewer);
        }
        $state['menu_counts'] = $menuCache[$cacheKey];
    }

    return $state;
}

function notification_scope_user_id(?array $viewer): ?int
{
    if (!$viewer) {
        return null;
    }

    return (int) $viewer['id'];
}

function set_notification_status(int $id, string $status, ?int $userId = null): void
{
    if (!in_array($status, ['read', 'unread'], true)) {
        throw new InvalidArgumentException('Invalid notification status.');
    }

    if ($userId === null) {
        db_execute(
            'UPDATE notifications SET status = ?, is_read = ?, read_at = CASE WHEN ? = "read" THEN COALESCE(read_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = ?',
            'sisi',
            [$status, $status === 'read' ? 1 : 0, $status, $id]
        );

        return;
    }

    db_execute(
        'UPDATE notifications SET status = ?, is_read = ?, read_at = CASE WHEN ? = "read" THEN COALESCE(read_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = ? AND user_id = ?',
        'sisii',
        [$status, $status === 'read' ? 1 : 0, $status, $id, $userId]
    );
}

function delete_read_notifications(?int $userId): int
{
    if ($userId === null) {
        return 0;
    }

    db_execute('DELETE FROM notifications WHERE user_id = ? AND status = "read"', 'i', [$userId]);

    return (int) (db_find('SELECT ROW_COUNT() AS total')['total'] ?? 0);
}

function notification_link_for_viewer(array $viewer, array $notification): string
{
    $storedLink = trim((string) ($notification['link'] ?? ''));
    if ($storedLink !== '') {
        $normalizedStoredLink = notification_normalize_route($storedLink);
        if ($normalizedStoredLink !== '') {
            return $normalizedStoredLink;
        }

        notification_log_invalid_target($storedLink, 'notification #' . (int) ($notification['id'] ?? 0));
    }

    $generatedLink = notification_normalize_route(notification_related_url($viewer, $notification));
    if ($generatedLink !== '') {
        return $generatedLink;
    }

    // A general announcement has no related record. Keep it in the notification
    // detail view instead of linking back to the same notification page.
    return '';
}

function notification_absolute_url(array $viewer, array $notification): string
{
    $route = notification_link_for_viewer($viewer, $notification);

    return $route !== '' ? url($route) : '';
}

function notification_normalize_route(string $target): string
{
    $target = trim(html_entity_decode($target, ENT_QUOTES, 'UTF-8'));
    if ($target === '' || str_contains($target, "\0") || str_contains($target, '\\')) {
        return '';
    }

    $targetHost = (string) (parse_url($target, PHP_URL_HOST) ?? '');
    $appHost = (string) (parse_url(APP_URL, PHP_URL_HOST) ?? '');
    if ($targetHost !== '' && ($appHost === '' || strcasecmp($targetHost, $appHost) !== 0)) {
        return '';
    }

    $query = (string) (parse_url($target, PHP_URL_QUERY) ?? '');
    $path = (string) (parse_url($target, PHP_URL_PATH) ?? '');
    if ($path === '') {
        return '';
    }

    $publicMarker = '/public/';
    $markerPosition = strripos('/' . ltrim($path, '/'), $publicMarker);
    if ($markerPosition !== false) {
        $path = substr('/' . ltrim($path, '/'), $markerPosition + strlen($publicMarker));
    }

    $segments = array_values(array_filter(explode('/', trim(rawurldecode($path), '/')), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
    while (($segments[0] ?? '') === 'public') {
        array_shift($segments);
    }

    if (in_array('..', $segments, true) || $segments === []) {
        return '';
    }

    // Repair duplicated folder prefixes such as admin/admin and public/public.
    $deduplicated = [];
    foreach ($segments as $segment) {
        if ($deduplicated !== [] && end($deduplicated) === $segment && in_array($segment, ['admin', 'manager', 'staff', 'rider', 'customer', 'public'], true)) {
            continue;
        }
        $deduplicated[] = $segment;
    }

    $route = implode('/', $deduplicated);
    if (!notification_route_exists($route)) {
        // Shared pages such as customer-verifications.php live at /public, not in role folders.
        if (count($deduplicated) > 1 && in_array($deduplicated[0], ['admin', 'manager', 'staff', 'rider', 'customer'], true)) {
            $rootRoute = implode('/', array_slice($deduplicated, 1));
            if (notification_route_exists($rootRoute)) {
                $route = $rootRoute;
            } else {
                return '';
            }
        } else {
            return '';
        }
    }

    return $route . ($query !== '' ? '?' . $query : '');
}

function notification_route_exists(string $route): bool
{
    $path = (string) (parse_url($route, PHP_URL_PATH) ?? '');
    if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
        return false;
    }

    $candidate = PUBLIC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    $publicRoot = realpath(PUBLIC_ROOT);
    $target = realpath($candidate);

    return $publicRoot !== false
        && $target !== false
        && is_file($target)
        && str_starts_with(strtolower($target), strtolower($publicRoot . DIRECTORY_SEPARATOR));
}

function notification_log_invalid_target(string $target, string $context): void
{
    error_log('[notification-url] Invalid target in ' . $context . ': ' . str_replace(["\r", "\n"], '', $target));
}

function repair_existing_notification_links(): array
{
    $repaired = 0;
    $cleared = 0;
    $notifications = db_select(
        'SELECT n.*, u.id AS viewer_id, u.full_name AS viewer_name, u.email AS viewer_email, u.role AS viewer_role
         FROM notifications n
         LEFT JOIN users u ON u.id = n.user_id
         ORDER BY n.id'
    );

    foreach ($notifications as $notification) {
        if (empty($notification['viewer_id']) || empty($notification['viewer_role'])) {
            continue;
        }

        $viewer = [
            'id' => (int) $notification['viewer_id'],
            'full_name' => (string) ($notification['viewer_name'] ?? ''),
            'email' => (string) ($notification['viewer_email'] ?? ''),
            'role' => (string) $notification['viewer_role'],
        ];
        $oldLink = trim((string) ($notification['link'] ?? ''));
        // Rebuild from the event content first so previously valid-looking but
        // semantically incorrect links (for example payment verification being
        // sent to customer verification) are corrected too.
        $generatedLink = notification_normalize_route(notification_related_url($viewer, $notification));
        $newLink = $generatedLink !== '' ? $generatedLink : notification_link_for_viewer($viewer, $notification);

        if ($newLink === '' && $oldLink !== '') {
            db_execute('UPDATE notifications SET link = NULL WHERE id = ?', 'i', [(int) $notification['id']]);
            $cleared++;
        } elseif ($newLink !== '' && $newLink !== $oldLink) {
            db_execute('UPDATE notifications SET link = ? WHERE id = ?', 'si', [$newLink, (int) $notification['id']]);
            $repaired++;
        }
    }

    return ['repaired' => $repaired, 'cleared' => $cleared];
}

function notify_inventory_threshold(int $productId): void
{
    $product = db_find(
        'SELECT id, shop_id, name, stock_quantity, status, updated_at FROM products WHERE id = ? LIMIT 1',
        'i',
        [$productId]
    );

    if (!$product || ($product['status'] ?? '') !== 'active') {
        return;
    }

    $stock = max(0, (int) ($product['stock_quantity'] ?? 0));
    if ($stock > inventory_low_stock_threshold()) {
        return;
    }

    $state = $stock === 0 ? 'out_of_stock' : 'low_stock';
    $title = $stock === 0 ? 'Product Out of Stock' : 'Low Stock Alert';
    $message = $stock === 0
        ? $product['name'] . ' is out of stock and needs replenishment.'
        : $product['name'] . ' has only ' . $stock . ' item(s) remaining.';
    $eventKey = 'inventory:' . (int) $product['id'] . ':' . $state . ':' . (string) ($product['updated_at'] ?? '');

    $type = $stock === 0 ? 'warning' : 'info';
    notify_active_roles(['administrator', 'staff'], $title, $message, $type, [], null, $eventKey);

    $shopId = (int) ($product['shop_id'] ?? 0);
    if ($shopId < 1) {
        return;
    }

    $managers = db_select(
        'SELECT id
         FROM users
         WHERE role = "manager" AND status = "active" AND deleted_at IS NULL
           AND (assigned_shop_id = ? OR (
               assigned_shop_id IS NULL
               AND (SELECT COUNT(*) FROM shop_locations) = 1
           ))',
        'i',
        [$shopId]
    );
    foreach ($managers as $manager) {
        create_notification((int) $manager['id'], $title, $message, $type, null, $eventKey, 'manager');
    }
}

function mark_notification_read(int $id, ?int $userId = null): void
{
    set_notification_status($id, 'read', $userId);
}

function mark_notification_unread(int $id, ?int $userId = null): void
{
    set_notification_status($id, 'unread', $userId);
}

function notification_source_label(array $notification): string
{
    $name = trim((string) ($notification['full_name'] ?? ''));
    $role = trim((string) ($notification['user_role'] ?? $notification['role'] ?? ''));

    if ($name === '') {
        return 'System';
    }

    return $role !== '' ? $name . ' (' . role_label($role) . ')' : $name;
}

function notification_related_url(array $viewer, array $notification): string
{
    $text = strtolower((string) ($notification['title'] ?? '') . ' ' . (string) ($notification['message'] ?? ''));
    $rawText = (string) ($notification['title'] ?? '') . ' ' . (string) ($notification['message'] ?? '');
    $role = (string) ($viewer['role'] ?? '');
    $roleFolder = role_folder($role);
    $orderReference = '';

    if (preg_match('/\bORD-\d{8}-[A-Z0-9]{6,}\b/i', $rawText, $matches)) {
        $orderReference = strtoupper($matches[0]);
    }

    if (str_contains($text, 'support')) {
        preg_match('/conversation #(\d+)/i', $rawText, $matches);
        $conversationId = isset($matches[1]) ? (int) $matches[1] : 0;

        return $roleFolder . '/support.php' . ($conversationId > 0 ? '?conversation_id=' . $conversationId : '');
    }

    if (str_contains($text, 'contact inquiry') || str_contains($text, 'contact message')) {
        if ($role === 'customer') {
            return 'contact.php';
        }

        preg_match('/#(\d+)/', (string) ($notification['title'] ?? ''), $matches);
        $messageId = isset($matches[1]) ? (int) $matches[1] : 0;

        return $roleFolder . '/contact-messages.php' . ($messageId > 0 ? '?id=' . $messageId : '');
    }

    if (
        (str_contains($text, 'verification') && !str_contains($text, 'payment'))
        || str_contains($text, 'account approved')
        || str_contains($text, 'registration')
    ) {
        return $role === 'customer' ? 'customer/profile.php' : 'customer-verifications.php';
    }

    if (str_contains($text, 'delivery')) {
        if ($role === 'customer') {
            if ($orderReference !== '') {
                $order = db_find('SELECT id FROM orders WHERE reference_no = ? AND customer_id = ? LIMIT 1', 'si', [$orderReference, (int) $viewer['id']]);

                if ($order) {
                    return 'customer/order-detail.php?id=' . (int) $order['id'];
                }
            }

            return 'customer/my-orders.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
        }

        return $roleFolder . '/deliveries.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
    }

    if (str_contains($text, 'payment') || str_contains($text, 'transaction')) {
        if ($role === 'customer') {
            if ($orderReference !== '') {
                $order = db_find('SELECT id FROM orders WHERE reference_no = ? AND customer_id = ? LIMIT 1', 'si', [$orderReference, (int) $viewer['id']]);

                if ($order) {
                    return 'customer/order-detail.php?id=' . (int) $order['id'];
                }
            }

            return 'customer/my-orders.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
        }

        if ($orderReference !== '') {
            $transaction = db_find(
                'SELECT t.id
                 FROM transactions t
                 INNER JOIN orders o ON o.id = t.order_id
                 WHERE o.reference_no = ?
                 LIMIT 1',
                's',
                [$orderReference]
            );

            if ($transaction) {
                return $roleFolder . '/transactions.php?view=' . (int) $transaction['id'];
            }
        }

        return $roleFolder . '/transactions.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
    }

    if (str_contains($text, 'order')) {
        if ($role === 'customer') {
            if ($orderReference !== '') {
                $order = db_find('SELECT id FROM orders WHERE reference_no = ? AND customer_id = ? LIMIT 1', 'si', [$orderReference, (int) $viewer['id']]);

                if ($order) {
                    return 'customer/order-detail.php?id=' . (int) $order['id'];
                }
            }

            return 'customer/my-orders.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
        }

        return $roleFolder . '/orders.php' . ($orderReference !== '' ? '?search=' . rawurlencode($orderReference) : '');
    }

    if (str_contains($text, 'stock') || str_contains($text, 'replenishment')) {
        return $role === 'customer' ? 'customer/catalog.php' : $roleFolder . '/products.php';
    }

    return '';
}

