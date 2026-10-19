<?php

declare(strict_types=1);

function fetch_orders(string $role, int $userId, string $search = ''): array
{
    return fetch_orders_filtered($role, $userId, ['search' => $search]);
}

function fetch_orders_filtered(string $role, int $userId, array $filters = []): array
{
    ensure_record_management_tables();

    $priorityContext = ['role' => $role, 'user_id' => $userId];

    $sql = 'SELECT o.*, d.assigned_staff_id AS delivery_assigned_staff_id,
                   d.delivery_status AS delivery_record_status, d.delivered_at AS delivery_delivered_at,
                   t.payment_method AS transaction_payment_method,
                   t.gcash_reference, t.proof_of_payment_path, t.transaction_status,
                   t.recorded_by AS payment_reviewed_by, t.reviewed_at AS payment_reviewed_at,
                   (SELECT prh.action FROM payment_review_history prh
                    WHERE prh.order_id = o.id ORDER BY prh.created_at DESC, prh.id DESC LIMIT 1) AS payment_review_action,
                   u.full_name AS customer_name, rider.full_name AS staff_name,
                   preparer.full_name AS prepared_by_name, COALESCE(items.item_count, 0) AS item_count,
                   COALESCE(rs.record_status, "active") AS record_status'
                   . priority_select_sql('order', [], $priorityContext) . '
            FROM orders o
            LEFT JOIN deliveries d ON d.order_id = o.id
            LEFT JOIN transactions t ON t.order_id = o.id
            LEFT JOIN users u ON u.id = o.customer_id
            LEFT JOIN users rider ON rider.id = COALESCE(d.assigned_staff_id, o.assigned_staff_id)
            LEFT JOIN users preparer ON preparer.id = o.prepared_by_staff_id
            LEFT JOIN (SELECT order_id, SUM(quantity) AS item_count FROM order_items GROUP BY order_id) items ON items.order_id = o.id
            LEFT JOIN record_states rs ON rs.record_type = "order" AND rs.record_id = o.id
            WHERE 1=1';
    $types = '';
    $params = [];
    $search = trim((string) ($filters['search'] ?? ''));

    if ($role === 'customer') {
        $sql .= ' AND o.customer_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } elseif ($role === 'rider') {
        $sql .= ' AND o.assigned_staff_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } elseif ($role === 'staff') {
        $sql .= ' AND (o.prepared_by_staff_id IS NULL OR o.prepared_by_staff_id = ?)';
        $types .= 'i';
        $params[] = $userId;
    }

    if ($search !== '') {
        $sql .= ' AND (o.reference_no LIKE ? OR u.full_name LIKE ? OR o.order_status LIKE ? OR o.payment_status LIKE ? OR o.delivery_status LIKE ?)';
        $types .= 'sssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if (!empty($filters['order_status'])) {
        $sql .= ' AND o.order_status = ?';
        $types .= 's';
        $params[] = $filters['order_status'];
    }

    if (!empty($filters['payment_status'])) {
        $sql .= ' AND o.payment_status = ?';
        $types .= 's';
        $params[] = $filters['payment_status'];
    }

    if (!empty($filters['delivery_status'])) {
        $sql .= ' AND o.delivery_status = ?';
        $types .= 's';
        $params[] = $filters['delivery_status'];
    }

    $sql .= ' ORDER BY ' . priority_order_sql('order', 'GREATEST(COALESCE(o.updated_at, o.created_at), COALESCE(d.updated_at, o.created_at), COALESCE(t.updated_at, o.created_at))', [], $priorityContext);

    return db_select($sql, $types, $params);
}

function fetch_order_by_id(int $id): ?array
{
    return db_find(
        'SELECT o.*, t.gcash_reference, t.transaction_status,
                u.full_name AS customer_name, u.phone AS customer_phone, u.address AS customer_address,
                rider.full_name AS staff_name, preparer.full_name AS prepared_by_name,
                COALESCE(items.item_count, 0) AS item_count
         FROM orders o
         LEFT JOIN transactions t ON t.order_id = o.id
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN users rider ON rider.id = o.assigned_staff_id
         LEFT JOIN users preparer ON preparer.id = o.prepared_by_staff_id
         LEFT JOIN (SELECT order_id, SUM(quantity) AS item_count FROM order_items GROUP BY order_id) items ON items.order_id = o.id
         WHERE o.id = ? LIMIT 1',
        'i',
        [$id]
    );
}

