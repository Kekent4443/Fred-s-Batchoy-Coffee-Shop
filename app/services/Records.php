<?php

declare(strict_types=1);

function record_type_options(): array
{
    return [
        'all' => 'All Records',
        'order' => 'Orders',
        'transaction' => 'Transactions',
        'delivery' => 'Deliveries',
        'customer_verification' => 'Customer Verifications',
        'audit' => 'User Activity',
    ];
}

function record_status_options(): array
{
    return [
        'active' => 'Active Records',
        'archived' => 'Archived Records',
        'deleted' => 'Deleted Records',
    ];
}

function record_snapshot(string $type, int $id): array
{
    return match ($type) {
        'order' => db_find(
            'SELECT id, reference_no, customer_id, prepared_by_staff_id, assigned_staff_id, order_status,
                    payment_method, payment_status, delivery_status, notes, subtotal_amount, discount_rate,
                    discount_amount, promo_code, promo_discount_amount, tax_rate, tax_amount, service_fee,
                    delivery_fee, total_amount, created_at, updated_at
             FROM orders WHERE id = ? LIMIT 1',
            'i',
            [$id]
        ) ?? [],
        'transaction' => db_find(
            'SELECT id, order_id, payment_method, amount, subtotal_amount, discount_rate, discount_amount,
                    promo_code, promo_discount_amount, transaction_status, gcash_reference, recorded_by,
                    reviewed_at, rejection_reason, created_at, updated_at
             FROM transactions WHERE id = ? LIMIT 1',
            'i',
            [$id]
        ) ?? [],
        'delivery' => db_find(
            'SELECT id, order_id, assigned_staff_id, delivery_status, delivery_address, delivery_landmark, notes, issue_notes,
                    delivered_at, estimated_delivery_at, recipient_name, created_at, updated_at
             FROM deliveries WHERE id = ? LIMIT 1',
            'i',
            [$id]
        ) ?? [],
        'customer_verification' => db_find(
            'SELECT id, full_name, email, phone, address, role, status, email_verified_at, verification_status,
                    verification_reviewed_by, verification_reviewed_name, verification_reviewed_role,
                    verification_reviewed_at, verification_notes, created_at, updated_at
             FROM users WHERE id = ? AND role = "customer" LIMIT 1',
            'i',
            [$id]
        ) ?? [],
        'audit' => db_find('SELECT id, user_id, action, description, created_at FROM audit_logs WHERE id = ? LIMIT 1', 'i', [$id]) ?? [],
        default => [],
    };
}

function set_record_state(string $type, int $id, string $state, int $userId): void
{
    ensure_record_management_tables();

    if (!in_array($type, ['order', 'transaction', 'delivery', 'customer_verification', 'audit'], true)) {
        throw new InvalidArgumentException('Unsupported record type.');
    }

    if (!in_array($state, ['active', 'archived', 'deleted'], true)) {
        throw new InvalidArgumentException('Unsupported record status.');
    }

    $snapshot = record_snapshot($type, $id);

    if ($snapshot === []) {
        throw new InvalidArgumentException('Record does not exist.');
    }

    $archivedAt = $state === 'archived' ? date('Y-m-d H:i:s') : null;
    $deletedAt = $state === 'deleted' ? date('Y-m-d H:i:s') : null;
    $recoveredAt = $state === 'active' ? date('Y-m-d H:i:s') : null;
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    db_execute(
        'INSERT INTO record_states (record_type, record_id, record_status, snapshot_data, archived_at, deleted_at, recovered_at, updated_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE record_status = VALUES(record_status), snapshot_data = VALUES(snapshot_data),
             archived_at = VALUES(archived_at), deleted_at = VALUES(deleted_at), recovered_at = VALUES(recovered_at),
             updated_by = VALUES(updated_by), updated_at = NOW()',
        'sisssssi',
        [$type, $id, $state, $snapshotJson, $archivedAt, $deletedAt, $recoveredAt, $userId]
    );

    log_audit($userId, 'record_' . $state, ucfirst($state) . ' ' . $type . ' record #' . $id);
}

