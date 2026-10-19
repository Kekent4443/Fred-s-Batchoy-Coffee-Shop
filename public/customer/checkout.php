<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!has_role('customer')) {
    $_SESSION['intended_customer_path'] = 'customer/checkout.php';
    flash('auth', 'Please sign in or create a customer account before checkout.', 'warning');
    redirect('auth/login.php');
}

$inventoryMessages = sync_cart_with_inventory();

if ($inventoryMessages !== []) {
    flash_many($inventoryMessages, 'warning', 'checkout_stock');
}

if (cart_items() === []) {
    flash('cart', 'Your cart is empty. Please add products before checkout.', 'warning');
    redirect('customer/catalog.php');
}

$user = current_user();
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
$checkoutCartFingerprint = hash('sha256', (string) json_encode(array_map(
    static fn (array $item): array => [
        'product_id' => (int) ($item['product_id'] ?? 0),
        'quantity' => (int) ($item['quantity'] ?? 0),
    ],
    cart_items()
), JSON_UNESCAPED_SLASHES));
if (empty($_SESSION['checkout_form_token'])
    || !hash_equals((string) ($_SESSION['checkout_cart_fingerprint'] ?? ''), $checkoutCartFingerprint)) {
    $_SESSION['checkout_form_token'] = bin2hex(random_bytes(24));
    $_SESSION['checkout_cart_fingerprint'] = $checkoutCartFingerprint;
}
$checkoutFormToken = (string) $_SESSION['checkout_form_token'];
$savedCheckout = is_array($_SESSION['checkout'] ?? null) ? $_SESSION['checkout'] : [];
$savedAddress = customer_saved_delivery_address($user);
$savedParts = !empty($savedCheckout['delivery_street'])
    ? customer_delivery_address_parts($savedCheckout)
    : $savedAddress['parts'];
$deliveryParts = customer_delivery_address_parts([
    'delivery_street' => request_string('delivery_street', $savedParts['street']),
    'delivery_barangay' => request_string('delivery_barangay', $savedParts['barangay']),
    'delivery_city' => request_string('delivery_city', $savedParts['city']),
    'delivery_province' => request_string('delivery_province', $savedParts['province']),
    'delivery_landmark' => request_string('delivery_landmark', $savedParts['landmark']),
]);
$notes = (string) request('notes', $savedCheckout['notes'] ?? '');
$saveInformation = !is_post() || request('save_information') === '1';
$checkoutErrors = [];
$legacyAddress = !is_post() ? $savedAddress['legacy_address'] : '';

if (is_post()) {
    $checkoutErrors = customer_delivery_address_errors($deliveryParts);
    $deliveryAddress = compose_customer_delivery_address($deliveryParts);
    $notes = trim((string) request('notes'));

    if ($checkoutErrors === [] && mb_strlen($deliveryAddress) < 20) {
        $checkoutErrors['delivery_street'] = 'Please enter a complete street-level address for delivery.';
    }

    if (mb_strlen($notes) > 1000) {
        $checkoutErrors['notes'] = 'Delivery Notes must be 1,000 characters or fewer.';
    }

    if ($checkoutErrors === []) {
        $_SESSION['checkout'] = [
            'delivery_address' => $deliveryAddress,
            'delivery_street' => $deliveryParts['street'],
            'delivery_barangay' => $deliveryParts['barangay'],
            'delivery_city' => $deliveryParts['city'],
            'delivery_province' => $deliveryParts['province'],
            'delivery_landmark' => $deliveryParts['landmark'],
            'notes' => $notes,
            'promo_code' => $promoCode,
            'idempotency_key' => $checkoutFormToken,
        ];

        if ($saveInformation) {
            $database = get_db();
            $database->begin_transaction();
            try {
                save_customer_delivery_address((int) $user['id'], $deliveryParts);
                log_audit((int) $user['id'], 'checkout_address_saved', 'Customer saved a structured default delivery address during checkout.');
                $database->commit();
            } catch (Throwable $exception) {
                $database->rollback();
                unset($_SESSION['checkout']);
                throw $exception;
            }
        }

        redirect('customer/payment.php');
    }
}

