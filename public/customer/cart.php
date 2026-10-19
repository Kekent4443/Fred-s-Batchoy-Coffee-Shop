<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (is_post() && request('remove_product_id') !== null) {
    if (remove_cart_item((int) request('remove_product_id'))) {
        flash('cart', 'Item removed from your cart.', 'success');
    } else {
        flash('cart', 'That item is no longer in your cart.', 'warning');
    }

    redirect('customer/cart.php');
}

if (is_post() && in_array(request('action'), ['update_cart', 'checkout'], true)) {
    $proceedToCheckout = request('action') === 'checkout';
    $submittedQuantities = $_POST['quantities'] ?? null;

    if (!is_array($submittedQuantities) || $submittedQuantities === []) {
        flash('cart', 'Please provide a valid quantity for each cart item.', 'danger');
        redirect('customer/cart.php');
    }

    foreach ($submittedQuantities as $productId => $quantity) {
        $validProductId = filter_var($productId, FILTER_VALIDATE_INT);
        $validQuantity = is_scalar($quantity) ? filter_var($quantity, FILTER_VALIDATE_INT) : false;

        if ($validProductId === false || $validProductId < 1 || $validQuantity === false || $validQuantity < 1 || $validQuantity > 999) {
            flash('cart', 'Cart quantities must be whole numbers of at least 1.', 'danger');
            redirect('customer/cart.php');
        }
    }

    $messages = update_cart_quantities($submittedQuantities);

    if ($messages === [] && !$proceedToCheckout) {
        flash('cart', 'Shopping cart updated.', 'success');
    } elseif ($messages !== []) {
        flash_many($messages, 'warning', 'cart_stock');
    }

    redirect($proceedToCheckout ? 'customer/checkout.php' : 'customer/cart.php');
}

if (is_post() && request('action') === 'clear_cart') {
    clear_cart();
    flash('cart', 'Shopping cart cleared.', 'warning');
    redirect('customer/cart.php');
}

if (is_post() && request('action') === 'apply_promo_code') {
    try {
        sync_cart_with_inventory();
        $promoPricing = apply_cart_promo_code((string) request('promo_code', ''));
        flash('cart', 'Promo code ' . (string) $promoPricing['promo_code'] . ' applied successfully.', 'success');
    } catch (InvalidArgumentException $exception) {
        clear_cart_promo_code();
        flash('cart', $exception->getMessage(), 'danger');
    }
    redirect('customer/cart.php');
}

if (is_post() && request('action') === 'clear_promo_code') {
    clear_cart_promo_code();
    flash('cart', 'Promo code removed.', 'success');
    redirect('customer/cart.php');
}

$inventoryMessages = sync_cart_with_inventory();

if ($inventoryMessages !== []) {
    flash_many($inventoryMessages, 'warning', 'cart_sync');
}

