<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$orderId = filter_var(request('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$order = $orderId > 0 ? fetch_order_by_id($orderId) : null;
$viewer = current_user();

if (!$order || !$viewer || (int) $order['customer_id'] !== (int) $viewer['id']) {
    http_response_code(404);
    exit;
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
$paperWidth = '58mm';
$receiptSettings['paper_width'] = $paperWidth;
$thermalPaperLocked = true;
$thermalOnly58mm = true;
$thermalShowControls = false;
$thermalPrintAuto = true;
$thermalPrintBackUrl = url('customer/order-detail.php?id=' . $orderId);

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($requestMethod === 'HEAD') {
    exit;
}
?>
<?php require APP_ROOT . '/app/includes/thermal-print-document.php'; ?>