function fetch_record_management_rows(array $filters = []): array
{
    ensure_record_management_tables();
    ensure_operational_feature_columns();

    $recordState = (string) ($filters['record_state'] ?? 'active');
    $recordState = in_array($recordState, ['active', 'archived', 'deleted'], true) ? $recordState : 'active';
    $recordType = (string) ($filters['record_type'] ?? 'all');
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $date = trim((string) ($filters['date'] ?? ''));

    $rows = [];
    $append = static function (array $row) use (&$rows, $recordState, $recordType, $search, $status, $date): void {
        if ($recordType !== 'all' && $row['record_type'] !== $recordType) {
            return;
        }

        if ($row['record_state'] !== $recordState) {
            return;
        }

        if ($status !== '' && stripos($row['status'], $status) === false) {
            return;
        }

        if ($date !== '' && strncmp((string) $row['created_at'], $date, 10) !== 0) {
            return;
        }

        $haystack = strtolower(implode(' ', array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $row
        )));

        if ($search !== '' && !str_contains($haystack, strtolower($search))) {
            return;
        }

        $rows[] = $row;
    };

    foreach (fetch_orders('administrator', 0) as $order) {
        $append([
            'record_type' => 'order',
            'record_id' => (int) $order['id'],
            'reference' => $order['reference_no'],
            'title' => $order['customer_name'],
            'description' => 'Order total ' . currency((float) $order['total_amount']),
            'status' => $order['order_status'],
            'created_at' => $order['created_at'],
            'priority_sort_at' => $order['updated_at'] ?? $order['created_at'],
            'record_state' => $order['record_status'] ?? 'active',
            'priority_rank' => $order['priority_rank'] ?? 4,
            'priority_level' => $order['priority_level'] ?? 'low',
        ]);
    }

    foreach (fetch_transactions('administrator', 0) as $transaction) {
        $append([
            'record_type' => 'transaction',
            'record_id' => (int) $transaction['id'],
            'reference' => $transaction['reference_no'],
            'title' => $transaction['customer_name'],
            'description' => ucfirst($transaction['payment_method']) . ' payment ' . currency((float) $transaction['amount']),
            'status' => $transaction['transaction_status'],
            'created_at' => $transaction['created_at'],
            'priority_sort_at' => $transaction['updated_at'] ?? $transaction['created_at'],
            'record_state' => $transaction['record_status'] ?? 'active',
            'priority_rank' => $transaction['priority_rank'] ?? 4,
            'priority_level' => $transaction['priority_level'] ?? 'low',
        ]);
    }

    foreach (fetch_deliveries('administrator', 0) as $delivery) {
        $append([
            'record_type' => 'delivery',
            'record_id' => (int) $delivery['id'],
            'reference' => $delivery['reference_no'],
            'title' => $delivery['customer_name'],
            'description' => $delivery['delivery_address'],
            'status' => $delivery['delivery_status'],
            'created_at' => $delivery['created_at'],
            'priority_sort_at' => $delivery['updated_at'] ?? $delivery['created_at'],
            'record_state' => $delivery['record_status'] ?? 'active',
            'priority_rank' => $delivery['priority_rank'] ?? 4,
            'priority_level' => $delivery['priority_level'] ?? 'low',
        ]);
    }

    foreach (fetch_customer_verifications('all', '', ['role' => 'administrator', 'can_act' => true]) as $customer) {
        $recordState = db_find(
            'SELECT record_status FROM record_states WHERE record_type = "customer_verification" AND record_id = ? LIMIT 1',
            'i',
            [(int) $customer['id']]
        );
        $reviewedAt = !empty($customer['verification_reviewed_at'])
            ? (string) $customer['verification_reviewed_at']
            : '';
        $approverName = (string) ($customer['verification_reviewed_name'] ?? '');
        $approverRole = (string) ($customer['verification_reviewed_role'] ?? '');
        $approverLabel = $approverName !== ''
            ? $approverName . ' (' . role_label($approverRole) . ')'
            : 'Pending approval';
        $approvalDate = format_display_date($reviewedAt);
        $approvalTime = $reviewedAt !== '' ? date('h:i A', strtotime($reviewedAt)) : '';

        $append([
            'record_type' => 'customer_verification',
            'record_id' => (int) $customer['id'],
            'reference' => 'CUST-' . $customer['id'],
            'title' => $customer['full_name'],
            'description' => $customer['email'] . ' | Approved/Reviewed by: ' . $approverLabel,
            'status' => (string) $customer['status'] === 'active' ? 'approved' : (string) $customer['status'],
            'created_at' => $customer['verification_reviewed_at'] ?: $customer['created_at'],
            'priority_sort_at' => $customer['updated_at'] ?? $customer['created_at'],
            'record_state' => $recordState['record_status'] ?? 'active',
            'customer_name' => (string) $customer['full_name'],
            'approver_name' => $approverName,
            'approver_role' => $approverRole,
            'approval_date' => $approvalDate,
            'approval_time' => $approvalTime,
            'approved_at' => $reviewedAt,
            'priority_rank' => $customer['priority_rank'] ?? 4,
            'priority_level' => $customer['priority_level'] ?? 'low',
        ]);
    }

    $auditLogs = db_select(
        'SELECT a.*, COALESCE(rs.record_status, "active") AS record_status
         FROM audit_logs a
         LEFT JOIN record_states rs ON rs.record_type = "audit" AND rs.record_id = a.id
         ORDER BY a.created_at DESC LIMIT 200'
    );

    foreach ($auditLogs as $log) {
        $append([
            'record_type' => 'audit',
            'record_id' => (int) $log['id'],
            'reference' => 'AUD-' . $log['id'],
            'title' => $log['action'],
            'description' => $log['description'],
            'status' => 'completed',
            'created_at' => $log['created_at'],
            'priority_sort_at' => $log['created_at'],
            'record_state' => $log['record_status'] ?? 'active',
            'priority_rank' => 5,
            'priority_level' => 'low',
        ]);
    }

    usort($rows, static function ($a, $b): int {
        $rankCompare = ((int) ($a['priority_rank'] ?? 4)) <=> ((int) ($b['priority_rank'] ?? 4));
        return $rankCompare !== 0 ? $rankCompare : strcmp((string) ($b['priority_sort_at'] ?? $b['created_at']), (string) ($a['priority_sort_at'] ?? $a['created_at']));
    });

    return $rows;
}

