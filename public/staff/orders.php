<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

$currentStaffId = (int) current_user()['id'];
$search = table_search_value();
$scope = (string) request('scope', 'all');
$scope = in_array($scope, ['all', 'handled'], true) ? $scope : 'all';
$deliveryFilter = (string) request('delivery_status', '');
$deliveryFilterOptions = [
    'pending' => 'Pending',
    'assigned' => 'Ready for Delivery',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'failed_delivery' => 'Failed Delivery',
    'cancelled' => 'Cancelled',
];
$deliveryFilter = array_key_exists($deliveryFilter, $deliveryFilterOptions) ? $deliveryFilter : '';

if (is_post()) {
    $orderId = (int) request('order_id');
    $staffId = $currentStaffId;
    $proofPhotoPath = null;
    try {
        $proofPhotoPath = save_uploaded_delivery_proof($_FILES['proof_photo'] ?? null);
        update_order_workflow_status(
            $orderId,
            (string) request('workflow_status'),
            $staffId,
            '',
            null,
            $proofPhotoPath,
            trim((string) request('recipient_name'))
        );
        flash('orders', 'Order and delivery workflow updated.', 'success');
    } catch (InvalidArgumentException $exception) {
        if ($proofPhotoPath && function_exists('delete_uploaded_evidence')) {
            delete_uploaded_evidence($proofPhotoPath);
        }
        flash('orders', $exception->getMessage(), 'danger');
    }

    redirect('staff/orders.php');
}

$allOrders = fetch_orders('staff', $currentStaffId, $search);
$orders = array_values(array_filter($allOrders, static function (array $order) use ($currentStaffId, $scope, $deliveryFilter): bool {
    $isHandled = (int) ($order['prepared_by_staff_id'] ?? 0) === $currentStaffId;

    if ($scope === 'handled' && !$isHandled) {
        return false;
    }

    if ($deliveryFilter !== '' && ($order['delivery_status'] ?? '') !== $deliveryFilter) {
        return false;
    }

    return true;
}));
$assignedCount = count(array_filter($allOrders, static fn (array $order): bool => (int) ($order['prepared_by_staff_id'] ?? 0) === $currentStaffId));
$outForDeliveryCount = count(array_filter($allOrders, static fn (array $order): bool => ($order['delivery_status'] ?? '') === 'out_for_delivery'));
$deliveredCount = count(array_filter($allOrders, static fn (array $order): bool => ($order['delivery_status'] ?? '') === 'delivered'));
$failedCount = count(array_filter($allOrders, static fn (array $order): bool => ($order['delivery_status'] ?? '') === 'failed_delivery'));

$pageTitle = 'Process Customer Orders';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main staff-orders-page">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Process Customer Orders', 'Confirm orders, prepare them, and mark them ready for rider assignment.', 'orders', 'Order Management'); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue">H</div><strong><?= e((string) $assignedCount) ?></strong><span>Handled by Me</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">T</div><strong><?= e((string) $outForDeliveryCount) ?></strong><span>Out for Delivery</span></article>
                <article class="metric-box"><div class="metric-box__icon green">D</div><strong><?= e((string) $deliveredCount) ?></strong><span>Delivered</span></article>
                <article class="metric-box"><div class="metric-box__icon red">F</div><strong><?= e((string) $failedCount) ?></strong><span>Failed Delivery</span></article>
            </div>

            <form method="get" class="toolbar">
                <input type="search" name="search" placeholder="Search reference, customer, or status" value="<?= e($search) ?>">
                <select name="scope">
                    <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All Orders</option>
                    <option value="handled" <?= $scope === 'handled' ? 'selected' : '' ?>>Handled by Me</option>
                </select>
                <select name="delivery_status">
                    <option value="">All Delivery Statuses</option>
                    <?php foreach ($deliveryFilterOptions as $status => $label): ?>
                        <option value="<?= e($status) ?>" <?= $deliveryFilter === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Filter Orders</button>
                <a class="btn btn-ghost" href="<?= e(url('staff/orders.php')) ?>">Reset</a>
            </form>
            <div class="staff-order-list" aria-label="Customer orders">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $handledByCurrentStaff = (int) ($order['prepared_by_staff_id'] ?? 0) === $currentStaffId;
                    $handlerLabel = !empty($order['prepared_by_name']) ? 'Handled by ' . $order['prepared_by_name'] : 'Not handled yet';
                    $workflowStatus = order_workflow_current_status($order);
                    $nextWorkflowStatuses = order_workflow_next_statuses($order);
                    $canUpdateWorkflow = staff_can_update_order_workflow($order, $currentStaffId) && $nextWorkflowStatuses !== [];
                    $recipientRequired = array_key_exists('delivered', $nextWorkflowStatuses);
                    ?>
                    <article class="staff-order-card <?= priority_item_class($order) ?>" <?= priority_data_attr($order) ?>>
                        <header class="staff-order-card__header">
                            <h2><?= e($order['reference_no']) ?></h2>
                            <?= priority_badge($order, 'order') ?>
                            <p><?= e($order['customer_name']) ?></p>
                        </header>

                        <div class="staff-order-card__status-grid">
                            <div class="staff-order-card__status">
                                <span>Preparing Staff</span>
                                <?= $handledByCurrentStaff ? '<span class="badge badge-success">Handled by Me</span>' : '<span class="badge badge-info">' . e($handlerLabel) . '</span>' ?>
                            </div>
                            <div class="staff-order-card__status">
                                <span>Order Status</span>
                                <?= status_badge((string) $order['order_status']) ?>
                            </div>
                            <div class="staff-order-card__status">
                                <span>Payment</span>
                                <?= order_payment_summary($order) ?>
                            </div>
                            <div class="staff-order-card__status">
                                <span>Delivery</span>
                                <?= status_badge((string) $order['delivery_status']) ?>
                            </div>
                        </div>

                        <div class="staff-order-card__updates">
                            <section class="staff-order-card__update" aria-label="Update order workflow">
                                <h3>Update Order Status</h3>
                                <?php if ($canUpdateWorkflow): ?>
                                    <form method="post" class="staff-order-card__form staff-order-card__form--stacked" enctype="multipart/form-data" data-status-action-form>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= e((string) $order['id']) ?>">
                                        <div class="staff-order-card__form-row status-action-controls" data-status-action-controls>
                                            <select name="workflow_status" aria-label="Next workflow status for <?= e($order['reference_no']) ?>">
                                                <?php foreach ($nextWorkflowStatuses as $status => $label): ?>
                                                    <option value="<?= e($status) ?>"><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-primary btn-sm status-action-button" type="submit"><?= status_action_button_content('check-circle', 'Apply Status', 'Applying...') ?></button>
                                        </div>
                                        <?php if ($recipientRequired): ?>
                                            <input type="text" name="recipient_name" placeholder="Recipient name" required>
                                            <input type="file" name="proof_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-muted"><?= $workflowStatus === 'ready_for_delivery' ? 'Assign a rider first' : 'View Only' ?></span>
                                <?php endif; ?>
                            </section>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if ($orders === []): ?>
                    <div class="staff-order-card staff-order-card--empty">
                        <h2>No customer orders found.</h2>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
