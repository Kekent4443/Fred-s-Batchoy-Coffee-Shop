<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$checkout = $_SESSION['checkout'] ?? null;

if (!$checkout) {
    redirect('customer/checkout.php');
}

$inventoryMessages = sync_cart_with_inventory();

if ($inventoryMessages !== []) {
    flash_many($inventoryMessages, 'warning', 'payment_stock');
}

if (cart_items() === []) {
    flash('order', 'Your cart is empty. Please review your cart before payment.', 'warning');
    redirect('customer/cart.php');
}

try {
    $pricing = cart_pricing_summary_with_promo();
} catch (InvalidArgumentException $exception) {
    clear_cart_promo_code();
    flash('cart', $exception->getMessage(), 'warning');
    redirect('customer/cart.php');
}
$subtotal = $pricing['subtotal'];
$discountRate = $pricing['discount_rate'];
$discountAmount = $pricing['discount_amount'];
$vatableSales = $pricing['vatable_sales'];
$vatAmount = $pricing['vat_amount'];
$taxRate = $pricing['tax_rate'];
$serviceFee = $pricing['service_fee'];
$deliveryFee = $pricing['delivery_fee'];
$total = $pricing['total'];
$promoCode = (string) ($pricing['promo_code'] ?? '');
$promoDiscountAmount = (float) ($pricing['promo_discount_amount'] ?? 0);
$gcashAccount = active_gcash_account();
$gcashAvailable = $gcashAccount !== null;
$paymentMethods = ['cash', 'gcash'];
$selectedPaymentMethod = (string) request('payment_method', $checkout['payment_method'] ?? 'cash');
$selectedPaymentMethod = in_array($selectedPaymentMethod, $paymentMethods, true) ? $selectedPaymentMethod : 'cash';
$gcashUnavailableRequest = $selectedPaymentMethod === 'gcash' && !$gcashAvailable;
if (!is_post() && $gcashUnavailableRequest) {
    $selectedPaymentMethod = 'cash';
}

if (is_post()) {
    $newProofPath = null;
    try {
        $submittedCheckoutToken = (string) post_scalar('idempotency_key', '');
        $expectedCheckoutToken = (string) ($checkout['idempotency_key'] ?? '');
        if ($expectedCheckoutToken === '' || !hash_equals($expectedCheckoutToken, $submittedCheckoutToken)) {
            throw new InvalidArgumentException('This checkout form expired. Return to checkout and try again.');
        }

        if ($gcashUnavailableRequest) {
            throw new InvalidArgumentException('GCash payment is temporarily unavailable. Please select Cash or contact support.');
        }

        $checkout['payment_method'] = $selectedPaymentMethod;

        if ($selectedPaymentMethod === 'gcash') {
            if (!$gcashAccount) {
                throw new InvalidArgumentException('GCash payment is temporarily unavailable. Please select Cash or contact support.');
            }

            $shownAccountId = (int) post_scalar('gcash_account_id', 0);
            if ($shownAccountId <= 0 || $shownAccountId !== (int) $gcashAccount['id']) {
                throw new InvalidArgumentException('GCash payment details changed. Review the current recipient account before submitting your order.');
            }
            $checkout['gcash_account_id'] = $shownAccountId;

            $checkout['gcash_reference'] = trim((string) post_scalar('gcash_reference', ''));

            if ($checkout['gcash_reference'] === '') {
                throw new InvalidArgumentException('Please enter the GCash reference number.');
            }

            if (strlen($checkout['gcash_reference']) > 120 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{3,119}$/', $checkout['gcash_reference'])) {
                throw new InvalidArgumentException('Please enter a valid GCash reference number using 4 to 120 letters, numbers, spaces, dashes, underscores, or periods.');
            }

            $newProofPath = save_uploaded_payment_proof($_FILES['proof_of_payment'] ?? null);
            $checkout['proof_of_payment_path'] = $newProofPath;

            if (empty($checkout['proof_of_payment_path'])) {
                throw new InvalidArgumentException('Please upload a proof of payment picture or screenshot.');
            }
        } else {
            unset($checkout['gcash_reference'], $checkout['proof_of_payment_path']);
        }

        $orderId = create_order_from_cart((int) current_user()['id'], $checkout);

        if ($orderId) {
            unset($_SESSION['checkout']);
            unset($_SESSION['checkout_form_token'], $_SESSION['checkout_cart_fingerprint']);
            clear_cart_promo_code();
            flash('order', ucfirst($selectedPaymentMethod) . ' order placed successfully.', 'success');
            redirect('customer/order-confirmation.php?id=' . $orderId);
        }

        throw new InvalidArgumentException('The order could not be placed. Your cart was not charged or cleared; please review it and try again.');
    } catch (InvalidArgumentException $exception) {
        if ($newProofPath && function_exists('delete_uploaded_evidence')) {
            delete_uploaded_evidence($newProofPath);
        }
        flash('order', $exception->getMessage(), 'danger');
    }
}

