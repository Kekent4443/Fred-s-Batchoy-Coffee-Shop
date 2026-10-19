<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$orderId = (int) request('id');
$order = fetch_order_by_id($orderId);

if (!$order || (int) $order['customer_id'] !== (int) current_user()['id']) {
    redirect('customer/my-orders.php');
}

if (is_post() && request('action') === 'email_receipt') {
    $viewer = current_user();
    $rateKey = (int) $viewer['id'] . ':' . $orderId;
    if (!rate_limit_allow('email_receipt', $rateKey, 3, 60 * 60)) {
        flash('receipt', 'Too many receipt email requests. Please wait before trying again.', 'warning');
    } else {
        $receiptLink = url('customer/order-detail.php?id=' . $orderId);
        $message = 'Receipt for order ' . $order['reference_no'] . '. Total: ' . currency((float) $order['total_amount'])
            . '. Payment status: ' . ucwords((string) $order['payment_status']) . '. View your secure receipt: ' . $receiptLink;
        $htmlMessage = '<h2>Order Receipt ' . e((string) $order['reference_no']) . '</h2>'
            . '<p>Total: <strong>' . e(currency((float) $order['total_amount'])) . '</strong></p>'
            . '<p>Payment status: ' . e(ucwords((string) $order['payment_status'])) . '</p>'
            . '<p><a href="' . e($receiptLink) . '">View your secure itemized receipt</a></p>';
        try {
            if (!send_email_notification((int) $viewer['id'], (string) $viewer['email'], 'Receipt ' . $order['reference_no'], $message, $htmlMessage)) {
                throw new RuntimeException('Unable to queue receipt email.');
            }
            flash('receipt', 'Your receipt was queued for delivery to ' . mask_email((string) $viewer['email']) . '.', 'success');
        } catch (Throwable $exception) {
            error_log('Receipt email queue failed for order #' . $orderId . ': ' . $exception->getMessage());
            flash('receipt', 'Unable to email the receipt right now. You can still view or print it here.', 'danger');
        }
    }
    redirect('customer/order-confirmation.php?id=' . $orderId);
}

$items = fetch_order_items($orderId);
$transaction = fetch_transaction_by_order($orderId);
$delivery = db_find(
    'SELECT d.*, rider.full_name AS rider_name
     FROM deliveries d
     LEFT JOIN users rider ON rider.id = d.assigned_staff_id
     WHERE d.order_id = ? LIMIT 1',
    'i',
    [$orderId]
);
$pricing = order_pricing_summary($items, $order);
$receiptSettings = receipt_settings();
$receiptSettings['paper_width'] = '58mm';
$thermalPaperLocked = true;
$thermalOnly58mm = true;
$useQzReceipt = true;
$subtotal = $pricing['subtotal'];
$discountRate = $pricing['discount_rate'];
$discountAmount = $pricing['discount_amount'];
$vatableSales = $pricing['vatable_sales'];
$vatAmount = $pricing['vat_amount'];
$serviceFee = $pricing['service_fee'];
$deliveryFee = $pricing['delivery_fee'];
$taxRate = $pricing['tax_rate'];
$finalTotal = $pricing['total'];

$pageTitle = 'Order Confirmation';
$bodyClass = 'customer-body';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <div class="confirmation-hero">
            <h1 style="margin-bottom: 0.4rem;">Order Confirmed!</h1>
            <p>Thank you for your order. Your payment status will update after staff validation.</p>
            <strong>Order ID <?= e($order['reference_no']) ?></strong>
        </div>

        <div class="section__actions" style="margin: 1rem 0;">
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_receipt">
                <button class="btn btn-secondary" type="submit">Email Receipt</button>
            </form>
        </div>

        <?php require APP_ROOT . '/app/includes/thermal-receipt.php'; ?>

        <div class="receipt-card no-print receipt-supporting-details">
            <div class="receipt-card__header">
                <div>
                    <h2>Fred's</h2>
                    <p>Premium Food Service</p>
                </div>
                <div>
                    <strong>Receipt</strong>
                    <p><?= e($order['reference_no']) ?></p>
                </div>
            </div>

            <div class="content-grid">
                <div class="form-card">
                    <h3>Customer Information</h3>
                    <p><?= e($order['customer_name']) ?></p>
                    <p><?= e($order['customer_phone']) ?></p>
                </div>
                <div class="form-card">
                    <h3>Delivery Address</h3>
                    <p><?= e($delivery['delivery_address'] ?? $order['customer_address']) ?></p>
                    <?php if (!empty($delivery['delivery_landmark'])): ?>
                        <p><strong>Landmark:</strong> <?= e((string) $delivery['delivery_landmark']) ?></p>
                    <?php endif; ?>
                    <p>Note: <?= e($delivery['notes'] ?? $order['notes'] ?? 'No additional note') ?></p>
                </div>
            </div>

            <div class="form-card">
                <h3>Payment Information</h3>
                <div class="content-grid">
                    <div><strong>Payment</strong><br><?= order_payment_summary(array_merge($order, $transaction ?? [])) ?></div>
                    <p><strong>Order Status</strong><br><?= order_workflow_badge($order) ?></p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td>
                                <td><?= e((string) $item['quantity']) ?></td>
                                <td><?= e(currency((float) $item['unit_price'])) ?></td>
                                <td><?= e(currency((float) $item['subtotal'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="summary-list" style="margin-top: 1rem;">
                <li><span>Subtotal</span><strong><?= e(currency($subtotal)) ?></strong></li>
                <li><span>Bulk Discount (<?= e(rtrim(rtrim(number_format($discountRate, 2), '0'), '.')) ?>%)</span><strong>-<?= e(currency($discountAmount)) ?></strong></li>
                <li><span>VATable Sales</span><strong><?= e(currency($vatableSales)) ?></strong></li>
                <li><span>VAT Amount (<?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%)</span><strong><?= e(currency($vatAmount)) ?></strong></li>
                <li><span>VAT Inclusive</span><strong>Included</strong></li>
                <?php if ($serviceFee > 0): ?><li><span>Service Fee</span><strong><?= e(currency($serviceFee)) ?></strong></li><?php endif; ?>
                <?php if ($deliveryFee > 0): ?><li><span>Delivery Fee</span><strong><?= e(currency($deliveryFee)) ?></strong></li><?php endif; ?>
            </div>

            <div class="summary-total">
                <span>Total Paid</span>
                <strong><?= e(currency($finalTotal)) ?></strong>
            </div>

            <div class="info-card receipt-note">
                <strong>Thank you for choosing Fred's!</strong>
                <p>We hope you enjoy your meal. If you have any questions or concerns, please don't hesitate to contact our support team.</p>
            </div>

            <div class="section__actions">
                <a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Order Again</a>
                <a class="btn btn-secondary" href="<?= e(url('customer/my-orders.php')) ?>">View All Orders</a>
            </div>
        </div>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
