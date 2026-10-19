<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/config/app.php';
require_once APP_ROOT . '/app/config/database.php';
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/helpers/data.php';
require_once APP_ROOT . '/app/helpers/email_queue.php';
require_once APP_ROOT . '/app/config/mail.php';

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$declaredLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($declaredLength !== false && $declaredLength > BREVO_WEBHOOK_MAX_BYTES) {
    http_response_code(413);
    exit;
}

try {
    if (!rate_limit_allow('brevo_webhook', '', BREVO_WEBHOOK_REQUESTS_PER_MINUTE, 60)) {
        header('Retry-After: 60');
        http_response_code(429);
        exit;
    }
} catch (Throwable $exception) {
    error_log('Brevo webhook rate-limit check failed: ' . $exception->getMessage());
    http_response_code(503);
    exit;
}

$secret = mail_setting('BREVO_WEBHOOK_SECRET', 'webhook_secret', '');
$authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$provided = str_starts_with(strtolower($authorization), 'bearer ')
    ? trim(substr($authorization, 7))
    : trim((string) ($_SERVER['HTTP_X_BREVO_WEBHOOK_SECRET'] ?? ''));

if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    exit;
}

$rawPayload = file_get_contents('php://input', false, null, 0, BREVO_WEBHOOK_MAX_BYTES + 1);
if (!is_string($rawPayload) || strlen($rawPayload) > BREVO_WEBHOOK_MAX_BYTES) {
    http_response_code(413);
    exit;
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$events = array_is_list($payload) ? $payload : [$payload];
if (count($events) > 100) {
    http_response_code(413);
    exit;
}
$recorded = 0;
foreach ($events as $event) {
    if (is_array($event) && record_brevo_delivery_event($event)) {
        $recorded++;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['accepted' => true, 'recorded' => $recorded]);
