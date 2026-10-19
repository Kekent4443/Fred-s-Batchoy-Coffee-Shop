<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';
require_once APP_ROOT . '/app/helpers/local_thermal_print.php';

if (!is_post()) {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!local_xp58_print_available()) {
    http_response_code(404);
    exit;
}

require_login(['customer', 'staff']);
$viewer = current_user();
$orderId = filter_var(post_scalar('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$order = $orderId > 0 ? fetch_order_by_id($orderId) : null;
if (!$viewer || !$order || ((string) $viewer['role'] === 'customer' && (int) $order['customer_id'] !== (int) $viewer['id'])) {
    http_response_code(404);
    exit;
}

$returnTo = (string) post_scalar('return_to', 'detail');
$returnPath = (string) $viewer['role'] === 'staff'
    ? 'staff/transactions.php'
    : ($returnTo === 'confirmation' ? 'customer/order-confirmation.php?id=' : 'customer/order-detail.php?id=') . $orderId;

if (post_scalar('paper_width', '58mm') !== '58mm') {
    flash('receipt', 'The local XP-58 uses 58mm paper. Select 58mm before printing.', 'warning');
    redirect($returnPath);
}

if (!rate_limit_allow('local_xp58_receipt', (string) $viewer['id'] . ':' . $orderId, 3, 60)) {
    flash('receipt', 'Please wait before printing this receipt again.', 'warning');
    redirect($returnPath);
}

// PHP's session lock serializes same-session requests. Avoid printing twice
// when a user double-clicks or the browser retries a completed POST.
$lastPrintAt = (int) ($_SESSION['local_xp58_last_print'][$orderId] ?? 0);
if ($lastPrintAt > 0 && time() - $lastPrintAt < 5) {
    flash('receipt', 'This receipt was just sent to the printer.', 'warning');
    redirect($returnPath);
}

try {
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
    $settings = receipt_settings();
    $settings['paper_width'] = '58mm';
    $textLines = build_receipt_text_lines($order, $items, $pricing, $settings, $transaction, $delivery);

    if (!is_array($textLines) || !local_xp58_spool_lines($textLines)) {
        throw new RuntimeException('Local receipt spool failed.');
    }
    $_SESSION['local_xp58_last_print'][$orderId] = time();
    flash('receipt', 'Receipt sent to the local XP-58 printer.', 'success');
} catch (Throwable $exception) {
    error_log('Local XP-58 receipt print failed (' . get_class($exception) . ').');
    flash('receipt', 'Unable to print on the local XP-58. Check the printer and try again.', 'danger');
}

redirect($returnPath);
