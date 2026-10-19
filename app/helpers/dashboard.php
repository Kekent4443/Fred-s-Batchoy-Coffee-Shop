<?php

declare(strict_types=1);

/** Derive identity from the authenticated session, never from query parameters. */
function dashboard_viewer(array $roles): array
{
    $viewer = current_user();
    if (!$viewer || !in_array($viewer['role'] ?? '', $roles, true)
        || ($viewer['status'] ?? '') !== 'active' || !empty($viewer['deleted_at'])) {
        throw new RuntimeException('This dashboard is not available to your account.', 403);
    }
    return $viewer;
}

function dashboard_scalar(array $source, string $name, string $default = ''): string
{
    return isset($source[$name]) && is_scalar($source[$name]) ? trim((string) $source[$name]) : $default;
}

function dashboard_period_filters(array $source, ?DateTimeImmutable $today = null): array
{
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $legacyRange = !empty($source['date_from']) || !empty($source['date_to']);
    $period = dashboard_scalar($source, 'period', $legacyRange ? 'custom' : 'this_month');
    $options = ['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'custom' => 'Custom Range'];
    if (!isset($options[$period])) {
        $period = 'this_month';
    }
    $values = ['date_from' => dashboard_scalar($source, 'date_from'), 'date_to' => dashboard_scalar($source, 'date_to')];
    $errors = [];
    if ($period === 'custom') {
        foreach ($values as $field => $raw) {
            if (!is_scalar($source[$field] ?? '') || parse_display_date($raw) === null) {
                $errors[$field] = 'Enter a valid ' . ($field === 'date_from' ? 'start' : 'end') . ' date in MM/DD/YY format.';
            }
        }
        $from = parse_display_date($values['date_from']);
        $to = parse_display_date($values['date_to']);
        if ($from !== null && $to !== null && $from > $to) {
            $errors['date_to'] = 'End date must be the same as or later than the start date.';
        }
        // Invalid filters never cause a silently unbounded query.
        $start = new DateTimeImmutable($from ?? $today->format('Y-m-d'));
        $end = new DateTimeImmutable($to ?? $today->format('Y-m-d'));
    } else {
        $start = match ($period) {
            'today' => $today, 'this_week' => $today->modify('monday this week'),
            default => $today->modify('first day of this month'),
        };
        $end = match ($period) {
            'today' => $today, 'this_week' => $today->modify('sunday this week'),
            default => $today->modify('last day of this month'),
        };
    }
    $filters = ['date_from' => $start->format('Y-m-d'), 'date_to' => $end->format('Y-m-d')];
    // Preserve the Manager's existing status-filter links and functionality.
    foreach (['order_status' => ['pending', 'processing', 'confirmed', 'assigned', 'out_for_delivery', 'completed', 'cancelled'],
              'transaction_status' => ['pending', 'accepted', 'completed', 'failed', 'cancelled']] as $key => $allowed) {
        $value = dashboard_scalar($source, $key);
        if (in_array($value, $allowed, true)) {
            $filters[$key] = $value;
        }
    }
    $days = (int) $start->diff($end)->days + 1;
    $interval = $days === 1 ? 'hour' : ($days <= 62 ? 'day' : ($days <= 1096 ? 'month' : 'year'));
    return [
        'period' => $period, 'options' => $options, 'filters' => $filters, 'errors' => $errors, 'values' => $values,
        'interval' => $interval, 'label' => $options[$period],
        'range_label' => format_display_date($filters['date_from']) . ' – ' . format_display_date($filters['date_to']),
    ];
}

/** The same WHERE and parameters feed counts, chart, best sellers and recent orders. */
function dashboard_business_where(array $filters): array
{
    [$conditions, $types, $params] = dashboard_filter_conditions($filters);
    if (!empty($filters['transaction_status'])) {
        $conditions[] = 'EXISTS (SELECT 1 FROM transactions tf WHERE tf.order_id = o.id AND tf.transaction_status = ?)';
        $types .= 's';
        $params[] = $filters['transaction_status'];
    }
    return [implode(' AND ', $conditions) ?: '1=1', $types, $params];
}

function dashboard_stock_data(): array
{
    dashboard_viewer(['administrator', 'manager', 'staff']);
    $threshold = inventory_low_stock_threshold();
    $count = db_find('SELECT COUNT(*) AS total FROM products WHERE stock_quantity <= ? AND status = "active"', 'i', [$threshold]);
    $rows = db_select(
        'SELECT p.id, p.name, p.stock_quantity, c.name AS category_name
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.stock_quantity <= ? AND p.status = "active"
         ORDER BY p.stock_quantity ASC, p.name ASC LIMIT 5', 'i', [$threshold]
    );
    return ['total' => (int) ($count['total'] ?? 0), 'rows' => $rows, 'threshold' => $threshold];
}

/** Fill missing buckets explicitly, so a quiet day is shown as zero sales. */
function dashboard_sales_series(array $rows, array $period): array
{
    $lookup = array_column($rows, 'total', 'bucket');
    $interval = $period['interval'];
    $start = new DateTimeImmutable($period['filters']['date_from']);
    $end = new DateTimeImmutable($period['filters']['date_to'] . ' 23:59:59');
    if ($interval === 'month') $start = $start->modify('first day of this month');
    if ($interval === 'year') $start = $start->setDate((int) $start->format('Y'), 1, 1);
    $format = match ($interval) { 'hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m', default => 'Y' };
    $series = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 ' . $interval)) {
        $key = $cursor->format($format);
        $series[] = [
            'label' => match ($interval) { 'hour' => $cursor->format('g A'), 'month' => $cursor->format('m/Y'), 'year' => $cursor->format('Y'), default => $cursor->format('m/d/y') },
            'total' => (float) ($lookup[$key] ?? 0),
        ];
    }
    return $series;
}

