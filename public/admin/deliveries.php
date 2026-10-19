<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$deliveryStatusOptions = delivery_status_labels();

if (is_post() && request('action') === 'assign_delivery') {
    $staffMember = fetch_user_by_id((int) request('rider_id'));

    if (!$staffMember || ($staffMember['role'] ?? '') !== 'rider' || ($staffMember['status'] ?? '') !== 'active') {
        flash('deliveries', 'Please select an active rider account.', 'danger');
    } else {
        try {
            assign_delivery_to_staff((int) request('order_id'), (int) request('rider_id'), (int) current_user()['id'], (string) request('estimated_delivery_at', ''));
            flash('deliveries', 'Delivery personnel assigned successfully.', 'success');
        } catch (InvalidArgumentException $exception) {
            flash('deliveries', $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            error_log('Administrator delivery assignment failed (' . $exception::class . ', code ' . (int) $exception->getCode() . ').');
            flash('deliveries', 'Rider assignment could not be completed. Please try again.', 'danger');
        }
    }

    redirect('admin/deliveries.php');
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

    redirect('admin/deliveries.php');
}

$search = table_search_value();
$statusFilter = (string) request('delivery_status', '');
$statusFilter = array_key_exists($statusFilter, $deliveryStatusOptions) ? $statusFilter : '';
$allDeliveries = fetch_deliveries('administrator', (int) current_user()['id']);
$deliveries = array_values(array_filter($allDeliveries, static function (array $delivery) use ($search, $statusFilter): bool {
    if ($statusFilter !== '' && ($delivery['delivery_status'] ?? '') !== $statusFilter) {
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
    ]));

    return str_contains($haystack, strtolower($search));
}));
$staffMembers = array_values(array_filter(fetch_users('rider'), static fn (array $staff): bool => ($staff['status'] ?? '') === 'active'));
$riderActiveCounts = [];
foreach (db_select(
    'SELECT assigned_staff_id, COUNT(*) AS active_count
     FROM deliveries
     WHERE assigned_staff_id IS NOT NULL AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported")
     GROUP BY assigned_staff_id'
) as $countRow) {
    $riderActiveCounts[(int) $countRow['assigned_staff_id']] = (int) $countRow['active_count'];
}
$active = count(array_filter($allDeliveries, static fn ($delivery) => in_array($delivery['delivery_status'], ['assigned', 'out_for_delivery', 'issue_reported'], true)));
$today = date('Y-m-d');
$deliveredToday = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'delivered' && !empty($delivery['delivered_at']) && date('Y-m-d', strtotime((string) $delivery['delivered_at'])) === $today));
$inTransit = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'out_for_delivery'));
$pending = count(array_filter($allDeliveries, static fn ($delivery) => $delivery['delivery_status'] === 'pending'));

