<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!is_post()) {
    redirect('customer/catalog.php');
}

$returnTo = safe_public_return_path((string) request('return_to', ''), 'customer/catalog.php');

if (request('remove_product_id') !== null) {
    if (remove_cart_item((int) request('remove_product_id'))) {
        flash('cart', 'Item removed from your cart.', 'success');
    } else {
        flash('cart', 'That item is no longer in your cart.', 'warning');
    }

    redirect($returnTo);
}

if (request('action') === 'clear_cart') {
    clear_cart();
    flash('cart', 'Shopping cart cleared.', 'warning');
    redirect($returnTo);
}

$messages = update_cart_quantities($_POST['quantities'] ?? []);

if ($messages === []) {
    flash('cart', 'Shopping cart updated.', 'success');
} else {
    flash_many($messages, 'warning', 'cart_stock');
}

redirect($returnTo);