function fetch_business_dashboard_data(array $period): array
{
    dashboard_viewer(['administrator', 'manager']);
    if (!array_key_exists('errors', $period) || !isset($period['filters'], $period['interval'])) {
        throw new InvalidArgumentException('A normalized dashboard period is required.');
    }
    if ($period['errors'] !== []) throw new InvalidArgumentException('Correct the dashboard date range.');
    [$where, $types, $params] = dashboard_business_where($period['filters']);
    $sales = valid_sales_condition();
    // One aggregate query supplies the KPI totals and status breakdown.
    $statuses = db_select(
        'SELECT o.order_status, COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN ' . $sales . ' THEN o.total_amount ELSE 0 END), 0) AS sales_total
         FROM orders o WHERE ' . $where . ' GROUP BY o.order_status ORDER BY o.order_status', $types, $params
    );
    $format = match ($period['interval']) { 'hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'month' => '%Y-%m', default => '%Y' };
    $trend = db_select(
        'SELECT DATE_FORMAT(o.created_at, "' . $format . '") AS bucket, SUM(o.total_amount) AS total
         FROM orders o WHERE ' . $where . ' AND ' . $sales . ' GROUP BY bucket ORDER BY bucket', $types, $params
    );
    return [
        'stats' => ['orders_total' => array_sum(array_column($statuses, 'total')), 'sales_total' => array_sum(array_column($statuses, 'sales_total'))],
        'customers_total' => (int) (db_find('SELECT COUNT(*) AS total FROM users WHERE role = "customer"')['total'] ?? 0),
        'stock' => dashboard_stock_data(), 'order_statuses' => $statuses,
        'sales_trend' => dashboard_sales_series($trend, $period),
        'best_sellers' => db_select(
            'SELECT p.id, p.name, SUM(oi.quantity) AS quantity_sold
             FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id
             WHERE ' . $where . ' AND ' . $sales . '
             GROUP BY p.id, p.name ORDER BY quantity_sold DESC, p.name ASC LIMIT 5', $types, $params
        ),
        'recent_orders' => db_select(
            'SELECT o.id, o.reference_no, o.total_amount, o.order_status, o.delivery_status, o.created_at, u.full_name AS customer_name
             FROM orders o LEFT JOIN users u ON u.id = o.customer_id
             WHERE ' . $where . ' ORDER BY o.created_at DESC, o.id DESC LIMIT 6', $types, $params
        ),
    ];
}

function dashboard_order_scope(): array
{
    $viewer = dashboard_viewer(['staff', 'customer']);
    return $viewer['role'] === 'customer'
        ? ['o.customer_id = ?', 'i', [(int) $viewer['id']]]
        : ['(o.prepared_by_staff_id IS NULL OR o.prepared_by_staff_id = ?)', 'i', [(int) $viewer['id']]];
}

function dashboard_order_summary(): array
{
    [$scope, $types, $params] = dashboard_order_scope();
    $today = new DateTimeImmutable('today');
    $rows = db_select(
        'SELECT o.order_status, COUNT(*) AS total,
                SUM(o.delivery_status = "delivered") AS delivered,
                SUM(o.created_at >= ? AND o.created_at < ?) AS today
         FROM orders o WHERE ' . $scope . ' GROUP BY o.order_status ORDER BY o.order_status',
        'ss' . $types, array_merge([$today->format('Y-m-d H:i:s'), $today->modify('+1 day')->format('Y-m-d H:i:s')], $params)
    );
    $counts = array_column($rows, 'total', 'order_status');
    return [
        'statuses' => $rows, 'total' => (int) array_sum(array_column($rows, 'total')),
        'today' => (int) array_sum(array_column($rows, 'today')),
        'pending' => (int) ($counts['pending'] ?? 0),
        'processing' => (int) ($counts['processing'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0),
        'delivered' => (int) array_sum(array_column($rows, 'delivered')),
    ];
}

function dashboard_scoped_orders(bool $priority = false): array
{
    $viewer = dashboard_viewer(['staff', 'customer']);
    [$scope, $types, $params] = dashboard_order_scope();
    $priorityContext = ['role' => (string) $viewer['role'], 'user_id' => (int) $viewer['id']];
    $prioritySelect = $priority ? priority_select_sql('order', [], $priorityContext) : '';
    $joins = $priority ? ' LEFT JOIN deliveries d ON d.order_id = o.id
                          LEFT JOIN transactions t ON t.order_id = o.id
                          LEFT JOIN record_states rs ON rs.record_type = "order" AND rs.record_id = o.id' : '';
    $priorityWhere = $priority ? ' AND (' . priority_case_sql('order', [], $priorityContext) . ') = 1' : '';
    return db_select(
        'SELECT o.id, o.reference_no, o.total_amount, o.order_status, o.delivery_status, o.created_at, u.full_name AS customer_name'
        . $prioritySelect . ' FROM orders o LEFT JOIN users u ON u.id = o.customer_id' . $joins . '
        WHERE ' . $scope . $priorityWhere . ' ORDER BY o.created_at DESC, o.id DESC LIMIT 6', $types, $params
    );
}

function fetch_staff_dashboard_data(): array
{
    $viewer = dashboard_viewer(['staff']);
    [$scope, $types, $params] = dashboard_order_scope();
    return [
        'summary' => dashboard_order_summary(), 'recent_orders' => dashboard_scoped_orders(),
        'priority_orders' => dashboard_scoped_orders(true), 'stock' => dashboard_stock_data(),
        'transactions' => db_select(
            'SELECT t.amount, t.transaction_status, t.created_at, o.reference_no
             FROM transactions t INNER JOIN orders o ON o.id = t.order_id WHERE ' . $scope . '
             ORDER BY t.created_at DESC, t.id DESC LIMIT 5', $types, $params
        ),
        'notifications' => fetch_notifications((int) $viewer['id'], 5),
    ];
}

function fetch_rider_dashboard_data(): array
{
    $viewer = dashboard_viewer(['rider']);
    $id = (int) $viewer['id'];
    $today = new DateTimeImmutable('today');
    $start = $today->format('Y-m-d H:i:s');
    $end = $today->modify('+1 day')->format('Y-m-d H:i:s');
    $summary = db_select(
        'SELECT d.delivery_status, COUNT(*) AS total,
                SUM(d.delivery_status = "delivered" AND d.delivered_at >= ? AND d.delivered_at < ?) AS completed_today
         FROM deliveries d WHERE d.assigned_staff_id = ? GROUP BY d.delivery_status', 'ssi', [$start, $end, $id]
    );
    $priorityContext = ['role' => 'rider', 'user_id' => $id];
    $priorityOrder = priority_order_sql('delivery', 'GREATEST(COALESCE(d.updated_at, d.created_at), COALESCE(o.updated_at, d.created_at))', [], $priorityContext);
    $select = 'SELECT d.id, d.order_id, d.delivery_status, d.delivery_address, d.delivery_landmark, d.estimated_delivery_at,
                      d.delivered_at, o.reference_no, o.order_status, o.payment_method, o.payment_status,
                      t.transaction_status, u.full_name AS customer_name, COALESCE(rs.record_status, "active") AS record_status'
                      . priority_select_sql('delivery', [], $priorityContext) . '
               FROM deliveries d INNER JOIN orders o ON o.id = d.order_id
               LEFT JOIN transactions t ON t.order_id = o.id
               LEFT JOIN users u ON u.id = o.customer_id
               LEFT JOIN record_states rs ON rs.record_type = "delivery" AND rs.record_id = d.id
               WHERE d.assigned_staff_id = ?';
    return [
        'statuses' => $summary, 'counts' => array_column($summary, 'total', 'delivery_status'),
        'assigned_total' => (int) array_sum(array_column($summary, 'total')),
        'completed_today' => (int) array_sum(array_column($summary, 'completed_today')),
        'today' => db_select($select . ' AND (
            (d.estimated_delivery_at >= ? AND d.estimated_delivery_at < ?)
            OR (d.delivered_at >= ? AND d.delivered_at < ?)
            OR (d.estimated_delivery_at IS NULL AND d.created_at >= ? AND d.created_at < ?))
            ORDER BY ' . $priorityOrder . ' LIMIT 6', 'issssss', [$id, $start, $end, $start, $end, $start, $end]),
        'assigned' => db_select($select . ' AND d.delivery_status IN ("pending", "assigned", "out_for_delivery", "issue_reported", "failed_delivery")
            ORDER BY ' . $priorityOrder . ' LIMIT 6', 'i', [$id]),
        'activity' => db_select(
            'SELECT h.new_status, h.created_at, o.reference_no FROM delivery_history h
             INNER JOIN deliveries d ON d.id = h.delivery_id INNER JOIN orders o ON o.id = d.order_id
             WHERE d.assigned_staff_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 6', 'i', [$id]
        ),
    ];
}

function fetch_customer_dashboard_data(): array
{
    $viewer = dashboard_viewer(['customer']);
    $now = date('Y-m-d H:i:s');
    return [
        'summary' => dashboard_order_summary(), 'recent_orders' => dashboard_scoped_orders(),
        'notifications' => fetch_notifications((int) $viewer['id'], 5),
        // Same active-window and remaining-use rules as promo_code_preview().
        'promos' => db_select(
            'SELECT code, description, discount_type, discount_value, minimum_subtotal, maximum_discount, ends_at
             FROM promo_codes WHERE status = "active" AND (starts_at IS NULL OR starts_at <= ?)
             AND (ends_at IS NULL OR ends_at >= ?) AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit)
             ORDER BY COALESCE(ends_at, "9999-12-31"), code LIMIT 3', 'ss', [$now, $now]
        ),
        'purchases' => db_select(
            'SELECT p.name, oi.quantity, o.reference_no, o.id AS order_id, o.created_at
             FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id INNER JOIN products p ON p.id = oi.product_id
             WHERE o.customer_id = ? AND ' . valid_sales_condition() . '
             ORDER BY o.created_at DESC, o.id DESC, oi.id DESC LIMIT 5', 'i', [(int) $viewer['id']]
        ),
    ];
}