function fetch_staff_record_history(int $staffId, array $filters = []): array
{
    ensure_operational_feature_columns();

    $type = (string) ($filters['type'] ?? 'orders');
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $date = trim((string) ($filters['date'] ?? ''));
    $rows = [];
    $append = static function (array $row) use (&$rows, $search, $status, $date): void {
        if ($status !== '' && stripos($row['status'], $status) === false) {
            return;
        }

        if ($date !== '' && strncmp((string) $row['created_at'], $date, 10) !== 0) {
            return;
        }

        $haystack = strtolower(implode(' ', [$row['reference'], $row['customer_name'], $row['status'], $row['description']]));

        if ($search !== '' && !str_contains($haystack, strtolower($search))) {
            return;
        }

        $rows[] = $row;
    };

    if (in_array($type, ['orders', 'all'], true)) {
        foreach (fetch_orders('staff', $staffId) as $order) {
            $append([
                'record_type' => 'Order',
                'reference' => $order['reference_no'],
                'customer_name' => $order['customer_name'],
                'status' => $order['order_status'],
                'description' => currency((float) $order['total_amount']),
                'created_at' => $order['created_at'],
                'priority_sort_at' => $order['updated_at'] ?? $order['created_at'],
                'priority_rank' => $order['priority_rank'] ?? 4,
                'priority_level' => $order['priority_level'] ?? 'low',
            ]);
        }
    }

    if (in_array($type, ['deliveries', 'all'], true)) {
        foreach (fetch_deliveries('staff', $staffId) as $delivery) {
            $append([
                'record_type' => 'Delivery',
                'reference' => $delivery['reference_no'],
                'customer_name' => $delivery['customer_name'],
                'status' => $delivery['delivery_status'],
                'description' => $delivery['delivery_address'],
                'created_at' => $delivery['created_at'],
                'priority_sort_at' => $delivery['updated_at'] ?? $delivery['created_at'],
                'priority_rank' => $delivery['priority_rank'] ?? 4,
                'priority_level' => $delivery['priority_level'] ?? 'low',
            ]);
        }
    }

    if (in_array($type, ['transactions', 'all'], true)) {
        foreach (fetch_transactions('staff', $staffId) as $transaction) {
            $append([
                'record_type' => 'Transaction',
                'reference' => $transaction['reference_no'],
                'customer_name' => $transaction['customer_name'],
                'status' => $transaction['transaction_status'],
                'description' => ucfirst($transaction['payment_method']) . ' ' . currency((float) $transaction['amount']),
                'created_at' => $transaction['created_at'],
                'priority_sort_at' => $transaction['updated_at'] ?? $transaction['created_at'],
                'priority_rank' => $transaction['priority_rank'] ?? 4,
                'priority_level' => $transaction['priority_level'] ?? 'low',
            ]);
        }
    }

    usort($rows, static function ($a, $b): int {
        $rankCompare = ((int) ($a['priority_rank'] ?? 4)) <=> ((int) ($b['priority_rank'] ?? 4));
        return $rankCompare !== 0 ? $rankCompare : strcmp((string) ($b['priority_sort_at'] ?? $b['created_at']), (string) ($a['priority_sort_at'] ?? $a['created_at']));
    });

    return $rows;
}

function summary_cards(array $stats, string $role): array
{
    if (in_array($role, ['administrator', 'manager'], true)) {
        return [
            ['label' => 'Total Sales', 'value' => currency($stats['sales_total']), 'icon' => 'sales'],
            ['label' => 'Orders', 'value' => $stats['orders_total'], 'icon' => 'orders'],
            ['label' => 'Transactions', 'value' => $stats['transactions_total'], 'icon' => 'payments'],
            ['label' => 'Deliveries', 'value' => $stats['deliveries_total'], 'icon' => 'delivery'],
        ];
    }

    if ($role === 'staff') {
        return [
            ['label' => 'Assigned Orders', 'value' => $stats['orders_total'], 'icon' => 'orders'],
            ['label' => 'Pending Actions', 'value' => $stats['pending_orders'], 'icon' => 'alerts'],
            ['label' => 'Transactions Recorded', 'value' => $stats['transactions_total'], 'icon' => 'payments'],
            ['label' => 'Assigned Deliveries', 'value' => $stats['deliveries_total'], 'icon' => 'delivery'],
        ];
    }

    return [
        ['label' => 'Total Spend', 'value' => currency($stats['sales_total']), 'icon' => 'sales'],
        ['label' => 'Orders', 'value' => $stats['orders_total'], 'icon' => 'orders'],
        ['label' => 'Transactions', 'value' => $stats['transactions_total'], 'icon' => 'payments'],
        ['label' => 'In Progress', 'value' => $stats['pending_orders'], 'icon' => 'alerts'],
    ];
}

