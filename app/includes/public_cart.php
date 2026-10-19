<?php

$guestCartId = $guestCartId ?? 'guest-cart';
$guestCartReturnTo = safe_public_return_path($guestCartReturnTo ?? current_public_path());
$guestCartItems = cart_items();
$guestCartCount = cart_count();
$guestCartPricing = cart_pricing_summary();
$guestCartTotal = (float) $guestCartPricing['total'];
$guestCartCheckoutLabel = has_role('customer') ? 'Checkout' : 'Sign In to Checkout';
?>
<div class="guest-cart" data-guest-cart>
    <button
        class="topbar-cart-link guest-cart__toggle"
        type="button"
        data-guest-cart-toggle
        aria-label="Open cart with <?= e((string) $guestCartCount) ?> item(s)"
        aria-expanded="false"
        aria-controls="<?= e($guestCartId) ?>"
        title="Open cart"
    >
        <?= cart_icon_svg() ?>
        <span class="customer-topbar__badge topbar-cart-link__badge guest-cart__badge" data-guest-cart-count><?= e((string) $guestCartCount) ?></span>
    </button>

    <div class="guest-cart__scrim" data-guest-cart-close hidden></div>

    <aside class="guest-cart__panel" id="<?= e($guestCartId) ?>" data-guest-cart-panel hidden>
        <div class="guest-cart__header">
            <div>
                <span class="eyebrow">Current Order</span>
                <h2>Cart</h2>
            </div>
            <button class="guest-cart__close" type="button" data-guest-cart-close aria-label="Close cart" title="Close cart">&times;</button>
        </div>

        <?php if ($guestCartItems === []): ?>
            <div class="guest-cart__empty">
                <h3>Your cart is empty</h3>
                <p>Add products from the menu before checkout.</p>
                <a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Browse Menu</a>
            </div>
        <?php else: ?>
            <form class="guest-cart__form" method="post" action="<?= e(url('cart-action.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_cart">
                <input type="hidden" name="return_to" value="<?= e($guestCartReturnTo) ?>">

                <div class="guest-cart__items">
                    <?php foreach ($guestCartItems as $guestCartItem): ?>
                        <?php
                        $guestCartItemId = (int) $guestCartItem['product_id'];
                        $guestCartItemQuantity = (int) $guestCartItem['quantity'];
                        $guestCartItemStock = (int) ($guestCartItem['stock_quantity'] ?? 0);
                        $guestCartItemPrice = (float) $guestCartItem['price'];
                        $guestCartItemSubtotal = $guestCartItemPrice * $guestCartItemQuantity;
                        $guestCartItemImage = public_file_url(
                            $guestCartItem['image_url'] ?? '',
                            asset('images/product-placeholder.svg')
                        );
                        ?>
                        <article class="guest-cart__item">
                            <div class="guest-cart__item-media" style="background-image: url('<?= e($guestCartItemImage) ?>');"></div>
                            <div class="guest-cart__item-main">
                                <h3><?= e((string) $guestCartItem['name']) ?></h3>
                                <small><?= e(currency($guestCartItemPrice)) ?> each</small>
                                <div class="guest-cart__qty" data-qty-stepper>
                                    <button type="button" data-qty-step="-1" aria-label="Decrease quantity">-</button>
                                    <input
                                        class="qty-input"
                                        type="number"
                                        min="0"
                                        max="<?= e((string) $guestCartItemStock) ?>"
                                        name="quantities[<?= e((string) $guestCartItemId) ?>]"
                                        value="<?= e((string) $guestCartItemQuantity) ?>"
                                        aria-label="Quantity for <?= e((string) $guestCartItem['name']) ?>"
                                    >
                                    <button type="button" data-qty-step="1" aria-label="Increase quantity">+</button>
                                </div>
                            </div>
                            <div class="guest-cart__item-summary">
                                <strong><?= e(currency($guestCartItemSubtotal)) ?></strong>
                                <button class="btn btn-danger btn-sm" type="submit" name="remove_product_id" value="<?= e((string) $guestCartItemId) ?>" data-confirm-message="Remove this product from your cart?" data-confirm-title="Remove Product" data-confirm-label="Remove" data-confirm-variant="danger">Remove</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="guest-cart__summary">
                    <span>Total</span>
                    <strong><?= e(currency($guestCartTotal)) ?></strong>
                </div>

                <div class="guest-cart__actions">
                    <button class="btn btn-secondary" type="submit">Update Cart</button>
                    <a class="btn btn-primary" href="<?= e(url('customer/checkout.php')) ?>"><?= e($guestCartCheckoutLabel) ?></a>
                </div>
            </form>
        <?php endif; ?>
    </aside>
</div>
<?php
unset(
    $guestCartId,
    $guestCartReturnTo,
    $guestCartItems,
    $guestCartCount,
    $guestCartPricing,
    $guestCartTotal,
    $guestCartCheckoutLabel
);
