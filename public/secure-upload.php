<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login();

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$viewer = current_user();
enforce_request_rate_limit('secure_upload_view', (string) ($viewer['id'] ?? 0), 120, 60);
$type = (string) request('type', '');
$recordId = filter_var(request('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$storedPath = '';
$authorized = false;

if ($viewer && $recordId > 0 && $type === 'valid_id') {
    $record = db_find('SELECT id, valid_id_path FROM users WHERE id = ? AND role = "customer" LIMIT 1', 'i', [$recordId]);
    $authorized = $record !== null && (
        can_manage_customer_verifications($viewer)
        || ((string) $viewer['role'] === 'customer' && (int) $viewer['id'] === (int) $record['id'])
    );
    $storedPath = (string) ($record['valid_id_path'] ?? '');
} elseif ($viewer && $recordId > 0 && $type === 'payment_proof') {
    $record = db_find(
        'SELECT t.id, t.proof_of_payment_path, o.customer_id
         FROM transactions t
         INNER JOIN orders o ON o.id = t.order_id
         WHERE t.id = ? LIMIT 1',
        'i',
        [$recordId]
    );
    $authorized = $record !== null && (
        in_array((string) $viewer['role'], ['administrator', 'manager', 'staff'], true)
        || ((string) $viewer['role'] === 'customer' && (int) $viewer['id'] === (int) $record['customer_id'])
    );
    $storedPath = (string) ($record['proof_of_payment_path'] ?? '');
} elseif ($viewer && $recordId > 0 && $type === 'delivery_proof') {
    $record = db_find(
        'SELECT d.id, d.proof_photo_path, d.assigned_staff_id, o.customer_id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         WHERE d.id = ? LIMIT 1',
        'i',
        [$recordId]
    );
    $authorized = $record !== null && (
        in_array((string) $viewer['role'], ['administrator', 'manager', 'staff'], true)
        || ((string) $viewer['role'] === 'customer' && (int) $viewer['id'] === (int) $record['customer_id'])
        || ((string) $viewer['role'] === 'rider' && (int) $viewer['id'] === (int) $record['assigned_staff_id'])
    );
    $storedPath = (string) ($record['proof_photo_path'] ?? '');
}

$file = $authorized ? resolve_uploaded_evidence_path($storedPath, $type) : null;

if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($file);
$allowedMimes = $type === 'valid_id'
    ? ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']
    : ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unsupported file type.';
    exit;
}

if ($type === 'valid_id' && can_manage_customer_verifications($viewer)) {
    log_audit((int) $viewer['id'], 'secure_valid_id_viewed', 'Viewed secure valid ID for customer #' . $recordId . '.');
}

$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file)) ?: 'evidence-file';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
if ($requestMethod !== 'HEAD') {
    readfile($file);
}
exit;
