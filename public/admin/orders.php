<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

if (is_post()) {
    try {
        assign_order_to_staff((int) request('order_id'), (int) request('rider_id'), (int) current_user()['id']);
        flash('orders', 'Order assigned to the rider successfully.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('orders', $exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Administrator rider assignment failed (' . $exception::class . ', code ' . (int) $exception->getCode() . ').');
        flash('orders', 'Rider assignment could not be completed. Please try again.', 'danger');
    }

    redirect('admin/orders.php');
}

$orderStatusOptions = ['pending', 'processing', 'confirmed', 'assigned', 'out_for_delivery', 'completed', 'cancelled'];
$paymentStatusOptions = ['pending', 'accepted', 'paid', 'failed', 'cancelled'];
$deliveryStatusOptions = ['pending', 'assigned', 'out_for_delivery', 'delivered', 'issue_reported', 'failed_delivery', 'cancelled'];
$filters = [
    'search' => table_search_value(),
    'order_status' => in_array((string) request('order_status'), $orderStatusOptions, true) ? (string) request('order_status') : '',
    'payment_status' => in_array((string) request('payment_status'), $paymentStatusOptions, true) ? (string) request('payment_status') : '',
    'delivery_status' => in_array((string) request('delivery_status'), $deliveryStatusOptions, true) ? (string) request('delivery_status') : '',
];
$orders = fetch_orders_filtered('administrator', (int) current_user()['id'], $filters);
$riderAccounts = array_values(array_filter(fetch_users('rider'), static fn (array $rider): bool => ($rider['status'] ?? '') === 'active'));
$riderActiveCounts = [];
foreach (db_select(
    'SELECT assigned_staff_id, COUNT(*) AS active_count
     FROM deliveries
     WHERE assigned_staff_id IS NOT NULL AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported")
     GROUP BY assigned_staff_id'
) as $countRow) {
    $riderActiveCounts[(int) $countRow['assigned_staff_id']] = (int) $countRow['active_count'];
}

if (request('export') === '1') {
    $rows = array_map(static fn ($row) => [
        $row['reference_no'],
        $row['customer_name'],
        $row['order_status'],
        $row['payment_method'] === 'gcash' ? 'GCash' : 'Cash on Delivery',
        $row['payment_status'],
        $row['gcash_reference'] ?? '',
        $row['transaction_status'] ?? $row['payment_status'],
        $row['delivery_status'],
        $row['total_amount'],
        format_display_datetime($row['created_at'] ?? null),
    ], $orders);
    export_csv('orders.csv', ['Reference', 'Customer', 'Order Status', 'Payment Method', 'Payment Status', 'GCash Reference', 'Verification Status', 'Delivery', 'Total', 'Created At'], $rows);
}

$exportQuery = http_build_query(array_merge(array_filter($filters, static fn ($value) => $value !== ''), ['export' => '1']));

$pageTitle = 'Sales Ordering Management';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('All Orders', 'Filter orders and export the current order list.', 'orders', 'Order Management', static function () use ($exportQuery): void { ?><a class="btn btn-secondary" href="<?= e(url('admin/orders.php?' . $exportQuery)) ?>">Export Order List</a><?php }); ?>
            <form method="get" class="toolbar">
                <input type="search" name="search" placeholder="Search order records" value="<?= e($filters['search']) ?>">
                <select name="order_status">
                    <option value="">All Order Statuses</option>
                    <?php foreach ($orderStatusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['order_status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="payment_status">
                    <option value="">All Payment Statuses</option>
                    <?php foreach ($paymentStatusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['payment_status'] === $status ? 'selected' : '' ?>><?= e(ucwords($status)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="delivery_status">
                    <option value="">All Delivery Statuses</option>
                    <?php foreach ($deliveryStatusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['delivery_status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Filter Orders</button>
                <a class="btn btn-ghost" href="<?= e(url('admin/orders.php')) ?>">Reset</a>
            </form>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Order Status</th>
                            <th>Payment</th>
                            <th>Delivery</th>
                            <th>Total</th>
                            <th>Assigned Rider</th>
                            <th>Assign Order to Rider</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $assignedRiderId = (int) ($order['delivery_assigned_staff_id'] ?: $order['assigned_staff_id']);
                            $isAssigned = $assignedRiderId > 0;
                            $canAssign = !$isAssigned && order_workflow_current_status($order) === 'ready_for_delivery';
                            $assignedRiderName = (string) ($order['staff_name'] ?: 'Rider #' . $assignedRiderId);
                            ?>
                            <tr<?= priority_item_attrs($order) ?>>
                                <td><?= e($order['reference_no']) ?><?= priority_badge($order, 'order') ?></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= status_badge((string) $order['order_status']) ?></td>
                                <td><?= order_payment_summary($order) ?></td>
                                <td><?= status_badge((string) $order['delivery_status']) ?></td>
                                <td><?= e(currency((float) $order['total_amount'])) ?></td>
                                <td><?= e($isAssigned ? $assignedRiderName : 'Unassigned') ?></td>
                                <td class="status-action-controls" data-status-action-controls>
                                    <?php if ($isAssigned): ?>
                                        <?= assigned_rider_locked_control($assignedRiderName) ?>
                                    <?php elseif ($canAssign): ?>
                                        <form method="post" class="inline-form" data-status-action-form>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="order_id" value="<?= e((string) $order['id']) ?>">
                                            <select name="rider_id" required>
                                                <option value="">Select rider</option>
                                                <?php foreach ($riderAccounts as $rider): ?>
                                                    <?php $activeDeliveryCount = $riderActiveCounts[(int) $rider['id']] ?? 0; ?>
                                                    <option value="<?= e((string) $rider['id']) ?>" <?= $activeDeliveryCount >= delivery_active_limit() ? 'disabled' : '' ?>><?= e($rider['full_name'] . ' (' . $activeDeliveryCount . '/' . delivery_active_limit() . ')') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-primary btn-sm status-action-button" type="submit"><?= status_action_button_content('users', 'Assign Rider', 'Assigning...') ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Awaiting Ready for Delivery</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
