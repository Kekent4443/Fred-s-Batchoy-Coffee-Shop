<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$orderId = (int) request('id');
$order = fetch_order_by_id($orderId);

if (!$order || (int) $order['customer_id'] !== (int) current_user()['id']) {
    redirect('customer/my-orders.php');
}

if (is_post() && request('action') === 'resubmit_gcash_payment') {
    $newProofPath = null;
    try {
        $reference = trim((string) request('gcash_reference'));
        if ($reference === '') {
            throw new InvalidArgumentException('Please enter the new GCash reference number.');
        }
        $newProofPath = save_uploaded_payment_proof($_FILES['proof_of_payment'] ?? null);
        if (!$newProofPath) {
            throw new InvalidArgumentException('Please upload a new proof of payment.');
        }
        resubmit_gcash_payment($orderId, (int) current_user()['id'], $reference, $newProofPath);
        flash('order', 'Your new GCash reference and proof were submitted for review.', 'success');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        if ($newProofPath && function_exists('delete_uploaded_evidence')) {
            delete_uploaded_evidence($newProofPath);
        }
        flash('order', $exception->getMessage(), 'danger');
    }

    redirect('customer/order-detail.php?id=' . $orderId);
}

$items = fetch_order_items($orderId);
$transaction = fetch_transaction_by_order($orderId);
$delivery = db_find(
    'SELECT d.*, staff.full_name AS rider_name, staff.phone AS rider_phone
     FROM deliveries d
     LEFT JOIN users staff ON staff.id = d.assigned_staff_id
     WHERE d.order_id = ? LIMIT 1',
    'i',
    [$orderId]
);
$deliveryHistory = fetch_delivery_history($orderId);
$workflowRecord = array_merge($order, $delivery ?? []);
$workflowStatus = order_workflow_current_status($workflowRecord);
$workflowLabels = order_workflow_labels();
$trackingEvents = [['label' => 'Order placed', 'created_at' => $order['created_at'] ?? null]];
foreach ($deliveryHistory as $entry) {
    $eventStatus = match ((string) ($entry['new_status'] ?? '')) {
        'processing' => 'preparing',
        'assigned' => 'ready_for_delivery',
        default => (string) ($entry['new_status'] ?? ''),
    };
    $eventNote = (string) ($entry['note'] ?? '');
    $eventLabel = match (true) {
        $eventStatus === 'ready_for_delivery' && str_starts_with($eventNote, 'Assigned rider #') => 'Rider assigned',
        $eventStatus === 'ready_for_delivery' && str_starts_with($eventNote, 'Updated estimated delivery time to:') => 'Estimated delivery updated',
        default => $workflowLabels[$eventStatus] ?? 'Delivery updated',
    };
    $trackingEvents[] = ['label' => $eventLabel, 'created_at' => $entry['created_at'] ?? null];
}
$trackingMessage = match ($workflowStatus) {
    'pending' => 'Your order is waiting for confirmation.',
    'confirmed', 'preparing' => 'Your order is being prepared.',
    'ready_for_delivery' => empty($delivery['assigned_staff_id']) ? 'Your order is ready and waiting for a rider.' : 'A rider has been assigned to your order.',
    'out_for_delivery' => 'Your rider is on the way.',
    'delivered' => 'Your delivery has been completed.',
    'issue_reported', 'failed_delivery' => 'A delivery problem has been reported and needs review.',
    'cancelled' => 'This order was cancelled.',
    default => 'Check back for the latest delivery update.',
};
$pricing = order_pricing_summary($items, $order);
$receiptSettings = receipt_settings();
$receiptSettings['paper_width'] = '58mm';
$thermalPaperLocked = true;
$thermalOnly58mm = true;
$useQzReceipt = true;
$pageTitle = 'Order Details';
$bodyClass = 'customer-body customer-order-detail-page';
$pageStyles = ['css/customer-order-detail.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <?php require APP_ROOT . '/app/includes/thermal-receipt.php'; ?>
        <div class="receipt-card no-print receipt-supporting-details">
            <div class="receipt-card__header">
                <div>
                    <span class="eyebrow">Digital Receipt</span>
                    <h1><?= e($order['reference_no']) ?></h1>
                </div>
            </div>
            <div class="content-grid order-detail__overview">
                <div class="form-card">
                    <h2>Order Information</h2>
                    <p><strong>Order Status:</strong> <?= status_badge((string) $order['order_status']) ?></p>
                    <div><strong>Payment:</strong> <?= order_payment_summary(array_merge($order, $transaction ?? [])) ?></div>
                    <p><strong>Placed On:</strong> <?= e(format_display_datetime($order['created_at'] ?? null)) ?></p>
                    <p><strong>Total:</strong> <?= e(currency($pricing['total'])) ?></p>
                    <p><strong>VAT Inclusive:</strong> Included</p>
                </div>
                <div class="form-card order-detail__tracking">
                    <h2>Delivery Status Tracking</h2>
                    <div class="order-detail__tracking-status">
                        <span>Current status</span>
                        <?= order_workflow_badge($workflowRecord) ?>
                        <p><?= e($trackingMessage) ?></p>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('customer/order-detail.php?id=' . $orderId)) ?>">Refresh status</a>
                    </div>
                    <dl class="order-detail__tracking-facts">
                        <div><dt>Rider</dt><dd><?= e($delivery['rider_name'] ?? 'Not assigned') ?></dd></div>
                        <div><dt>Rider contact</dt><dd><?= e($delivery['rider_phone'] ?? 'Not available') ?></dd></div>
                        <div><dt>Estimated delivery</dt><dd><?= e(format_display_datetime($delivery['estimated_delivery_at'] ?? null, 'Not set')) ?></dd></div>
                        <?php if ($workflowStatus === 'delivered'): ?>
                            <div><dt>Delivered at</dt><dd><?= e(format_display_datetime($delivery['delivered_at'] ?? null, 'Not recorded')) ?></dd></div>
                            <div><dt>Recipient</dt><dd><?= e($delivery['recipient_name'] ?? 'Not recorded') ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Address</dt><dd><?= e($delivery['delivery_address'] ?? $order['customer_address']) ?></dd></div>
                        <?php if (!empty($delivery['delivery_landmark'])): ?>
                            <div><dt>Landmark</dt><dd><?= e((string) $delivery['delivery_landmark']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (in_array($workflowStatus, ['issue_reported', 'failed_delivery'], true) && !empty($delivery['issue_notes'])): ?>
                            <div><dt>Issue</dt><dd><?= e((string) $delivery['issue_notes']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($delivery['proof_photo_path'])): ?>
                            <div><dt>Proof</dt><dd><a href="<?= e(protected_upload_url('delivery_proof', (int) $delivery['id'])) ?>" target="_blank" rel="noopener noreferrer">View proof of delivery</a></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
            <div class="page-panel page-panel--spaced order-detail__timeline">
                <div class="card-head"><h2>Recorded Updates</h2></div>
                <ol class="activity-list order-detail__events">
                    <?php foreach ($trackingEvents as $event): ?>
                        <li><strong><?= e($event['label']) ?></strong><span><?= e(format_display_datetime($event['created_at'] ?? null, 'Time not recorded')) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="table-responsive order-detail__items">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td data-label="Product"><?= e($item['product_name']) ?></td>
                                <td data-label="Quantity"><?= e((string) $item['quantity']) ?></td>
                                <td data-label="Unit Price"><?= e(currency((float) $item['unit_price'])) ?></td>
                                <td data-label="Subtotal"><?= e(currency((float) $item['subtotal'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="summary-list" style="margin-top: 1rem;">
                <li><span>Subtotal</span><strong><?= e(currency($pricing['subtotal'])) ?></strong></li>
                <li><span>Bulk Discount (<?= e(rtrim(rtrim(number_format($pricing['discount_rate'], 2), '0'), '.')) ?>%)</span><strong>-<?= e(currency($pricing['discount_amount'])) ?></strong></li>
                <?php if ((float) ($pricing['promo_discount_amount'] ?? 0) > 0): ?><li><span>Promo Discount (<?= e((string) ($pricing['promo_code'] ?? '')) ?>)</span><strong>-<?= e(currency((float) $pricing['promo_discount_amount'])) ?></strong></li><?php endif; ?>
                <li><span>VATable Sales</span><strong><?= e(currency($pricing['vatable_sales'])) ?></strong></li>
                <?php if ((float) $pricing['service_fee'] > 0): ?><li><span>Service Fee</span><strong><?= e(currency($pricing['service_fee'])) ?></strong></li><?php endif; ?>
                <?php if ((float) $pricing['delivery_fee'] > 0): ?><li><span>Delivery Fee</span><strong><?= e(currency($pricing['delivery_fee'])) ?></strong></li><?php endif; ?>
                <li><span>VAT Amount (<?= e(rtrim(rtrim(number_format($pricing['tax_rate'], 2), '0'), '.')) ?>%)</span><strong><?= e(currency($pricing['vat_amount'])) ?></strong></li>
                <li><span>VAT Inclusive</span><strong>Included</strong></li>
            </div>
            <div class="summary-total">
                <span>Total</span>
                <strong><?= e(currency($pricing['total'])) ?></strong>
            </div>
            <div class="info-card">
                <h2>Transaction Status</h2>
                <?= order_payment_summary(array_merge($order, $transaction ?? [])) ?>
                <?php if ($transaction && ($transaction['payment_method'] ?? '') === 'gcash' && !empty($transaction['proof_of_payment_path'])): ?>
                    <p><a class="auth-link" href="<?= e(protected_upload_url('payment_proof', (int) $transaction['id'])) ?>" target="_blank" rel="noopener noreferrer">View submitted payment proof</a></p>
                <?php endif; ?>
                <?php if ($transaction && ($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'failed' && ($order['payment_status'] ?? '') === 'failed' && ($order['order_status'] ?? '') !== 'cancelled'): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong>Payment rejected:</strong>
                        <span><?= e((string) ($transaction['rejection_reason'] ?? 'Please submit a corrected reference number and proof.')) ?></span>
                    </div>
                    <form method="post" class="stack-form" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resubmit_gcash_payment">
                        <label>New GCash Reference Number
                            <input type="text" name="gcash_reference" minlength="4" maxlength="120" required>
                        </label>
                        <label>New Proof of Payment
                            <input type="file" name="proof_of_payment" accept="image/png,image/jpeg,image/gif,image/webp" required>
                        </label>
                        <button class="btn btn-primary" type="submit">Resubmit GCash Payment</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
