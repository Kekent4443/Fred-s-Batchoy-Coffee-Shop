<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';

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
$delivery = db_find('SELECT * FROM deliveries WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
$pricing = order_pricing_summary($items, $order);
$settings = receipt_settings();
$settings['paper_width'] = '58mm';
$pdf = build_receipt_pdf($order, $items, $pricing, $settings, $transaction, $delivery);
$filename = 'receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $order['reference_no']) . '.pdf';
$disposition = request('view') === '1' ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($requestMethod !== 'HEAD') {
    echo $pdf;
}
exit;
