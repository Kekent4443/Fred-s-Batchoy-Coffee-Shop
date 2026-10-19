<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

if (is_post() && request('action') === 'assign_delivery') {
    try {
        assign_delivery_to_staff((int) request('order_id'), (int) request('rider_id'), (int) current_user()['id'], (string) request('estimated_delivery_at', ''));
        flash('deliveries', 'Delivery assignment updated and sent to the rider.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('deliveries', $exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Manager delivery assignment failed (' . $exception::class . ', code ' . (int) $exception->getCode() . ').');
        flash('deliveries', 'Rider assignment could not be completed. Please try again.', 'danger');
    }

    redirect('manager/deliveries.php');
}

if (is_post() && request('action') === 'resolve_exception') {
    try {
        $resolutionAction = (string) request('resolution_action');
        $riderId = filter_var(request('rider_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        resolve_delivery_exception(
            (int) request('order_id'),
            $resolutionAction,
            (int) current_user()['id'],
            $riderId,
            (string) request('estimated_delivery_at', ''),
            trim((string) request('resolution_reason'))
        );
        flash('deliveries', $resolutionAction === 'reassign' ? 'Delivery exception resolved and reassigned.' : 'Delivery returned to the ready queue.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('deliveries', $exception->getMessage(), 'danger');
    }

    redirect('manager/deliveries.php');
}

$search = table_search_value();
$statusFilter = (string) request('delivery_status', '');
$deliveryStatusOptions = delivery_status_labels();
$statusFilter = array_key_exists($statusFilter, $deliveryStatusOptions) ? $statusFilter : '';
$allDeliveries = fetch_deliveries('manager', (int) current_user()['id']);
$deliveries = array_values(array_filter($allDeliveries, static function (array $delivery) use ($search, $statusFilter): bool {
    if ($statusFilter !== '' && $delivery['delivery_status'] !== $statusFilter) {
        return false;
    }

    if ($search === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', [
        $delivery['reference_no'] ?? '',
        $delivery['customer_name'] ?? '',
        $delivery['assigned_name'] ?? '',
        $delivery['delivery_status'] ?? '',
        $delivery['delivery_address'] ?? '',
        $delivery['delivery_landmark'] ?? '',
    ]));

    return str_contains($haystack, strtolower($search));
}));
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
$performance = db_select('SELECT u.full_name, COUNT(d.id) AS total_deliveries FROM deliveries d LEFT JOIN users u ON u.id = d.assigned_staff_id GROUP BY d.assigned_staff_id ORDER BY total_deliveries DESC');
$pending = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'pending'));
$assigned = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'assigned'));
$inTransit = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'out_for_delivery'));
$delivered = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'delivered'));