$pageTitle = 'Deliveries';
$pageStyles = ['css/admin-deliveries.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section admin-deliveries">
            <?php render_page_header('Deliveries', 'Manage rider assignments and track customer deliveries.', 'delivery', 'Delivery Management', null, 'admin-deliveries__heading'); ?>

            <div class="metric-grid admin-deliveries__metrics">
                <article class="metric-box"><div class="metric-box__icon blue"><?= sidebar_icon_svg('delivery') ?></div><strong><?= e((string) $active) ?></strong><span>Active Deliveries</span></article>
                <article class="metric-box"><div class="metric-box__icon green"><?= sidebar_icon_svg('check-circle') ?></div><strong><?= e((string) $deliveredToday) ?></strong><span>Delivered Today</span></article>
                <article class="metric-box"><div class="metric-box__icon purple"><?= sidebar_icon_svg('location') ?></div><strong><?= e((string) $inTransit) ?></strong><span>In Transit</span></article>
                <article class="metric-box"><div class="metric-box__icon orange"><?= sidebar_icon_svg('clock') ?></div><strong><?= e((string) $pending) ?></strong><span>Pending</span></article>
            </div>

            <div class="page-panel admin-deliveries__panel">
                <form class="toolbar admin-deliveries__toolbar" method="get" role="search">
                    <label class="admin-deliveries__search">
                        <span class="sr-only">Search deliveries</span>
                        <?= sidebar_icon_svg('reports', 'admin-deliveries__search-icon') ?>
                        <input type="search" name="search" placeholder="Search reference, customer, rider, or address..." value="<?= e($search) ?>">
                    </label>
                    <label class="admin-deliveries__filter">
                        <span class="sr-only">Filter by delivery status</span>
                        <select name="delivery_status" aria-label="Filter by delivery status">
                            <option value="">All Status</option>
                            <?php foreach ($deliveryStatusOptions as $status => $label): ?>
                                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <a class="btn btn-ghost admin-deliveries__reset" href="<?= e(url('admin/deliveries.php')) ?>">Reset</a>
                    <button class="btn btn-primary admin-deliveries__submit" type="submit">Search</button>
                </form>

                <div class="admin-deliveries__column-headings" aria-hidden="true">
                    <span>Reference</span><span>Customer</span><span>Rider</span><span>Status</span><span>Payment</span><span>ETA</span><span>Actions</span>
                </div>
                <div class="admin-deliveries__list">
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php
                        $assignedRiderId = (int) ($delivery['assigned_staff_id'] ?: $delivery['order_assigned_staff_id']);
                        $isAssigned = $assignedRiderId > 0;
                        $canAssign = !$isAssigned && order_workflow_current_status($delivery) === 'ready_for_delivery';
                        $hasException = in_array((string) ($delivery['delivery_status'] ?? ''), ['issue_reported', 'failed_delivery'], true);
                        $isDelivered = (string) ($delivery['delivery_status'] ?? '') === 'delivered';
                        $stateClass = $hasException ? 'is-issue' : ($canAssign ? 'is-ready' : ($isDelivered ? 'is-delivered' : ''));
                        $orderId = (int) $delivery['order_id'];
                        ?>
                        <article class="admin-delivery-card <?= e(priority_item_class($delivery) . ' ' . $stateClass) ?>" <?= priority_data_attr($delivery) ?>>
                            <div class="admin-delivery-card__cell admin-delivery-card__reference">
                                <span class="admin-delivery-card__label">Reference</span>
                                <strong class="admin-delivery-card__reference-number"><?= e((string) $delivery['reference_no']) ?></strong>
                                <?= priority_badge($delivery, 'delivery') ?>
                                <?php if (!empty($delivery['delivery_address'])): ?>
                                    <p class="admin-delivery-card__address"><?= sidebar_icon_svg('location') ?><span><?= e((string) $delivery['delivery_address']) ?></span></p>
                                <?php endif; ?>
                                <?php if (!empty($delivery['delivery_landmark'])): ?>
                                    <small class="admin-delivery-card__landmark">Landmark: <?= e((string) $delivery['delivery_landmark']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="admin-delivery-card__cell">
                                <span class="admin-delivery-card__label">Customer</span>
                                <strong><?= e((string) ($delivery['customer_name'] ?? 'Unknown customer')) ?></strong>
                                <?php if (!empty($delivery['customer_phone'])): ?><small><?= e((string) $delivery['customer_phone']) ?></small><?php endif; ?>
                            </div>
                            <div class="admin-delivery-card__cell">
                                <span class="admin-delivery-card__label">Rider</span>
                                <strong><?= e((string) ($delivery['assigned_name'] ?? 'Unassigned')) ?></strong>
                                <small><?= !empty($delivery['assigned_phone']) ? e((string) $delivery['assigned_phone']) : 'No rider assigned' ?></small>
                            </div>
                            <div class="admin-delivery-card__cell">
                                <span class="admin-delivery-card__label">Status</span>
                                <div><?= order_workflow_badge($delivery) ?></div>
                            </div>
                            <div class="admin-delivery-card__cell admin-delivery-card__payment">
                                <span class="admin-delivery-card__label">Payment</span>
                                <?= order_payment_summary($delivery) ?>
                            </div>
                            <div class="admin-delivery-card__cell admin-delivery-card__eta">
                                <span class="admin-delivery-card__label">ETA</span>
                                <?= !empty($delivery['estimated_delivery_at']) ? '<time datetime="' . e(date('c', strtotime((string) $delivery['estimated_delivery_at']))) . '">' . e(format_display_datetime($delivery['estimated_delivery_at'])) . '</time>' : '<span class="badge badge-muted">Not set</span>' ?>
                            </div>
                            <div class="admin-delivery-card__cell admin-delivery-card__actions status-action-controls" data-status-action-controls>
                                <span class="admin-delivery-card__label">Actions</span>
                                <?php if ($hasException): ?>
                                    <details class="admin-delivery-action admin-delivery-action--issue">
                                        <summary><?= sidebar_icon_svg('alert-triangle') ?><span>Resolve Issue</span></summary>
                                        <div class="admin-delivery-action__body">
                                            <p class="admin-delivery-action__issue"><strong>Rider report</strong><?= e((string) ($delivery['issue_notes'] ?? 'No notes provided by rider.')) ?></p>
                                            <form method="post" class="stack-form admin-delivery-form" data-status-action-form data-confirm-message="Apply the selected resolution to this delivery exception?" data-confirm-title="Resolve Delivery Exception" data-confirm-label="Apply Resolution" data-confirm-variant="warning">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="resolve_exception">
                                                <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                                                <label><span>Resolution</span><select name="resolution_action" required><option value="return_to_ready">Return to Ready Queue</option><option value="reassign">Reassign Now</option></select></label>
                                                <label><span>Rider for reassignment</span><select name="rider_id"><option value="">Select only when reassigning</option>
                                                    <?php foreach ($staffMembers as $staff): ?>
                                                        <?php $count = $riderActiveCounts[(int) $staff['id']] ?? 0; ?>
                                                        <option value="<?= e((string) $staff['id']) ?>" <?= $count >= delivery_active_limit() ? 'disabled' : '' ?>><?= e($staff['full_name'] . ' (' . $count . '/' . delivery_active_limit() . ')') ?></option>
                                                    <?php endforeach; ?>
                                                </select></label>
                                                <?= datetime_input_field('estimated_delivery_at', $delivery['estimated_delivery_at'] ?? '', 'Estimated delivery', false, 'resolve-' . $orderId, 'admin-delivery-form__field') ?>
                                                <label><span>Resolution reason</span><textarea name="resolution_reason" rows="3" minlength="5" maxlength="1000" required placeholder="Explain how this issue was resolved"></textarea></label>
                                                <button class="btn btn-secondary status-action-button" type="submit"><?= status_action_button_content('refresh', 'Apply Resolution', 'Applying...') ?></button>
                                            </form>
                                        </div>
                                    </details>
                                <?php elseif ($isAssigned): ?>
                                    <?= assigned_rider_locked_control((string) ($delivery['assigned_name'] ?: 'Rider #' . $assignedRiderId), 'stack-form admin-delivery-form') ?>
                                <?php elseif ($canAssign): ?>
                                    <details class="admin-delivery-action">
                                        <summary><?= sidebar_icon_svg('users') ?><span>Assign Rider</span></summary>
                                        <div class="admin-delivery-action__body">
                                            <form method="post" class="stack-form admin-delivery-form" data-status-action-form>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="assign_delivery">
                                                <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                                                <label><span>Active rider</span><select name="rider_id" required><option value="">Select rider</option>
                                                    <?php foreach ($staffMembers as $staff): ?>
                                                        <?php
                                                        $count = $riderActiveCounts[(int) $staff['id']] ?? 0;
                                                        $alreadyAssigned = (int) ($delivery['assigned_staff_id'] ?? 0) === (int) $staff['id'];
                                                        $capacityCount = max(0, $count - ($alreadyAssigned ? 1 : 0));
                                                        ?>
                                                        <option value="<?= e((string) $staff['id']) ?>" <?= $alreadyAssigned ? 'selected' : '' ?> <?= $capacityCount >= delivery_active_limit() ? 'disabled' : '' ?>><?= e($staff['full_name'] . ' (' . $count . '/' . delivery_active_limit() . ')') ?></option>
                                                    <?php endforeach; ?>
                                                </select></label>
                                                <?= datetime_input_field('estimated_delivery_at', $delivery['estimated_delivery_at'] ?? '', 'Estimated delivery', false, 'assign-' . $orderId, 'admin-delivery-form__field') ?>
                                                <button class="btn btn-primary status-action-button" type="submit"><?= status_action_button_content('delivery', 'Assign Rider', 'Assigning...') ?></button>
                                            </form>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <span class="admin-delivery-card__locked"><?= sidebar_icon_svg('shield-check') ?><span>Locked</span></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($deliveries === []): ?>
                        <div class="empty-state admin-deliveries__empty"><h2>No deliveries found</h2><p>Try changing the search term or delivery status filter.</p></div>
                    <?php endif; ?>
                </div>
                <p class="admin-deliveries__result-count">Showing <?= e((string) count($deliveries)) ?> <?= count($deliveries) === 1 ? 'delivery' : 'deliveries' ?></p>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
