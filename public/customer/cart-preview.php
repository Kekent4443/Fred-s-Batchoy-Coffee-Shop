<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$cart = cart_items();
$submittedQuantities = $_POST['quantities'] ?? [];

if (!is_array($submittedQuantities) || $cart === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The cart is empty or the quantities are invalid.']);
    exit;
}

$normalizedQuantities = [];
foreach ($submittedQuantities as $productId => $rawQuantity) {
    $productId = filter_var($productId, FILTER_VALIDATE_INT);
    $quantity = is_scalar($rawQuantity)
        ? filter_var($rawQuantity, FILTER_VALIDATE_INT)
        : false;

    if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > 999) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'One or more cart quantities are invalid.']);
        exit;
    }

    $normalizedQuantities[$productId] = $quantity;
}

if ($normalizedQuantities === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid cart quantity.']);
    exit;
}

$messages = update_cart_quantities($normalizedQuantities);
$messages = array_values(array_unique(array_merge($messages, sync_cart_with_inventory())));
$updatedCart = cart_items();
$removedProductIds = array_values(array_diff(array_map('intval', array_keys($cart)), array_map('intval', array_keys($updatedCart))));

if ($updatedCart === []) {
    echo json_encode([
        'success' => true,
        'cart_empty' => true,
        'removed_product_ids' => $removedProductIds,
        'messages' => $messages,
        'items' => [],
    ]);
    exit;
}

try {
    $pricing = cart_pricing_summary_with_promo();
} catch (InvalidArgumentException $exception) {
    clear_cart_promo_code();
    $pricing = cart_pricing_summary_with_promo();
    $messages[] = $exception->getMessage();
}

$itemSummaries = [];
foreach ($updatedCart as $productId => $item) {
    $itemSubtotal = round((float) $item['price'] * (int) $item['quantity'], 2);
    $itemSummaries[(string) $productId] = [
        'quantity' => (int) $item['quantity'],
        'max_quantity' => (int) ($item['stock_quantity'] ?? 0),
        'subtotal' => $itemSubtotal,
        'subtotal_label' => currency($itemSubtotal),
    ];
}

$pricing['subtotal_label'] = currency($pricing['subtotal']);
$pricing['discount_amount_label'] = currency($pricing['discount_amount']);
$pricing['promo_discount_amount_label'] = currency($pricing['promo_discount_amount']);
$pricing['vatable_sales_label'] = currency($pricing['vatable_sales']);
$pricing['vat_amount_label'] = currency($pricing['vat_amount']);
$pricing['service_fee_label'] = currency($pricing['service_fee']);
$pricing['delivery_fee_label'] = currency($pricing['delivery_fee']);
$pricing['total_label'] = currency($pricing['total']);

echo json_encode([
    'success' => true,
    'cart_empty' => false,
    'removed_product_ids' => $removedProductIds,
    'messages' => array_values(array_unique($messages)),
    'items' => $itemSummaries,
    'pricing' => $pricing,
]);
exit;