$pageTitle = 'Checkout';
$bodyClass = 'customer-body';
$pageStyles = ['css/checkout.css'];
$pageScripts = ['js/checkout.js'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <?php render_page_header('Checkout', 'Complete your order details.', 'receipt', 'My Order', static function (): void { ?><a class="auth-link" href="<?= e(url('customer/cart.php')) ?>">Back to Cart</a><?php }); ?>

        <form method="post" class="checkout-stack" data-checkout-form>
            <div class="page-panel checkout-section">
                <?= csrf_field() ?>
                <section class="checkout-form-section" aria-labelledby="checkout-contact-heading">
                    <div class="checkout-form-section__heading">
                        <h2 id="checkout-contact-heading">Contact Information</h2>
                        <p>Your account contact details will be used for this order.</p>
                    </div>
                    <div class="form-grid checkout-contact-grid">
                        <label class="form-grid__full checkout-field">Full Name
                            <input type="text" value="<?= e($user['full_name']) ?>" disabled>
                        </label>
                        <label class="checkout-field">Email Address
                            <input type="email" value="<?= e($user['email']) ?>" disabled>
                        </label>
                        <label class="checkout-field">Phone Number
                            <input type="text" value="<?= e($user['phone']) ?>" disabled>
                        </label>
                    </div>
                </section>

                <section class="checkout-form-section" aria-labelledby="checkout-address-heading">
                    <div class="checkout-form-section__heading">
                        <h2 id="checkout-address-heading">Delivery Address</h2>
                        <p>Enter each address part separately so your delivery location is clear and easy to verify.</p>
                    </div>

                    <?php if ($legacyAddress !== ''): ?>
                        <div class="checkout-legacy-address" role="status">
                            <strong>Please review your saved address</strong>
                            <p>It was saved as one line, so it was not guessed into separate fields. Confirm the street, barangay, city, and province below before continuing.</p>
                            <span><?= e($legacyAddress) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid checkout-address-grid">
                        <label class="form-grid__full checkout-field">Street Address
                            <input type="text" name="delivery_street" id="delivery_street" required maxlength="255" autocomplete="street-address" value="<?= e($deliveryParts['street']) ?>" placeholder="House / block / lot, street, purok, sitio, subdivision, or building"<?= form_error_attributes($checkoutErrors, 'delivery_street', 'checkout') ?>>
                            <small>Street-level details only. Do not repeat your barangay, city, or province here.</small>
                            <?= form_field_error($checkoutErrors, 'delivery_street', 'checkout') ?>
                        </label>
                        <label class="checkout-field">Barangay
                            <input type="text" name="delivery_barangay" id="delivery_barangay" required maxlength="120" autocomplete="address-level3" value="<?= e($deliveryParts['barangay']) ?>" placeholder="E.g., Bateria"<?= form_error_attributes($checkoutErrors, 'delivery_barangay', 'checkout') ?>>
                            <?= form_field_error($checkoutErrors, 'delivery_barangay', 'checkout') ?>
                        </label>
                        <label class="checkout-field">City / Municipality
                            <input type="text" name="delivery_city" id="delivery_city" required maxlength="120" autocomplete="address-level2" value="<?= e($deliveryParts['city']) ?>" placeholder="E.g., Guihulngan City"<?= form_error_attributes($checkoutErrors, 'delivery_city', 'checkout') ?>>
                            <?= form_field_error($checkoutErrors, 'delivery_city', 'checkout') ?>
                        </label>
                        <label class="checkout-field">Province
                            <input type="text" name="delivery_province" id="delivery_province" required maxlength="120" autocomplete="address-level1" value="<?= e($deliveryParts['province']) ?>" placeholder="E.g., Negros Oriental"<?= form_error_attributes($checkoutErrors, 'delivery_province', 'checkout') ?>>
                            <?= form_field_error($checkoutErrors, 'delivery_province', 'checkout') ?>
                        </label>
                        <label class="checkout-field">Landmark / Additional Address Info <span class="checkout-field__optional">Optional</span>
                            <input type="text" name="delivery_landmark" id="delivery_landmark" maxlength="255" autocomplete="off" value="<?= e($deliveryParts['landmark']) ?>" placeholder="E.g., Near the public market or blue gate beside the school"<?= form_error_attributes($checkoutErrors, 'delivery_landmark', 'checkout') ?>>
                            <small>Helpful location guidance only; it is kept separate from the formal address.</small>
                            <?= form_field_error($checkoutErrors, 'delivery_landmark', 'checkout') ?>
                        </label>

                        <section class="form-grid__full checkout-address-preview" aria-labelledby="delivery-address-preview-heading" data-delivery-address-preview aria-live="polite">
                            <div class="checkout-address-preview__heading">
                                <h3 id="delivery-address-preview-heading">Complete Delivery Address</h3>
                                <span>Review before continuing</span>
                            </div>
                            <p class="checkout-address-preview__address" data-delivery-address-preview-text><?= e(compose_customer_delivery_address($deliveryParts) ?: 'Complete the required address fields to preview your delivery address.') ?></p>
                            <p class="checkout-address-preview__landmark" data-delivery-landmark-preview<?= $deliveryParts['landmark'] === '' ? ' hidden' : '' ?>><strong>Landmark:</strong> <span data-delivery-landmark-preview-text><?= e($deliveryParts['landmark']) ?></span></p>
                            <small>Please review this address before continuing. This is the address that will be stored with the delivery.</small>
                        </section>

                        <label class="form-grid__full checkout-field">Delivery Notes <span class="checkout-field__optional">Optional</span>
                            <textarea name="notes" rows="4" maxlength="1000" placeholder="E.g., ring the doorbell twice, leave at the front door, or call when you arrive"<?= form_error_attributes($checkoutErrors, 'notes', 'checkout') ?>><?= e($notes) ?></textarea>
                            <small>Order instructions are separate from your delivery address.</small>
                            <?= form_field_error($checkoutErrors, 'notes', 'checkout') ?>
                        </label>
                    </div>
                    <label class="check-row checkout-save-address"><input type="checkbox" name="save_information" value="1" <?= $saveInformation ? 'checked' : '' ?>><span>Save this delivery address for next time</span></label>
                </section>
            </div>

            <div class="page-panel checkout-summary">
                <h2>Order Summary</h2>
                <ul class="summary-list">
                    <?php foreach (cart_items() as $item): ?>
                        <li>
                            <span><?= e($item['name']) ?> <small>Qty: <?= e((string) $item['quantity']) ?></small></span>
                            <strong><?= e(currency($item['price'] * $item['quantity'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="summary-list" style="margin-top: 1rem;">
                    <li><span>Subtotal</span><strong><?= e(currency($subtotal)) ?></strong></li>
                    <li><span>Bulk Discount (<?= e(rtrim(rtrim(number_format($discountRate, 2), '0'), '.')) ?>%)</span><strong>-<?= e(currency($discountAmount)) ?></strong></li>
                    <?php if ($promoCode !== ''): ?><li><span>Promo Discount (<?= e($promoCode) ?>)</span><strong>-<?= e(currency($promoDiscountAmount)) ?></strong></li><?php endif; ?>
                    <?php if ($serviceFee > 0): ?><li><span>Service Fee</span><strong><?= e(currency($serviceFee)) ?></strong></li><?php endif; ?>
                    <?php if ($deliveryFee > 0): ?><li><span>Delivery Fee</span><strong><?= e(currency($deliveryFee)) ?></strong></li><?php endif; ?>
                    <li><span>VATable Sales</span><strong><?= e(currency($vatableSales)) ?></strong></li>
                    <li><span>VAT Amount (<?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%)</span><strong><?= e(currency($vatAmount)) ?></strong></li>
                    <li><span>VAT Inclusive</span><strong>Included</strong></li>
                </div>
                <p class="discount-application-note">If both apply, the bulk discount is taken first; the promo code applies once to the remaining item subtotal.</p>
                <div class="summary-total">
                    <span>Total</span>
                    <strong><?= e(currency($total)) ?></strong>
                </div>
                <button class="btn btn-primary checkout-submit" type="submit" data-checkout-submit>Continue to Payment</button>
            </div>
        </form>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