$pageTitle = 'Shopping Cart';
$bodyClass = 'customer-body cart-page-body';
$items = cart_items();
try {
    $pricing = cart_pricing_summary_with_promo();
} catch (InvalidArgumentException $exception) {
    clear_cart_promo_code();
    $pricing = cart_pricing_summary_with_promo();
    flash('cart', $exception->getMessage(), 'warning');
}
$subtotal = $pricing['subtotal'];
$discountRate = $pricing['discount_rate'];
$discountAmount = $pricing['discount_amount'];
$vatableSales = $pricing['vatable_sales'];
$vatAmount = $pricing['vat_amount'];
$taxRate = $pricing['tax_rate'];
$serviceFee = $pricing['service_fee'];
$deliveryFee = $pricing['delivery_fee'];
$grandTotal = $pricing['total'];
$promoCode = (string) ($pricing['promo_code'] ?? '');
$promoDiscountAmount = (float) ($pricing['promo_discount_amount'] ?? 0);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <?php render_page_header('Shopping Cart', (string) cart_count() . ' item(s) in your cart.', 'orders', 'My Order'); ?>

        <?php if (!$items): ?>
            <div class="empty-state">
                <h2>Your cart is empty</h2>
                <p>Browse the menu to add products before checkout.</p>
                <a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Continue Shopping</a>
            </div>
        <?php else: ?>
            <form method="post" id="cart-quantity-form" class="cart-stack" data-cart-form data-cart-preview-url="<?= e(url('customer/cart-preview.php')) ?>">
                <?= csrf_field() ?>
                <div class="cart-items">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemSubtotal = ((float) $item['price']) * ((int) $item['quantity']);
                        $productImage = public_file_url(
                            $item['image_url'] ?? '',
                            asset('images/product-placeholder.svg')
                        );
                        $productId = (int) $item['product_id'];
                        $quantity = (int) $item['quantity'];
                        $availableStock = max(1, (int) ($item['stock_quantity'] ?? 1));
                        $quantityLabelId = 'cart-quantity-label-' . $productId;
                        ?>
                        <article class="cart-item" data-cart-item data-product-id="<?= e((string) $productId) ?>">
                            <div class="cart-item__media" style="background-image: url('<?= e($productImage) ?>');" role="img" aria-label="<?= e($item['name']) ?>"></div>
                            <div class="cart-item__details">
                                <h3><?= e($item['name']) ?></h3>
                                <span class="chip cart-item__stock" data-cart-stock-label><?= e('Available: ' . (string) $availableStock) ?></span>
                            </div>
                            <div class="cart-item__controls">
                                <div class="cart-quantity-field">
                                    <span class="cart-quantity-field__label" id="<?= e($quantityLabelId) ?>">Quantity</span>
                                    <div class="quantity-stepper" data-qty-stepper role="group" aria-labelledby="<?= e($quantityLabelId) ?>">
                                        <button class="quantity-stepper__button" type="button" data-qty-step="-1" aria-label="Decrease quantity"<?= $quantity <= 1 ? ' disabled' : '' ?>>
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/></svg>
                                        </button>
                                        <input
                                            class="qty-input quantity-stepper__input"
                                            type="number"
                                            min="1"
                                            max="<?= e((string) $availableStock) ?>"
                                            step="1"
                                            inputmode="numeric"
                                            name="quantities[<?= e((string) $productId) ?>]"
                                            value="<?= e((string) $quantity) ?>"
                                            data-persisted-value="<?= e((string) $quantity) ?>"
                                            aria-label="Quantity for <?= e($item['name']) ?>"
                                            required
                                        >
                                        <button class="quantity-stepper__button" type="button" data-qty-step="1" aria-label="Increase quantity"<?= $quantity >= $availableStock ? ' disabled' : '' ?>>
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="cart-item__summary">
                                <div class="cart-item__price">
                                    <small><span>Unit price</span><?= e(currency((float) $item['price'])) ?> each</small>
                                    <strong data-cart-item-subtotal><?= e(currency($itemSubtotal)) ?></strong>
                                </div>
                                <button class="btn btn-danger btn-sm cart-item__remove" type="submit" name="remove_product_id" value="<?= e((string) $productId) ?>" data-confirm-message="Remove this product from your cart?" data-confirm-title="Remove Product" data-confirm-label="Remove" data-confirm-variant="danger">
                                    <?= trash_icon_svg() ?>
                                    <span>Remove Item</span>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="section__actions cart-form-actions">
                    <button class="btn btn-secondary" type="submit" name="action" value="update_cart">
                        <svg class="cart-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 6v5h-5M4 18v-5h5M6.1 9a7 7 0 0 1 11.7-2.6L20 11M4 13l2.2 4.6A7 7 0 0 0 17.9 15"/></svg>
                        <span>Update Cart</span>
                    </button>
                    <a class="btn btn-ghost" href="<?= e(url('customer/catalog.php')) ?>">
                        <svg class="cart-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m14 6-6 6 6 6M8 12h11"/></svg>
                        <span>Continue Shopping</span>
                    </a>
                </div>
            </form>

            <div class="page-panel cart-summary-panel">
                <h2>Order Summary</h2>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_promo_code">
                    <label>Promo Code
                        <input type="text" name="promo_code" minlength="3" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}" placeholder="Enter code" value="<?= e($promoCode) ?>" required>
                    </label>
                    <button class="btn btn-secondary" type="submit"><?= $promoCode !== '' ? 'Reapply' : 'Apply' ?></button>
                </form>
                <?php if ($promoCode !== ''): ?>
                    <form method="post" class="inline-form" data-confirm-message="Clear every item from your shopping cart?" data-confirm-title="Clear Cart" data-confirm-label="Clear Cart" data-confirm-variant="danger" data-confirm-sensitive="true">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_promo_code">
                        <span class="badge badge-success"><?= e($promoCode) ?> applied</span>
                        <button class="auth-link" type="submit">Remove</button>
                    </form>
                <?php endif; ?>
                <div class="summary-list">
                    <li><span>Subtotal</span><strong data-cart-subtotal><?= e(currency($subtotal)) ?></strong></li>
                    <li><span>Bulk Discount (<span data-cart-discount-rate><?= e(rtrim(rtrim(number_format($discountRate, 2), '0'), '.')) ?></span>%)</span><strong data-cart-discount-amount>-<?= e(currency($discountAmount)) ?></strong></li>
                    <?php if ($promoCode !== ''): ?><li><span>Promo Discount (<?= e($promoCode) ?>)</span><strong data-cart-promo-discount>-<?= e(currency($promoDiscountAmount)) ?></strong></li><?php endif; ?>
                    <?php if ($serviceFee > 0): ?><li><span>Service Fee</span><strong><?= e(currency($serviceFee)) ?></strong></li><?php endif; ?>
                    <?php if ($deliveryFee > 0): ?><li><span>Delivery Fee</span><strong><?= e(currency($deliveryFee)) ?></strong></li><?php endif; ?>
                    <li><span>VATable Sales</span><strong data-cart-vatable-sales><?= e(currency($vatableSales)) ?></strong></li>
                    <li><span>VAT Amount (<span data-cart-tax-rate><?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?></span>%)</span><strong data-cart-vat-amount><?= e(currency($vatAmount)) ?></strong></li>
                    <li><span>VAT Inclusive</span><strong>Included</strong></li>
                </div>
                <p class="discount-application-note">If both apply, the bulk discount is taken first; the promo code applies once to the remaining item subtotal.</p>
                <div class="summary-total">
                    <span>Total</span>
                    <strong data-cart-grand-total><?= e(currency($grandTotal)) ?></strong>
                </div>
                <div class="section__actions">
                    <button class="btn btn-primary" type="submit" name="action" value="checkout" form="cart-quantity-form"><?= has_role('customer') ? 'Proceed to Checkout' : 'Sign In to Checkout' ?></button>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_cart">
                        <button class="btn btn-ghost" type="submit">Clear Cart</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