$pageTitle = 'Manager Deliveries';
$pageStyles = ['css/manager-deliveries.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Delivery Dashboard', 'Assign deliveries, monitor status, and keep rider delivery queues in sync.', 'delivery', 'Delivery Management'); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon orange">P</div><strong><?= e((string) $pending) ?></strong><span>Pending</span></article>
                <article class="metric-box"><div class="metric-box__icon blue">A</div><strong><?= e((string) $assigned) ?></strong><span>Assigned</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">T</div><strong><?= e((string) $inTransit) ?></strong><span>In Transit</span></article>
                <article class="metric-box"><div class="metric-box__icon green">D</div><strong><?= e((string) $delivered) ?></strong><span>Delivered</span></article>
            </div>

            <div class="page-panel manager-deliveries">
                <form method="get" class="toolbar manager-deliveries__toolbar" role="search">
                    <input type="search" name="search" placeholder="Search delivery, customer, rider, address..." value="<?= e($search) ?>">
                    <select name="delivery_status" aria-label="Filter by delivery status">
                        <option value="">All Delivery Statuses</option>
                        <?php foreach ($deliveryStatusOptions as $status => $label): ?>
                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" type="submit">Filter Deliveries</button>
                    <a class="btn btn-ghost" href="<?= e(url('manager/deliveries.php')) ?>">Reset</a>
                </form>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Customer</th>
                                <th>Assigned Rider</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Address</th>
                                <th>Delivered At</th>
                                <th>Assign for Delivery</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $delivery): ?>
                                <?php $assignedRiderId = (int) ($delivery['assigned_staff_id'] ?: $delivery['order_assigned_staff_id']); ?>
                                <?php $isAssigned = $assignedRiderId > 0; ?>
                                <?php $canAssign = !$isAssigned && order_workflow_current_status($delivery) === 'ready_for_delivery'; ?>
                                <?php $hasException = in_array((string) ($delivery['delivery_status'] ?? ''), ['issue_reported', 'failed_delivery'], true); ?>
                                <tr<?= priority_item_attrs($delivery) ?>>
                                    <td data-label="Reference"><?= e($delivery['reference_no']) ?><?= priority_badge($delivery, 'delivery') ?></td>
                                    <td data-label="Customer"><?= e($delivery['customer_name']) ?></td>
                                    <td data-label="Assigned rider"><?= e($delivery['assigned_name'] ?? 'Unassigned') ?></td>
                                    <td data-label="Status"><?= order_workflow_badge($delivery) ?></td>
                                    <td data-label="Payment"><?= order_payment_summary($delivery) ?></td>
                                    <td data-label="Delivery address">
                                        <span class="manager-deliveries__address"><?= e($delivery['delivery_address']) ?></span>
                                        <?php if (!empty($delivery['delivery_landmark'])): ?><small class="manager-deliveries__landmark">Landmark: <?= e((string) $delivery['delivery_landmark']) ?></small><?php endif; ?>
                                    </td>
                                    <td data-label="Delivered at"><?= !empty($delivery['delivered_at']) ? e(format_display_datetime($delivery['delivered_at'])) : '<span class="badge badge-muted">Not recorded</span>' ?></td>
                                    <td data-label="Actions" class="status-action-controls" data-status-action-controls>
                                        <?php if ($hasException): ?>
                                            <div class="manager-deliveries__issue">
                                                <strong>Issue:</strong> <?= e($delivery['issue_notes'] ?? 'No notes provided by rider.') ?>
                                            </div>
                                            <form method="post" class="stack-form" data-status-action-form data-confirm-message="Apply the selected resolution to this delivery exception?" data-confirm-title="Resolve Delivery Exception" data-confirm-label="Apply Resolution" data-confirm-variant="warning">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="resolve_exception">
                                                <input type="hidden" name="order_id" value="<?= e((string) $delivery['order_id']) ?>">
                                                <label>Resolution
                                                    <select name="resolution_action" required>
                                                        <option value="return_to_ready">Return to Ready Queue</option>
                                                        <option value="reassign">Reassign Now</option>
                                                    </select>
                                                </label>
                                                <label>Rider for reassignment
                                                    <select name="rider_id">
                                                        <option value="">Select only when reassigning</option>
                                                        <?php foreach ($riderAccounts as $rider): ?>
                                                            <?php $count = $riderActiveCounts[(int) $rider['id']] ?? 0; ?>
                                                            <option value="<?= e((string) $rider['id']) ?>" <?= $count >= delivery_active_limit() ? 'disabled' : '' ?>><?= e($rider['full_name'] . ' (' . $count . '/' . delivery_active_limit() . ')') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <?= datetime_input_field('estimated_delivery_at', $delivery['estimated_delivery_at'] ?? '', 'Estimated delivery', false, 'resolve-' . (int) $delivery['order_id'], 'manager-deliveries__datetime') ?>
                                                <label>Resolution reason
                                                    <textarea name="resolution_reason" rows="2" minlength="5" maxlength="1000" required placeholder="Explain how this issue was resolved"></textarea>
                                                </label>
                                                <button class="btn btn-secondary btn-sm status-action-button" type="submit"><?= status_action_button_content('refresh', 'Apply Resolution', 'Applying...') ?></button>
                                            </form>
                                        <?php elseif ($isAssigned): ?>
                                            <?= assigned_rider_locked_control((string) ($delivery['assigned_name'] ?: 'Rider #' . $assignedRiderId), 'stack-form') ?>
                                        <?php elseif ($canAssign): ?>
                                            <form method="post" class="stack-form" data-status-action-form>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="assign_delivery">
                                                <input type="hidden" name="order_id" value="<?= e((string) $delivery['order_id']) ?>">
                                                <label>Active rider
                                                    <select name="rider_id" required>
                                                        <option value="">Select rider</option>
                                                        <?php foreach ($riderAccounts as $rider): ?>
                                                            <?php
                                                            $count = $riderActiveCounts[(int) $rider['id']] ?? 0;
                                                            $alreadyAssigned = (int) ($delivery['assigned_staff_id'] ?? 0) === (int) $rider['id'];
                                                            $capacityCount = max(0, $count - ($alreadyAssigned ? 1 : 0));
                                                            ?>
                                                            <option value="<?= e((string) $rider['id']) ?>" <?= $alreadyAssigned ? 'selected' : '' ?> <?= $capacityCount >= delivery_active_limit() ? 'disabled' : '' ?>>
                                                                <?= e($rider['full_name'] . ' (' . $count . '/' . delivery_active_limit() . ')') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <?= datetime_input_field('estimated_delivery_at', $delivery['estimated_delivery_at'] ?? '', 'Estimated delivery', false, 'assign-' . (int) $delivery['order_id'], 'manager-deliveries__datetime') ?>
                                                <button class="btn btn-primary btn-sm status-action-button" type="submit"><?= status_action_button_content('users', 'Assign Rider', 'Assigning...') ?></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge badge-muted">Locked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($deliveries === []): ?>
                                <tr><td colspan="8">No delivery records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="page-panel page-panel--spaced">
                <div class="card-head"><h2>Delivery Performance</h2></div>
                <ul class="activity-list">
                    <?php foreach ($performance as $row): ?>
                        <li>
                            <strong><?= e($row['full_name'] ?? 'Unassigned') ?></strong>
                            <span><?= e((string) $row['total_deliveries']) ?> delivery assignment(s)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
