<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/qz_receipt.php';

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    qz_receipt_json(405, ['success' => false, 'message' => 'This request is not supported.']);
}
if (qz_receipt_viewer() === null) {
    qz_receipt_json(403, ['success' => false, 'message' => 'Please sign in with a receipt-printing account.']);
}

$material = qz_signing_material();
if ($material === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    exit('QZ Tray signing is not configured.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
    echo $material['certificate'];
}