$pageTitle = 'Payment';
$bodyClass = 'customer-body';
$pageScripts = ['js/checkout.js', 'js/payment-method.js'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <?php render_page_header('Payment', 'Select your payment method and complete your order.', 'wallet', 'My Order', static function (): void { ?><a class="auth-link" href="<?= e(url('customer/checkout.php')) ?>">Back to Checkout</a><?php }); ?>

        <form method="post" class="payment-stack" enctype="multipart/form-data" data-order-submission-form>
            <?= csrf_field() ?>
            <input type="hidden" name="idempotency_key" value="<?= e((string) ($checkout['idempotency_key'] ?? '')) ?>">
            <input type="hidden" name="gcash_account_id" value="<?= e((string) ($gcashAccount['id'] ?? 0)) ?>">
            <div class="page-panel payment-section">
                <h2>Select Payment Method</h2>
                <label>Payment Method
                    <select name="payment_method" id="payment_method" data-gcash-available="<?= $gcashAvailable ? '1' : '0' ?>" required>
                        <option value="cash" <?= $selectedPaymentMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="gcash" <?= $selectedPaymentMethod === 'gcash' ? 'selected' : '' ?> <?= $gcashAvailable ? '' : 'disabled' ?>>GCash<?= $gcashAvailable ? '' : ' (Unavailable)' ?></option>
                    </select>
                </label>

                <div class="form-card" data-payment-panel="cash">
                    <h2>Cash Information</h2>
                    <label>Amount
                        <input type="text" value="<?= e(currency($total)) ?>" disabled>
                    </label>
                    <label>Payment Status
                        <input type="text" value="Pending" disabled>
                    </label>
                    <div class="info-card">
                        <strong>Payment Note</strong>
                        <p>Cash payments are recorded as pending until validated by staff during release or delivery.</p>
                    </div>
                </div>

                <div class="form-card" data-payment-panel="gcash">
                    <h2>GCash Details</h2>
                    <?php if ($gcashAccount): ?>
                        <label>GCash Number
                            <input type="text" value="<?= e(format_gcash_number((string) $gcashAccount['account_number'])) ?>" disabled>
                        </label>
                        <label>Account Name
                            <input type="text" value="<?= e((string) $gcashAccount['account_name']) ?>" disabled>
                        </label>
                        <?php if (!empty($gcashAccount['qr_code_path'])): ?>
                            <div class="gcash-qr-preview gcash-qr-preview--checkout">
                                <img src="<?= e(public_file_url((string) $gcashAccount['qr_code_path'])) ?>" alt="GCash payment QR code">
                                <small>Scan this QR code using the GCash app.</small>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="info-card"><strong>GCash unavailable</strong><p>No active GCash recipient account is currently configured. Please select Cash.</p></div>
                    <?php endif; ?>
                    <label>Reference Number
                        <input type="text" name="gcash_reference" placeholder="Enter reference number" value="<?= e((string) request('gcash_reference', '')) ?>">
                    </label>
                    <label>Upload Proof of Payment
                        <input type="file" name="proof_of_payment" accept="image/png,image/jpeg,image/gif,image/webp">
                        <small>Accepted files: pictures or screenshots in JPG, PNG, GIF, or WEBP format.</small>
                    </label>
                    <div class="info-card">
                        <strong>Secure Payment</strong>
                        <p>Upload the payment screenshot before completing a GCash order.</p>
                    </div>
                </div>
            </div>

            <div class="page-panel payment-summary">
                <h2>Order Summary</h2>
                <div class="summary-list">
                    <li><span>Subtotal</span><strong><?= e(currency($subtotal)) ?></strong></li>
                    <li><span>Bulk Discount (<?= e(rtrim(rtrim(number_format($discountRate, 2), '0'), '.')) ?>%)</span><strong>-<?= e(currency($discountAmount)) ?></strong></li>
                    <?php if ($promoCode !== ''): ?><li><span>Promo Discount (<?= e($promoCode) ?>)</span><strong>-<?= e(currency($promoDiscountAmount)) ?></strong></li><?php endif; ?>
                    <?php if ($serviceFee > 0): ?><li><span>Service Fee</span><strong><?= e(currency($serviceFee)) ?></strong></li><?php endif; ?>
                    <?php if ($deliveryFee > 0): ?><li><span>Delivery Fee</span><strong><?= e(currency($deliveryFee)) ?></strong></li><?php endif; ?>
                    <li><span>VATable Sales</span><strong><?= e(currency($vatableSales)) ?></strong></li>
                    <li><span>VAT Amount (<?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%)</span><strong><?= e(currency($vatAmount)) ?></strong></li>
                    <li><span>VAT Inclusive</span><strong>Included</strong></li>
                </div>
                <div class="summary-total">
                    <span>Total</span>
                    <strong><?= e(currency($total)) ?></strong>
                </div>
                <button class="btn btn-primary" type="submit" data-order-submit>Complete Payment</button>
            </div>
        </form>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
