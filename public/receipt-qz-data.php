<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';
require_once APP_ROOT . '/app/helpers/qz_receipt.php';

if (!is_post()) {
    header('Allow: POST');
    qz_receipt_json(405, ['success' => false, 'message' => 'This request is not supported.']);
}

$viewer = qz_receipt_viewer();
if ($viewer === null) {
    qz_receipt_json(403, ['success' => false, 'message' => 'Please sign in with a receipt-printing account.']);
}

$orderId = filter_var(post_scalar('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$order = $orderId > 0 ? fetch_order_by_id($orderId) : null;
if (!$order || ((string) $viewer['role'] === 'customer' && (int) $order['customer_id'] !== (int) $viewer['id'])) {
    qz_receipt_json(404, ['success' => false, 'message' => 'Receipt not found.']);
}

if (qz_signing_material() === null) {
    qz_receipt_json(503, ['success' => false, 'message' => 'QZ Tray printing is not configured yet. Please contact the administrator.']);
}

if (!rate_limit_allow('qz_receipt', (string) $viewer['id'] . ':' . $orderId, 12, 60)) {
    qz_receipt_json(429, ['success' => false, 'message' => 'Please wait before printing this receipt again.']);
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
    $lines = build_receipt_text_lines($order, $items, $pricing, $settings, $transaction, $delivery);
    $payload = qz_receipt_payload($lines);
    if ($payload === null) {
        throw new RuntimeException('The receipt exceeds the XP-58 raw print limits.');
    }

    $ticket = bin2hex(random_bytes(24));
    $now = time();
    $_SESSION['qz_receipt_tickets'] = array_filter(
        (array) ($_SESSION['qz_receipt_tickets'] ?? []),
        static fn (array $entry): bool => (int) ($entry['expires'] ?? 0) > $now
    );
    $_SESSION['qz_receipt_tickets'][hash('sha256', $ticket)] = [
        'expires' => $now + 120,
        'remaining' => 8,
        'viewer_id' => (int) $viewer['id'],
        'viewer_role' => (string) $viewer['role'],
    ];
    if (count($_SESSION['qz_receipt_tickets']) > 5) {
        $_SESSION['qz_receipt_tickets'] = array_slice($_SESSION['qz_receipt_tickets'], -5, null, true);
    }

    qz_receipt_json(200, [
        'success' => true,
        'printer' => 'Xprinter XP-58',
        'paper' => '58mm',
        'data' => $payload,
        'ticket' => $ticket,
    ]);
} catch (Throwable $exception) {
    error_log('QZ receipt preparation failed (' . get_class($exception) . ').');
    qz_receipt_json(500, ['success' => false, 'message' => 'Unable to prepare the receipt. Please try again.']);
}