function fetch_order_items(int $orderId): array
{
    return db_select(
        'SELECT oi.*, p.name AS product_name
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?',
        'i',
        [$orderId]
    );
}

function fetch_transactions(string $role, int $userId): array
{
    return fetch_transactions_filtered($role, $userId);
}

function fetch_transactions_filtered(string $role, int $userId, array $filters = []): array
{
    ensure_operational_feature_columns();
    ensure_record_management_tables();

    $priorityContext = ['role' => $role, 'user_id' => $userId];

    $sql = 'SELECT t.*, o.reference_no, o.assigned_staff_id, o.payment_status, u.full_name AS customer_name,
                   reviewer.full_name AS verifier_name, reviewer.role AS verifier_role, COALESCE(rs.record_status, "active") AS record_status'
                   . priority_select_sql('transaction', [], $priorityContext) . '
            FROM transactions t
            LEFT JOIN orders o ON o.id = t.order_id
            LEFT JOIN users u ON u.id = o.customer_id
            LEFT JOIN users reviewer ON reviewer.id = t.recorded_by
            LEFT JOIN record_states rs ON rs.record_type = "transaction" AND rs.record_id = t.id
            WHERE 1=1';
    $types = '';
    $params = [];
    $search = trim((string) ($filters['search'] ?? ''));

    if ($role === 'customer') {
        $sql .= ' AND o.customer_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    if ($search !== '') {
        $sql .= ' AND (o.reference_no LIKE ? OR u.full_name LIKE ? OR t.payment_method LIKE ? OR t.transaction_status LIKE ? OR t.gcash_reference LIKE ?)';
        $types .= 'sssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if (!empty($filters['transaction_status'])) {
        $sql .= ' AND t.transaction_status = ?';
        $types .= 's';
        $params[] = $filters['transaction_status'];
    }

    $sql .= ' ORDER BY ' . priority_order_sql('transaction', 'COALESCE(t.updated_at, t.created_at)', [], $priorityContext);

    return db_select($sql, $types, $params);
}

function fetch_transaction_by_id(int $id): ?array
{
    ensure_operational_feature_columns();

    return db_find(
        'SELECT t.*, o.reference_no, o.payment_status, o.order_status, o.delivery_status, o.assigned_staff_id, o.total_amount, o.created_at AS order_created_at,
                u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                reviewer.full_name AS verifier_name, reviewer.role AS verifier_role
         FROM transactions t
         LEFT JOIN orders o ON o.id = t.order_id
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN users reviewer ON reviewer.id = t.recorded_by
         WHERE t.id = ? LIMIT 1',
        'i',
        [$id]
    );
}

function fetch_transaction_by_order(int $orderId): ?array
{
    ensure_operational_feature_columns();

    return db_find('SELECT * FROM transactions WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
}

function fetch_deliveries(string $role, int $userId): array
{
    ensure_operational_feature_columns();
    ensure_record_management_tables();

    $priorityContext = ['role' => $role, 'user_id' => $userId];

    $sql = 'SELECT d.*, o.reference_no, o.order_status, o.assigned_staff_id AS order_assigned_staff_id,
                   o.payment_method, o.payment_status, o.delivery_status AS order_delivery_status,
                   t.gcash_reference, t.transaction_status,
                   u.full_name AS customer_name, u.phone AS customer_phone,
                   staff.full_name AS assigned_name, staff.phone AS assigned_phone,
                   COALESCE(rs.record_status, "active") AS record_status'
                   . priority_select_sql('delivery', [], $priorityContext) . '
            FROM deliveries d
            LEFT JOIN orders o ON o.id = d.order_id
            LEFT JOIN transactions t ON t.order_id = o.id
            LEFT JOIN users u ON u.id = o.customer_id
            LEFT JOIN users staff ON staff.id = COALESCE(d.assigned_staff_id, o.assigned_staff_id)
            LEFT JOIN record_states rs ON rs.record_type = "delivery" AND rs.record_id = d.id
            WHERE 1=1';
    $types = '';
    $params = [];

    if ($role === 'customer') {
        $sql .= ' AND o.customer_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } elseif ($role === 'rider') {
        $sql .= ' AND d.assigned_staff_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    $sql .= ' ORDER BY ' . priority_order_sql('delivery', 'GREATEST(COALESCE(d.updated_at, d.created_at), COALESCE(o.updated_at, d.created_at))', [], $priorityContext);

    return db_select($sql, $types, $params);
}

