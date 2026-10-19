<?php

declare(strict_types=1);

$bootstrapJsonRequested = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$GLOBALS['app_json_buffer_base_level'] = ob_get_level();
if ($bootstrapJsonRequested) {
    // Keep warnings, notices, BOMs, and accidental debug output out of API
    // responses. JSON response helpers discard this buffer before emitting.
    ob_start();
}

require_once __DIR__ . '/config/app.php';

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG && !$bootstrapJsonRequested ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG && !$bootstrapJsonRequested ? '1' : '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

function prepare_json_response_output(): void
{
    $baseLevel = (int) ($GLOBALS['app_json_buffer_base_level'] ?? 0);
    while (ob_get_level() > $baseLevel) {
        ob_end_clean();
    }
}

if (!headers_sent()) {
    header_remove('X-Powered-By');
}

$bootstrapExpectsJson = static function () use ($bootstrapJsonRequested): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $path = strtolower((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));

    return $bootstrapJsonRequested
        || str_contains($accept, 'application/json')
        || $requestedWith === 'xmlhttprequest'
        || str_contains($path, '/support/')
        || str_contains($path, '/notification-');
};

$bootstrapRenderError = static function (Throwable $exception, int $status = 500) use ($bootstrapExpectsJson): void {
    // Exception messages may contain SQL values, addresses, or provider data.
    error_log('Uncaught application failure (' . get_class($exception) . ', code ' . (int) $exception->getCode() . ').');

    if (!headers_sent()) {
        http_response_code($status);
        header_remove('X-Powered-By');
        header('Cache-Control: no-store');
    }

    if ($bootstrapExpectsJson()) {
        prepare_json_response_output();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'The service is temporarily unavailable. Please try again shortly.']);
        return;
    }

    if (APP_DEBUG) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo (string) $exception;
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable</title></head><body><main style="max-width:42rem;margin:4rem auto;padding:1.5rem;font:16px/1.5 system-ui,sans-serif"><h1>Temporarily unavailable</h1><p>We are unable to reach the ordering service right now. Please try again shortly.</p></main></body></html>';
};

set_exception_handler(static function (Throwable $exception) use ($bootstrapRenderError): void {
    $bootstrapRenderError($exception, 500);
});

register_shutdown_function(static function () use ($bootstrapRenderError): void {
    $lastError = error_get_last();
    if (!$lastError || !in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $bootstrapRenderError(new ErrorException(
        (string) $lastError['message'],
        0,
        (int) $lastError['type'],
        (string) $lastError['file'],
        (int) $lastError['line']
    ), 500);
});

if (PHP_SAPI !== 'cli' && APP_FORCE_HTTPS && !app_request_is_https() && !headers_sent()) {
    $canonicalScheme = (string) (parse_url(APP_URL, PHP_URL_SCHEME) ?: 'https');
    $canonicalHost = (string) (parse_url(APP_URL, PHP_URL_HOST) ?: '');
    $canonicalPort = parse_url(APP_URL, PHP_URL_PORT);
    $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $requestQuery = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?: '');

    if ($canonicalScheme === 'https' && $canonicalHost !== '') {
        $canonicalOrigin = 'https://' . $canonicalHost . ($canonicalPort ? ':' . (int) $canonicalPort : '');
        header('Location: ' . $canonicalOrigin . '/' . ltrim($requestPath, '/') . ($requestQuery !== '' ? '?' . $requestQuery : ''), true, 307);
        exit;
    }
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', app_request_is_https() ? '1' : '0');
ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_TIMEOUT_SECONDS);
session_name(APP_SESSION_NAME);

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data: https:; frame-src https://www.google.com https://maps.google.com; connect-src 'self' wss://localhost:8181 wss://localhost:8282 wss://localhost:8383 wss://localhost:8484");
    header('Cache-Control: no-store, private');

    if (app_request_is_https() && !app_host_is_local((string) (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost'))) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_COOKIE_PATH,
        'secure' => app_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/includes/page-header.php';
require_once __DIR__ . '/helpers/protected_uploads.php';
require_once __DIR__ . '/helpers/flash.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auth_history.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/customer_address.php';
require_once __DIR__ . '/helpers/dashboard.php';
require_once __DIR__ . '/helpers/email_queue.php';
require_once __DIR__ . '/helpers/support.php';
require_once __DIR__ . '/helpers/location.php';
require_once __DIR__ . '/helpers/contact.php';
require_once __DIR__ . '/helpers/orders.php';
require_once __DIR__ . '/helpers/payment_settings.php';
require_once __DIR__ . '/helpers/receipt_settings.php';
require_once __DIR__ . '/helpers/branding.php';

try {
    // schema_maintenance_enabled() is false for every HTTP request and in production.
    if (schema_maintenance_enabled()) {
        ensure_auth_history_schema();
    }
    maintain_secure_session();
    enforce_post_rate_limit();
    verify_csrf();

    if (schema_maintenance_enabled() && !login_rate_limit_bypassed_for_request()) {
        ensure_rate_limit_schema();
        ensure_remember_tokens_table();
        ensure_email_queue_schema();
        ensure_database_schema();
        ensure_pricing_schema();
        ensure_customer_verification_schema();
        ensure_support_schema();
        ensure_shop_location_schema();
        ensure_contact_schema();
        ensure_gcash_account_schema();
        ensure_receipt_settings_schema();
        ensure_branding_schema();
        ensure_performance_indexes();

    }
} catch (Throwable $exception) {
    if (APP_DEBUG) {
        throw $exception;
    }

    $bootstrapRenderError($exception, 503);
    exit;
}

if (APP_ORDER_EXPIRY_MAINTENANCE_ENABLED && PHP_SAPI !== 'cli') {
    try {
        run_throttled_maintenance(
            'auto-cancel-expired-orders',
            60,
            static fn (): int => auto_cancel_expired_orders()
        );
    } catch (Throwable $exception) {
        // Maintenance must never take an otherwise healthy storefront down.
        error_log('Expired-order maintenance failed (' . get_class($exception) . ').');
    }
}

$emailQueueRoute = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$forceEmailQueue = is_post() && in_array($emailQueueRoute, ['forgot-password.php', 'customer-verifications.php'], true);
schedule_opportunistic_email_queue_processing($forceEmailQueue);
