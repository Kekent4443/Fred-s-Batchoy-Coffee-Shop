<?php

declare(strict_types=1);

function priority_case_sql(string $type, array $aliases = [], array $context = []): string
{
    $alias = static fn (string $key, string $default): string => $aliases[$key] ?? $default;

    $role = strtolower((string) ($context['role'] ?? ''));
    $role = in_array($role, ['administrator', 'manager', 'staff', 'rider', 'customer'], true) ? $role : '';
    $userId = max(0, (int) ($context['user_id'] ?? 0));
    $canAct = (bool) ($context['can_act'] ?? false);
    $order = $alias('order', 'o');
    $delivery = $alias('delivery', 'd');
    $transaction = $alias('transaction', 't');
    $recordState = $alias('record_state', 'rs');
    $paymentReady = '((' . $order . '.payment_method <> "gcash" AND ' . $order . '.payment_status <> "cancelled")
        OR (' . $order . '.payment_method = "gcash"
            AND ' . $order . '.payment_status IN ("accepted", "paid")
            AND COALESCE(' . $transaction . '.transaction_status, "") IN ("accepted", "completed")))';
    $terminalOrder = '(' . $order . '.order_status IN ("completed", "cancelled")
        OR ' . $order . '.delivery_status IN ("delivered", "cancelled"))';
    $activeOrder = '(' . $order . '.order_status IN ("pending", "confirmed", "processing", "assigned", "out_for_delivery")
        OR ' . $order . '.delivery_status IN ("pending", "assigned", "out_for_delivery", "issue_reported", "failed_delivery"))';
    $orderAction = '0=1';
    $deliveryAction = '0=1';

    if ($role === 'staff') {
        $staffScope = '(' . $order . '.prepared_by_staff_id IS NULL OR ' . $order . '.prepared_by_staff_id = ' . $userId . ')';
        $orderAction = $staffScope . '
            AND ' . $paymentReady . '
            AND ' . $order . '.order_status IN ("pending", "confirmed", "processing")
            AND COALESCE(' . $delivery . '.delivery_status, ' . $order . '.delivery_status, "pending") NOT IN ("assigned", "out_for_delivery", "delivered", "cancelled", "issue_reported", "failed_delivery")';
    } elseif (in_array($role, ['administrator', 'manager'], true)) {
        $orderAction = '(' . $order . '.delivery_status IN ("issue_reported", "failed_delivery")
            OR COALESCE(' . $delivery . '.delivery_status, "") IN ("issue_reported", "failed_delivery")
            OR (' . $paymentReady . '
                AND (' . $order . '.order_status = "assigned" OR ' . $order . '.delivery_status = "assigned" OR ' . $delivery . '.delivery_status = "assigned"))
            OR (' . $order . '.payment_method = "gcash"
                AND ' . $order . '.payment_status = "pending"
                AND COALESCE(' . $transaction . '.transaction_status, "") = "pending"
                AND COALESCE(' . $transaction . '.proof_of_payment_path, "") <> ""))';
        $deliveryAction = '(' . $delivery . '.delivery_status IN ("issue_reported", "failed_delivery")
            OR (' . $delivery . '.delivery_status = "assigned" AND ' . $paymentReady . '))';
    } elseif ($role === 'rider') {
        $riderScope = $userId > 0 ? $delivery . '.assigned_staff_id = ' . $userId : '0=1';
        $deliveryAction = '(' . $riderScope . ' AND ' . $delivery . '.delivery_status IN ("assigned", "out_for_delivery") AND ' . $paymentReady . ')';
    }

    return match ($type) {
        'notification' => 'CASE
            WHEN ' . $alias('notification', 'n') . '.status = "unread" THEN 2
            WHEN ' . $alias('notification', 'n') . '.status = "read" THEN 4
            ELSE 4
        END',
        'user' => 'CASE
            WHEN users.deleted_at IS NOT NULL THEN 5
            WHEN users.status = "pending_verification" THEN 1
            WHEN users.status = "active" THEN 4
            WHEN users.status = "rejected" THEN 5
            ELSE 4
        END',
        'customer_verification' => 'CASE
            WHEN users.status = "pending_verification" THEN ' . ($canAct ? '1' : '2') . '
            WHEN users.status IN ("active", "rejected") THEN 5
            ELSE 4
        END',
        'category' => 'CASE
            WHEN categories.status = "inactive" THEN 5
            ELSE 4
        END',
        'product' => 'CASE
            WHEN p.status <> "active" OR p.is_available = 0 THEN 5
            WHEN p.stock_quantity <= 0 THEN 1
            WHEN p.stock_quantity <= 10 THEN 1
            ELSE 4
        END',
        'order' => 'CASE
            WHEN COALESCE(' . $recordState . '.record_status, "active") <> "active" THEN 5
            WHEN ' . $orderAction . ' THEN 1
            WHEN ' . $terminalOrder . ' THEN 5
            WHEN ' . $activeOrder . ' THEN 3
            ELSE 4
        END',
        'transaction' => 'CASE
            WHEN COALESCE(' . $recordState . '.record_status, "active") <> "active" THEN 5
            WHEN ' . (in_array($role, ['administrator', 'manager', 'staff'], true)
                ? $transaction . '.payment_method = "gcash" AND ' . $transaction . '.transaction_status = "pending" AND COALESCE(' . $transaction . '.proof_of_payment_path, "") <> ""'
                : '0=1') . ' THEN 1
            WHEN ' . $transaction . '.transaction_status = "pending" THEN 3
            WHEN ' . $transaction . '.transaction_status IN ("completed", "failed", "cancelled") THEN 5
            WHEN ' . $transaction . '.transaction_status = "accepted" THEN 3
            ELSE 4
        END',
        'delivery' => 'CASE
            WHEN COALESCE(' . $recordState . '.record_status, "active") <> "active" THEN 5
            WHEN ' . $deliveryAction . ' THEN 1
            WHEN ' . $delivery . '.delivery_status IN ("delivered", "cancelled") THEN 5
            WHEN ' . $delivery . '.delivery_status IN ("pending", "assigned", "out_for_delivery", "issue_reported", "failed_delivery") THEN 3
            ELSE 4
        END',
        default => 'CASE WHEN 1=1 THEN 4 END',
    };
}

function priority_select_sql(string $type, array $aliases = [], array $context = []): string
{
    $case = priority_case_sql($type, $aliases, $context);

    return ', ' . $case . ' AS priority_rank,
            CASE
                WHEN ' . $case . ' = 1 THEN "high"
                WHEN ' . $case . ' IN (2, 3) THEN "medium"
                ELSE "low"
            END AS priority_level';
}

function priority_order_sql(string $type, string $updatedExpression, array $aliases = [], array $context = []): string
{
    return priority_case_sql($type, $aliases, $context) . ' ASC, ' . $updatedExpression . ' DESC';
}

