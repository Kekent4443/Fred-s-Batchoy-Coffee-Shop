<?php

declare(strict_types=1);

function app_environment_boolean(string $name, bool $default = false): bool
{
    $value = getenv($name);

    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function app_sanitized_host(string $value): string
{
    $value = trim($value);

    if ($value === '' || strpbrk($value, "\r\n\\/@") !== false) {
        return '';
    }

    if (preg_match('/^\[[0-9a-f:]+\](?::([0-9]{1,5}))?$/i', $value, $matches)) {
        return empty($matches[1]) || (int) $matches[1] <= 65535 ? strtolower($value) : '';
    }

    if (!preg_match('/^([a-z0-9.-]+)(?::([0-9]{1,5}))?$/i', $value, $matches)) {
        return '';
    }

    $hostname = trim((string) $matches[1], '.');
    if ($hostname === '' || str_contains($hostname, '..') || (!empty($matches[2]) && (int) $matches[2] > 65535)) {
        return '';
    }

    return strtolower($hostname . (!empty($matches[2]) ? ':' . $matches[2] : ''));
}

function app_host_is_local(string $host): bool
{
    $hostname = (string) (parse_url('http://' . $host, PHP_URL_HOST) ?: $host);
    $hostname = strtolower(trim($hostname, '[]'));

    if ($hostname === 'localhost' || str_ends_with($hostname, '.test') || str_ends_with($hostname, '.local')) {
        return true;
    }

    if (filter_var($hostname, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return filter_var(
        $hostname,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function app_trusted_proxy_entries(): array
{
    static $entries = null;

    if (is_array($entries)) {
        return $entries;
    }

    $configured = (string) (getenv('APP_TRUSTED_PROXIES') ?: getenv('TRUSTED_PROXY_IPS') ?: '');
    $entries = array_values(array_filter(array_map('trim', explode(',', $configured))));

    return $entries;
}

function app_ip_matches_proxy_entry(string $ip, string $entry): bool
{
    if (!str_contains($entry, '/')) {
        $packedIp = @inet_pton($ip);
        $packedEntry = @inet_pton($entry);

        return $packedIp !== false
            && $packedEntry !== false
            && strlen($packedIp) === strlen($packedEntry)
            && hash_equals($packedEntry, $packedIp);
    }

    [$network, $prefixText] = explode('/', $entry, 2);
    $packedIp = @inet_pton($ip);
    $packedNetwork = @inet_pton($network);
    $prefix = filter_var($prefixText, FILTER_VALIDATE_INT);

    if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork) || $prefix === false) {
        return false;
    }

    $maximumBits = strlen($packedIp) * 8;
    if ($prefix < 0 || $prefix > $maximumBits) {
        return false;
    }

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;

    return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
}

function app_request_uses_trusted_proxy(): bool
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach (app_trusted_proxy_entries() as $entry) {
        if (app_ip_matches_proxy_entry($remoteAddress, $entry)) {
            return true;
        }
    }

    return false;
}

function app_trusted_forwarded_value(string $serverKey): string
{
    if (!app_request_uses_trusted_proxy()) {
        return '';
    }

    return strtolower(trim(explode(',', (string) ($_SERVER[$serverKey] ?? ''))[0] ?? ''));
}

function app_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return app_trusted_forwarded_value('HTTP_X_FORWARDED_PROTO') === 'https';
}

function schema_maintenance_enabled(): bool
{
    return PHP_SAPI === 'cli' && defined('APP_ENV') && APP_ENV !== 'production'
        && defined('APP_AUTO_MIGRATE') && APP_AUTO_MIGRATE;
}

define('APP_NAME', getenv('APP_NAME') ?: 'Fred\'s Multi-Product Sales Ordering System');
define('APP_VERSION', '1.0.0');
define('APP_ROOT', dirname(__DIR__, 2));
define('PUBLIC_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'public');
define('ASSET_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'assets');
define('UPLOAD_ROOT', PUBLIC_ROOT . DIRECTORY_SEPARATOR . 'uploads');
define('PRIVATE_UPLOAD_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'uploads');

$httpHost = app_sanitized_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
$serverHost = app_sanitized_host((string) ($_SERVER['SERVER_NAME'] ?? ''));
$host = $httpHost !== '' && app_host_is_local($httpHost)
    ? $httpHost
    : ($serverHost !== '' ? $serverHost : ($httpHost !== '' ? $httpHost : 'localhost'));
$scheme = app_request_is_https() || !app_host_is_local($host) ? 'https' : 'http';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/public/index.php');
$publicMarker = '/public/';
$publicPosition = strpos($scriptName, $publicMarker);

if ($publicPosition !== false) {
    $basePath = substr($scriptName, 0, $publicPosition + strlen('/public'));
} elseif (($privatePosition = strpos($scriptName, '/private/')) !== false) {
    $basePath = substr($scriptName, 0, $privatePosition) . '/public';
} else {
    $basePath = rtrim(dirname($scriptName), '/');
}

$configuredAppUrl = trim((string) (getenv('APP_URL') ?: ''));
$configuredParts = $configuredAppUrl !== '' ? parse_url($configuredAppUrl) : false;
$configuredScheme = strtolower((string) ($configuredParts['scheme'] ?? ''));
$configuredHost = (string) ($configuredParts['host'] ?? '');
$configuredHostWithPort = $configuredHost . (!empty($configuredParts['port']) ? ':' . (int) $configuredParts['port'] : '');
$validConfiguredUrl = is_array($configuredParts)
    && in_array($configuredScheme, ['http', 'https'], true)
    && $configuredHost !== ''
    && app_sanitized_host($configuredHostWithPort) !== ''
    && empty($configuredParts['user'])
    && empty($configuredParts['pass'])
    && !isset($configuredParts['query'])
    && !isset($configuredParts['fragment']);
$resolvedAppUrl = $validConfiguredUrl ? rtrim($configuredAppUrl, '/') : $scheme . '://' . $host . $basePath;

define('APP_URL', $resolvedAppUrl);
define('APP_TIMEZONE', 'Asia/Manila');
$configuredEnvironment = trim((string) (getenv('APP_ENV') ?: ''));
// A loopback-only development request may use local defaults. Both PHP's
// built-in server and local Laragon Apache need this; a public host, remote
// client, CLI command, or explicit APP_ENV still defaults/stays production.
$loopbackHosts = ['localhost', '127.0.0.1', '::1'];
$requestHost = strtolower((string) (parse_url('http://' . $httpHost, PHP_URL_HOST) ?: ''));
$serverName = strtolower(trim((string) ($_SERVER['SERVER_NAME'] ?? ''), '[]'));
$remoteAddress = strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$implicitLocal = in_array(PHP_SAPI, ['cli-server', 'apache2handler'], true)
    && in_array($requestHost, $loopbackHosts, true)
    && in_array($serverName, $loopbackHosts, true)
    && in_array($remoteAddress, $loopbackHosts, true);
$environment = strtolower($configuredEnvironment !== '' ? $configuredEnvironment : ($implicitLocal ? 'local' : 'production'));
if (!in_array($environment, ['production', 'staging', 'development', 'testing', 'local'], true)) {
    $environment = 'production';
}
define('APP_ENV', $environment);

$debugRequested = app_environment_boolean('APP_DEBUG', false);
define('APP_DEBUG', APP_ENV !== 'production' && $debugRequested);
define('DEV_TOOLS_ENABLED', APP_ENV !== 'production' && app_environment_boolean('DEV_TOOLS_ENABLED', false));
define('API_DOCS_ENABLED', APP_ENV !== 'production' && app_environment_boolean('API_DOCS_ENABLED', false));

$testingEnvironment = in_array(APP_ENV, ['staging', 'testing', 'development', 'local'], true);
define('TESTING_MODE', $testingEnvironment && app_environment_boolean('TESTING_MODE', false));
// Keep the former bypass flag as a configuration fallback, but never disable
// rate limiting completely for tester accounts.
define('TESTER_AUTH_RATE_LIMITS_ENABLED', TESTING_MODE && app_environment_boolean(
    'TESTER_AUTH_RATE_LIMITS_ENABLED',
    app_environment_boolean('TESTER_RATE_LIMIT_BYPASS', false)
));
define('TESTER_LOGIN_RATE_LIMIT_MAX', min(1000, max(100, (int) (getenv('TESTER_LOGIN_RATE_LIMIT_MAX') ?: 300))));
define('TESTER_AUTH_POST_LIMIT', min(1000, max(120, (int) (getenv('TESTER_AUTH_POST_LIMIT') ?: 300))));
$configuredTesterEmails = preg_split('/[\s,;]+/', strtolower(trim((string) (getenv('TESTER_EMAILS') ?: '')))) ?: [];
$configuredTesterEmails = array_values(array_unique(array_filter(
    $configuredTesterEmails,
    static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
)));
define('TESTER_EMAILS', $configuredTesterEmails);

// Never migrate during a web request, even if a non-production environment
// flag is accidentally deployed. Local/test CLI may opt in explicitly.
define('APP_AUTO_MIGRATE', APP_ENV !== 'production' && PHP_SAPI === 'cli'
    && app_environment_boolean('APP_AUTO_MIGRATE', false));
$loginRateLimitValue = getenv('LOGIN_RATE_LIMIT_ENABLED');
$loginRateLimitRequested = $loginRateLimitValue === false
    || !in_array(strtolower(trim((string) $loginRateLimitValue)), ['0', 'false', 'off', 'no'], true);
$globalLoginBypassAllowed = in_array(APP_ENV, ['development', 'local'], true);
define(
    'LOGIN_RATE_LIMIT_ENABLED',
    $globalLoginBypassAllowed ? $loginRateLimitRequested : true
);
define('LOGIN_ACCOUNT_LOCK_THRESHOLD', max(5, (int) (getenv('LOGIN_ACCOUNT_LOCK_THRESHOLD') ?: 5)));
define('LOGIN_ACCOUNT_LOCK_SECONDS', max(60, (int) (getenv('LOGIN_ACCOUNT_LOCK_SECONDS') ?: 300)));
define('ONLINE_WINDOW_SECONDS', max(300, (int) (getenv('ONLINE_WINDOW_SECONDS') ?: 600)));
define('AUTH_ACTIVITY_SYNC_SECONDS', max(15, (int) (getenv('AUTH_ACTIVITY_SYNC_SECONDS') ?: 60)));

$appUrlHost = (string) (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost');
$appUrlScheme = strtolower((string) (parse_url(APP_URL, PHP_URL_SCHEME) ?: 'http'));
define('APP_FORCE_HTTPS', app_environment_boolean('APP_FORCE_HTTPS', $appUrlScheme === 'https' && !app_host_is_local($appUrlHost)));

$appUrlPath = '/' . trim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');
$cookiePath = preg_replace('#/public/?$#i', '', $appUrlPath) ?: '/';
define('APP_COOKIE_PATH', $cookiePath === '' ? '/' : $cookiePath);
$configuredSessionName = trim((string) (getenv('APP_SESSION_NAME') ?: ''));
define(
    'APP_SESSION_NAME',
    preg_match('/^[A-Za-z][A-Za-z0-9]{0,31}$/', $configuredSessionName)
        ? $configuredSessionName
        : 'FREDS' . strtoupper(substr(hash('sha256', $appUrlHost . '|' . APP_COOKIE_PATH), 0, 12))
);

$sessionIdleTimeout = (int) (getenv('SESSION_IDLE_TIMEOUT_SECONDS') ?: 7200);
define('SESSION_IDLE_TIMEOUT_SECONDS', max(300, $sessionIdleTimeout));
$sessionAbsoluteTimeout = (int) (getenv('SESSION_ABSOLUTE_TIMEOUT_SECONDS') ?: 43200);
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', max(SESSION_IDLE_TIMEOUT_SECONDS, $sessionAbsoluteTimeout));

$sessionRegenerateInterval = (int) (getenv('SESSION_REGENERATE_INTERVAL_SECONDS') ?: 900);
define('SESSION_REGENERATE_INTERVAL_SECONDS', max(300, $sessionRegenerateInterval));

define('APP_WEB_EMAIL_QUEUE_ENABLED', app_environment_boolean('APP_WEB_EMAIL_QUEUE_ENABLED', true));
$webEmailQueueInterval = (int) (getenv('APP_WEB_EMAIL_QUEUE_INTERVAL_SECONDS') ?: 15);
define('APP_WEB_EMAIL_QUEUE_INTERVAL_SECONDS', max(5, $webEmailQueueInterval));

$passwordResetExpiry = (int) (getenv('PASSWORD_RESET_EXPIRY_MINUTES') ?: 5);
define('PASSWORD_RESET_EXPIRY_MINUTES', min(60, max(5, $passwordResetExpiry)));

$passwordResetCooldown = (int) (getenv('PASSWORD_RESET_COOLDOWN_SECONDS') ?: 60);
define('PASSWORD_RESET_COOLDOWN_SECONDS', min(120, max(60, $passwordResetCooldown)));

$passwordResetAttempts = (int) (getenv('PASSWORD_RESET_MAX_ATTEMPTS') ?: 5);
define('PASSWORD_RESET_MAX_ATTEMPTS', max(1, $passwordResetAttempts));

$passwordResetHourlyLimit = (int) (getenv('PASSWORD_RESET_HOURLY_LIMIT') ?: 5);
define('PASSWORD_RESET_HOURLY_LIMIT', min(5, max(1, $passwordResetHourlyLimit)));

$passwordResetIpHourlyLimit = (int) (getenv('PASSWORD_RESET_IP_HOURLY_LIMIT') ?: 20);
define('PASSWORD_RESET_IP_HOURLY_LIMIT', max(5, $passwordResetIpHourlyLimit));

$adminPasswordResetTargetLimit = (int) (getenv('ADMIN_PASSWORD_RESET_TARGET_HOURLY_LIMIT') ?: 3);
define('ADMIN_PASSWORD_RESET_TARGET_HOURLY_LIMIT', min(10, max(1, $adminPasswordResetTargetLimit)));

$adminPasswordResetActorLimit = (int) (getenv('ADMIN_PASSWORD_RESET_ACTOR_HOURLY_LIMIT') ?: 20);
define('ADMIN_PASSWORD_RESET_ACTOR_HOURLY_LIMIT', min(100, max(5, $adminPasswordResetActorLimit)));

$brevoWebhookMaxBytes = (int) (getenv('BREVO_WEBHOOK_MAX_BYTES') ?: 1048576);
define('BREVO_WEBHOOK_MAX_BYTES', min(5 * 1024 * 1024, max(16 * 1024, $brevoWebhookMaxBytes)));

$brevoWebhookRequestsPerMinute = (int) (getenv('BREVO_WEBHOOK_REQUESTS_PER_MINUTE') ?: 120);
define('BREVO_WEBHOOK_REQUESTS_PER_MINUTE', min(600, max(10, $brevoWebhookRequestsPerMinute)));

$paymentTimeout = (int) (getenv('ORDER_PAYMENT_TIMEOUT_MINUTES') ?: 30);
define('ORDER_PAYMENT_TIMEOUT_MINUTES', max(1, $paymentTimeout));
define('APP_ORDER_EXPIRY_MAINTENANCE_ENABLED', app_environment_boolean('APP_ORDER_EXPIRY_MAINTENANCE_ENABLED', true));

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

if (!headers_sent()) {
    header_remove('X-Powered-By');
}
