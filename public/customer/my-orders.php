<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

if (is_post() && post_scalar('action') === 'cancel_order') {
    try {
        $orderId = filter_var(post_scalar('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            throw new InvalidArgumentException('Please select a valid order to cancel.');
        }
        cancel_order((int) $orderId, (int) current_user()['id']);
        flash('order', 'Your unpaid order was cancelled and its inventory was restored.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('order', $exception->getMessage(), 'danger');
    }
    redirect('customer/my-orders.php');
}

$user = current_user();
$stats = fetch_dashboard_stats('customer', (int) $user['id']);
$orders = fetch_orders('customer', (int) $user['id'], table_search_value());
$averageOrder = $stats['orders_total'] > 0 ? $stats['sales_total'] / $stats['orders_total'] : 0;
$deliveredCount = count(array_filter($orders, static fn ($order) => $order['delivery_status'] === 'delivered'));

$pageTitle = 'My Orders';
$bodyClass = 'customer-body';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>

    <section class="section">
        <?php render_page_header('My Orders', 'View your order history and track deliveries.', 'orders', 'My Account'); ?>

        <div class="metric-grid">
            <article class="metric-box"><div class="metric-box__icon blue">#</div><strong><?= e((string) $stats['orders_total']) ?></strong><span>Total Orders</span></article>
            <article class="metric-box"><div class="metric-box__icon green">₱</div><strong><?= e(currency($stats['sales_total'])) ?></strong><span>Total Spent</span></article>
            <article class="metric-box"><div class="metric-box__icon purple">✓</div><strong><?= e((string) $deliveredCount) ?></strong><span>Delivered</span></article>
            <article class="metric-box"><div class="metric-box__icon orange">≈</div><strong><?= e(currency($averageOrder)) ?></strong><span>Avg Order Value</span></article>
        </div>

        <div class="page-panel">
            <form method="get" class="toolbar">
                <input type="search" name="search" placeholder="Search orders by ID..." value="<?= e(table_search_value()) ?>">
                <button class="btn btn-secondary" type="submit">Search</button>
                <button type="button" class="btn btn-secondary" data-export-table="customer-orders">Export</button>
            </form>

            <div class="table-responsive">
                <table class="data-table searchable-table" data-export-name="customer-orders">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Order Status</th>
                            <th>Payment</th>
                            <th>Delivery Status</th>
                            <th>Delivered At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php $itemCount = isset($order['item_count']) ? (int) $order['item_count'] : count(fetch_order_items((int) $order['id'])); ?>
                            <tr<?= priority_item_attrs($order) ?>>
                                <td><?= e($order['reference_no']) ?><?= priority_badge($order, 'order') ?></td>
                                <td><?= e(format_display_date($order['created_at'] ?? null)) ?></td>
                                <td><?= e((string) $itemCount) ?> item(s)</td>
                                <td><?= e(currency((float) $order['total_amount'])) ?></td>
                                <td><?= status_badge((string) $order['order_status']) ?></td>
                                <td><?= order_payment_summary($order) ?></td>
                                <td><?= status_badge((string) $order['delivery_status']) ?></td>
                                <td><?= !empty($order['delivery_delivered_at']) ? e(format_display_datetime($order['delivery_delivered_at'])) : '<span class="badge badge-muted">Not recorded</span>' ?></td>
                                <td>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/order-detail.php?id=' . $order['id'])) ?>">View Receipt</a>
                                    <?php if (customer_can_cancel_order($order, (int) $user['id'])): ?>
                                        <form method="post" class="inline-form" data-confirm-message="Cancel this pending order? Reserved inventory will be returned." data-confirm-title="Cancel Order" data-confirm-label="Cancel Order" data-confirm-variant="danger" data-confirm-sensitive="true">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?= e((string) $order['id']) ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">Cancel</button>
                                        </form>
                                    <?php elseif (($order['order_status'] ?? '') === 'pending' && customer_order_has_protected_gcash_payment($order)): ?>
                                        <div class="stack-form">
                                            <small>Your GCash payment is already under review. Please contact support or wait for the payment review before requesting cancellation.</small>
                                            <a class="btn btn-ghost btn-sm customer-support-action" href="<?= e(url('customer/support.php')) ?>" aria-label="Contact Support" title="Contact Support" data-action-icon="support-headset">Contact Support</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
