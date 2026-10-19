<?php

declare(strict_types=1);

function dashboard_filter_conditions(array $filters, string $orderAlias = 'o', ?string $transactionAlias = null): array
{
    $conditions = [];
    $types = '';
    $params = [];

    if (!empty($filters['date_from'])) {
        $conditions[] = $orderAlias . '.created_at >= ?';
        $types .= 's';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $conditions[] = $orderAlias . '.created_at <= ?';
        $types .= 's';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    if (!empty($filters['order_status'])) {
        $conditions[] = $orderAlias . '.order_status = ?';
        $types .= 's';
        $params[] = $filters['order_status'];
    }

    if ($transactionAlias !== null && !empty($filters['transaction_status'])) {
        $conditions[] = $transactionAlias . '.transaction_status = ?';
        $types .= 's';
        $params[] = $filters['transaction_status'];
    }

    return [$conditions, $types, $params];
}


function dashboard_transaction_filter_join(array $filters): string
{
    return !empty($filters['transaction_status'])
        ? ' INNER JOIN transactions t_filter ON t_filter.order_id = o.id'
        : '';
}

/** Paid is the settled-payment state used by GCash review and COD collection. */
function valid_sales_condition(): string
{
    return 'o.payment_status = "paid" AND o.order_status <> "cancelled"
        AND NOT EXISTS (SELECT 1 FROM transactions invalid_payment
                        WHERE invalid_payment.order_id = o.id
                          AND invalid_payment.transaction_status IN ("failed", "cancelled"))';
}

function fetch_dashboard_stats(string $role, int $userId, array $filters = []): array
{
    $stats = [
        'sales_total' => 0,
        'orders_total' => 0,
        'transactions_total' => 0,
        'deliveries_total' => 0,
        'pending_orders' => 0,
    ];

    if (in_array($role, ['administrator', 'manager'], true)) {
        $transactionJoin = dashboard_transaction_filter_join($filters);
        [$orderConditions, $orderTypes, $orderParams] = dashboard_filter_conditions($filters, 'o', $transactionJoin ? 't_filter' : null);
        $orderWhere = $orderConditions ? ' AND ' . implode(' AND ', $orderConditions) : '';

        $stats['sales_total'] = (float) (db_find(
            'SELECT COALESCE(SUM(o.total_amount), 0) AS total FROM orders o' . $transactionJoin . ' WHERE ' . valid_sales_condition() . $orderWhere,
            $orderTypes,
            $orderParams
        )['total'] ?? 0);
        $stats['orders_total'] = (int) (db_find(
            'SELECT COUNT(DISTINCT o.id) AS total FROM orders o' . $transactionJoin . ' WHERE 1=1' . $orderWhere,
            $orderTypes,
            $orderParams
        )['total'] ?? 0);
        $stats['deliveries_total'] = (int) (db_find(
            'SELECT COUNT(DISTINCT d.id) AS total FROM deliveries d INNER JOIN orders o ON o.id = d.order_id' . $transactionJoin . ' WHERE 1=1' . $orderWhere,
            $orderTypes,
            $orderParams
        )['total'] ?? 0);
        $stats['pending_orders'] = (int) (db_find(
            'SELECT COUNT(DISTINCT o.id) AS total FROM orders o' . $transactionJoin . ' WHERE o.order_status IN ("pending", "processing", "confirmed")' . $orderWhere,
            $orderTypes,
            $orderParams
        )['total'] ?? 0);

        [$transactionConditions, $transactionTypes, $transactionParams] = dashboard_filter_conditions($filters, 'o', 't');
        $transactionWhere = $transactionConditions ? ' AND ' . implode(' AND ', $transactionConditions) : '';
        $stats['transactions_total'] = (int) (db_find(
            'SELECT COUNT(DISTINCT t.id) AS total FROM transactions t INNER JOIN orders o ON o.id = t.order_id WHERE 1=1' . $transactionWhere,
            $transactionTypes,
            $transactionParams
        )['total'] ?? 0);

        return $stats;
    }

    if ($role === 'staff') {
        $stats['orders_total'] = (int) (db_find('SELECT COUNT(*) AS total FROM orders WHERE prepared_by_staff_id = ?', 'i', [$userId])['total'] ?? 0);
        $stats['pending_orders'] = (int) (db_find('SELECT COUNT(*) AS total FROM orders WHERE prepared_by_staff_id = ? AND order_status IN ("confirmed", "processing")', 'i', [$userId])['total'] ?? 0);
        $stats['transactions_total'] = (int) (db_find('SELECT COUNT(*) AS total FROM transactions WHERE recorded_by = ?', 'i', [$userId])['total'] ?? 0);
        $stats['deliveries_total'] = (int) (db_find('SELECT COUNT(*) AS total FROM orders WHERE prepared_by_staff_id = ? AND delivery_status IN ("assigned", "out_for_delivery", "delivered")', 'i', [$userId])['total'] ?? 0);
        return $stats;
    }

    $stats['sales_total'] = (float) (db_find(
        'SELECT COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE customer_id = ? AND payment_status = "paid"',
        'i',
        [$userId]
    )['total'] ?? 0);
    $stats['orders_total'] = (int) (db_find('SELECT COUNT(*) AS total FROM orders WHERE customer_id = ?', 'i', [$userId])['total'] ?? 0);
    $stats['transactions_total'] = (int) (db_find(
        'SELECT COUNT(*) AS total FROM transactions t INNER JOIN orders o ON o.id = t.order_id WHERE o.customer_id = ?',
        'i',
        [$userId]
    )['total'] ?? 0);
    $stats['deliveries_total'] = (int) (db_find(
        'SELECT COUNT(*) AS total FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE o.customer_id = ?',
        'i',
        [$userId]
    )['total'] ?? 0);
    $stats['pending_orders'] = (int) (db_find(
        'SELECT COUNT(*) AS total FROM orders WHERE customer_id = ? AND order_status IN ("pending", "processing", "assigned", "out_for_delivery")',
        'i',
        [$userId]
    )['total'] ?? 0);

    return $stats;
}

function fetch_order_status_summary(array $filters = []): array
{
    $transactionJoin = dashboard_transaction_filter_join($filters);
    [$conditions, $types, $params] = dashboard_filter_conditions($filters, 'o', $transactionJoin ? 't_filter' : null);
    $where = $conditions ? ' AND ' . implode(' AND ', $conditions) : '';

    return db_select(
        'SELECT o.order_status, COUNT(DISTINCT o.id) AS total
         FROM orders o' . $transactionJoin . '
         WHERE 1=1' . $where . '
         GROUP BY o.order_status
         ORDER BY o.order_status ASC',
        $types,
        $params
    );
}

function fetch_transaction_status_summary(array $filters = []): array
{
    [$conditions, $types, $params] = dashboard_filter_conditions($filters, 'o', 't');
    $where = $conditions ? ' AND ' . implode(' AND ', $conditions) : '';

    return db_select(
        'SELECT t.transaction_status, COUNT(DISTINCT t.id) AS total
         FROM transactions t
         INNER JOIN orders o ON o.id = t.order_id
         WHERE 1=1' . $where . '
         GROUP BY t.transaction_status
         ORDER BY t.transaction_status ASC',
        $types,
        $params
    );
}


function report_type_options(bool $includeUserActivity = true): array
{
    $types = [
        'sales' => 'Sales Report',
        'orders' => 'Order Report',
        'transactions' => 'Transaction Report',
        'products' => 'Product Report',
        'deliveries' => 'Delivery Report',
    ];

    if ($includeUserActivity) {
        $types['user_activity'] = 'User Activity Report';
    }

    return $types;
}

function report_status_options(string $type): array
{
    return match ($type) {
        'sales', 'orders' => [
            'pending' => 'Pending', 'processing' => 'Processing', 'confirmed' => 'Confirmed',
            'assigned' => 'Assigned', 'out_for_delivery' => 'Out for Delivery',
            'completed' => 'Completed', 'cancelled' => 'Cancelled',
        ],
        'transactions' => [
            'pending' => 'Pending', 'accepted' => 'Accepted', 'completed' => 'Completed',
            'failed' => 'Failed', 'cancelled' => 'Cancelled',
        ],
        'products' => ['active' => 'Active', 'inactive' => 'Inactive'],
        'deliveries' => [
            'pending' => 'Pending', 'assigned' => 'Assigned',
            'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered',
            'issue_reported' => 'Issue Reported', 'failed_delivery' => 'Failed Delivery',
            'cancelled' => 'Cancelled',
        ],
        default => [],
    };
}

function normalize_report_filters(array $source): array
{
    $scalar = static function (string $field) use ($source): string {
        $value = $source[$field] ?? '';
        return is_scalar($value) || $value === null ? trim((string) $value) : '';
    };
    $dateFrom = $scalar('date_from');
    $dateTo = $scalar('date_to');

    return [
        'date_from' => parse_display_date($dateFrom) ?? '',
        'date_to' => parse_display_date($dateTo) ?? '',
        'status' => $scalar('status'),
        'category_id' => (int) $scalar('category_id'),
        'search' => $scalar('search'),
    ];
}

function append_report_filters(string $sql, string $dateColumn, string $statusColumn, array $filters, string &$types, array &$params): string
{
    if (($filters['date_from'] ?? '') !== '') {
        $sql .= ' AND ' . $dateColumn . ' >= ?';
        $types .= 's';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $sql .= ' AND ' . $dateColumn . ' <= ?';
        $types .= 's';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['status'] ?? '') !== '' && $statusColumn !== '') {
        $sql .= ' AND ' . $statusColumn . ' = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }

    return $sql;
}

function build_report_data(string $type, array $filters = []): array
{
    $filters = normalize_report_filters($filters);
    $types = '';
    $params = [];
    $options = report_type_options(true);
    $type = array_key_exists($type, $options) ? $type : 'sales';

    if ($type === 'sales') {
        $sql = 'SELECT p.name AS product, c.name AS category,
                       COALESCE(SUM(CASE WHEN o.payment_status = "paid" THEN oi.quantity ELSE 0 END), 0) AS qty_sold,
                       COALESCE(SUM(CASE WHEN o.payment_status = "paid" THEN oi.subtotal ELSE 0 END), 0) AS revenue
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN order_items oi ON oi.product_id = p.id
                LEFT JOIN orders o ON o.id = oi.order_id
                WHERE 1=1';
        $sql = append_report_filters($sql, 'o.created_at', 'o.order_status', $filters, $types, $params);

        if (($filters['category_id'] ?? 0) > 0) {
            $sql .= ' AND p.category_id = ?';
            $types .= 'i';
            $params[] = $filters['category_id'];
        }

        $sql .= ' GROUP BY p.id ORDER BY revenue DESC, qty_sold DESC';
        $rows = array_map(static fn ($row) => [$row['product'], $row['category'], $row['qty_sold'], currency((float) $row['revenue'])], db_select($sql, $types, $params));

        return ['title' => 'Sales Report', 'headers' => ['Product', 'Category', 'Qty Sold', 'Revenue'], 'rows' => $rows];
    }

    if ($type === 'orders') {
        $sql = 'SELECT o.reference_no, u.full_name AS customer_name, o.order_status, o.payment_status, o.delivery_status, o.total_amount, o.created_at
                FROM orders o
                LEFT JOIN deliveries d ON d.order_id = o.id
                LEFT JOIN transactions t ON t.order_id = o.id
                LEFT JOIN users u ON u.id = o.customer_id
                LEFT JOIN record_states rs ON rs.record_type = "order" AND rs.record_id = o.id
                WHERE 1=1';
        $sql = append_report_filters($sql, 'o.created_at', 'o.order_status', $filters, $types, $params);
        $sql .= ' ORDER BY o.created_at DESC, o.id DESC';
        $rows = array_map(static fn ($row) => [$row['reference_no'], $row['customer_name'], $row['order_status'], $row['payment_status'], $row['delivery_status'], currency((float) $row['total_amount']), format_display_datetime($row['created_at'] ?? null)], db_select($sql, $types, $params));

        return ['title' => 'Order Report', 'headers' => ['Reference', 'Customer', 'Order Status', 'Payment', 'Delivery', 'Total', 'Created At'], 'rows' => $rows];
    }

    if ($type === 'transactions') {
        ensure_operational_feature_columns();
        $sql = 'SELECT o.reference_no, u.full_name AS customer_name, t.payment_method, t.amount, t.transaction_status, t.gcash_reference, t.created_at
                FROM transactions t
                LEFT JOIN orders o ON o.id = t.order_id
                LEFT JOIN users u ON u.id = o.customer_id
                LEFT JOIN record_states rs ON rs.record_type = "transaction" AND rs.record_id = t.id
                WHERE 1=1';
        $sql = append_report_filters($sql, 't.created_at', 't.transaction_status', $filters, $types, $params);
        $sql .= ' ORDER BY t.created_at DESC, t.id DESC';
        $rows = array_map(static fn ($row) => [$row['reference_no'], $row['customer_name'], ucfirst($row['payment_method']), currency((float) $row['amount']), $row['transaction_status'], $row['gcash_reference'], format_display_datetime($row['created_at'] ?? null)], db_select($sql, $types, $params));

        return ['title' => 'Transaction Report', 'headers' => ['Reference', 'Customer', 'Method', 'Amount', 'Status', 'GCash Reference', 'Created At'], 'rows' => $rows];
    }

    if ($type === 'products') {
        $sql = 'SELECT p.name, c.name AS category_name, p.price, p.stock_quantity, p.status, p.created_at
                FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1';
        $sql = append_report_filters($sql, 'p.created_at', 'p.status', $filters, $types, $params);

        if (($filters['category_id'] ?? 0) > 0) {
            $sql .= ' AND p.category_id = ?';
            $types .= 'i';
            $params[] = $filters['category_id'];
        }

        $sql .= ' ORDER BY ' . priority_order_sql('product', 'COALESCE(p.updated_at, p.created_at)') . ', p.name ASC';
        $rows = array_map(static fn ($row) => [$row['name'], $row['category_name'], currency((float) $row['price']), $row['stock_quantity'], $row['status'], format_display_datetime($row['created_at'] ?? null)], db_select($sql, $types, $params));

        return ['title' => 'Product Report', 'headers' => ['Product', 'Category', 'Price', 'Stock', 'Status', 'Created At'], 'rows' => $rows];
    }

    if ($type === 'deliveries') {
        ensure_operational_feature_columns();
        $sql = 'SELECT o.reference_no, u.full_name AS customer_name, staff.full_name AS staff_name, d.delivery_status, d.delivery_address, d.delivered_at, d.created_at
                FROM deliveries d
                LEFT JOIN orders o ON o.id = d.order_id
                LEFT JOIN users u ON u.id = o.customer_id
                LEFT JOIN users staff ON staff.id = d.assigned_staff_id
                LEFT JOIN record_states rs ON rs.record_type = "delivery" AND rs.record_id = d.id
                WHERE 1=1';
        $sql = append_report_filters($sql, 'd.created_at', 'd.delivery_status', $filters, $types, $params);
        $sql .= ' ORDER BY d.created_at DESC, d.id DESC';
        $rows = array_map(static fn ($row) => [$row['reference_no'], $row['customer_name'], $row['staff_name'] ?? 'Unassigned', $row['delivery_status'], $row['delivery_address'], format_display_datetime($row['delivered_at'] ?? null), format_display_datetime($row['created_at'] ?? null)], db_select($sql, $types, $params));

        return ['title' => 'Delivery Report', 'headers' => ['Reference', 'Customer', 'Staff', 'Status', 'Address', 'Delivered At', 'Created At'], 'rows' => $rows];
    }

    $sql = 'SELECT a.created_at, COALESCE(u.full_name, "System") AS user_name, a.action, a.description
            FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1';
    $sql = append_report_filters($sql, 'a.created_at', '', $filters, $types, $params);
    $sql .= ' ORDER BY a.created_at DESC';
    $rows = array_map(static fn ($row) => [format_display_datetime($row['created_at'] ?? null), $row['user_name'], $row['action'], $row['description']], db_select($sql, $types, $params));

    return ['title' => 'User Activity Report', 'headers' => ['Date', 'User', 'Action', 'Description'], 'rows' => $rows];
}

function sales_per_product(): array
{
    return db_select(
        'SELECT p.name,
                COALESCE(SUM(CASE WHEN o.payment_status = "paid" THEN oi.quantity ELSE 0 END), 0) AS qty_sold,
                COALESCE(SUM(CASE WHEN o.payment_status = "paid" THEN oi.subtotal ELSE 0 END), 0) AS revenue
         FROM products p
         LEFT JOIN order_items oi ON oi.product_id = p.id
         LEFT JOIN orders o ON o.id = oi.order_id
         GROUP BY p.id
         ORDER BY revenue DESC, qty_sold DESC'
    );
}

function generate_report_log(string $reportName, string $role, int $userId): void
{
    db_execute(
        'INSERT INTO report_logs (report_name, generated_by, role_scope, created_at) VALUES (?, ?, ?, NOW())',
        'sis',
        [$reportName, $userId, $role]
    );
}

