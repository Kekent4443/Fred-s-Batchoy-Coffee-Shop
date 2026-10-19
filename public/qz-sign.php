<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/qz_receipt.php';

if (!is_post()) {
    header('Allow: POST');
    qz_receipt_json(405, ['success' => false, 'message' => 'This request is not supported.']);
}

$viewer = qz_receipt_viewer();
if ($viewer === null) {
    qz_receipt_json(403, ['success' => false, 'message' => 'Please sign in with a receipt-printing account.']);
}

$hash = post_scalar('hash');
$ticket = post_scalar('ticket');
$ticketKey = preg_match('/^[a-f0-9]{48}$/', $ticket) === 1 ? hash('sha256', $ticket) : '';
$authorization = $ticketKey !== '' ? ($_SESSION['qz_receipt_tickets'][$ticketKey] ?? null) : null;
if (preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1
    || !is_array($authorization)
    || (int) ($authorization['expires'] ?? 0) <= time()
    || (int) ($authorization['remaining'] ?? 0) <= 0
    || (int) ($authorization['viewer_id'] ?? 0) !== (int) $viewer['id']
    || (string) ($authorization['viewer_role'] ?? '') !== (string) $viewer['role']) {
    qz_receipt_json(403, ['success' => false, 'message' => 'Print authorization expired. Please try again.']);
}

if (!rate_limit_allow('qz_sign', (string) $viewer['id'], 80, 60)) {
    qz_receipt_json(429, ['success' => false, 'message' => 'Please wait before printing again.']);
}

$material = qz_signing_material();
if ($material === null) {
    qz_receipt_json(503, ['success' => false, 'message' => 'QZ Tray printing is not configured yet.']);
}

$_SESSION['qz_receipt_tickets'][$ticketKey]['remaining']--;
$signature = '';
if (!openssl_sign($hash, $signature, $material['key'], OPENSSL_ALGO_SHA512)) {
    error_log('QZ Tray message signing failed.');
    qz_receipt_json(500, ['success' => false, 'message' => 'Unable to authorize printing. Please try again.']);
}

prepare_json_response_output();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo base64_encode($signature);
