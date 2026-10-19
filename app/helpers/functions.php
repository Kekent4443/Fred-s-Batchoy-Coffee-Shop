<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function system_toast_type(string $type): string
{
    $type = strtolower(trim($type));
    $type = $type === 'error' ? 'danger' : $type;

    return in_array($type, ['success', 'warning', 'danger', 'info'], true) ? $type : 'info';
}

function system_toast_markup(string $message, string $type = 'info'): string
{
    $type = system_toast_type($type);
    $iconPaths = [
        'success' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-5"/>',
        'warning' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'danger' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    ];
    $typeLabel = ucfirst($type === 'danger' ? 'error' : $type);
    $role = $type === 'danger' ? 'alert' : 'status';

    return '<div class="system-toast system-toast--' . e($type) . '" role="' . $role . '" aria-live="' . ($type === 'danger' ? 'assertive' : 'polite') . '" aria-atomic="true" data-system-toast data-toast-timeout="4000" style="--toast-duration: 4000ms;">'
        . '<span class="system-toast__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $iconPaths[$type] . '</svg></span>'
        . '<span class="system-toast__message"><span class="sr-only">' . e($typeLabel) . ': </span>' . e($message) . '</span>'
        . '<button class="system-toast__close" type="button" data-toast-close aria-label="Close notification"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>'
        . '<span class="system-toast__progress" aria-hidden="true"><span class="system-toast__progress-bar"></span></span>'
        . '</div>';
}

function display_date_timestamp(mixed $value): ?int
{
    if ($value instanceof DateTimeInterface) {
        return $value->getTimestamp();
    }

    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
}

function display_date_year_format(mixed $value): string
{
    if (is_string($value) && preg_match('/^\s*\d{1,2}\/\d{1,2}\/(\d{2}|\d{4})(?:\D|$)/', $value, $matches) === 1) {
        return strlen($matches[1]) === 4 ? 'Y' : 'y';
    }

    return 'y';
}

function date_input_format_hint(bool $includesTime = false): string
{
    return $includesTime ? 'MM/DD/YY with time' : 'MM/DD/YY';
}

function parse_display_date(mixed $value): ?string
{
    if (!is_scalar($value) && $value !== null) {
        return null;
    }
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2}|\d{4})$/', $value, $matches) !== 1) {
        return null;
    }

    $month = (int) $matches[1];
    $day = (int) $matches[2];
    $year = strlen($matches[3]) === 4 ? (int) $matches[3] : 2000 + (int) $matches[3];

    return checkdate($month, $day, $year)
        ? sprintf('%04d-%02d-%02d', $year, $month, $day)
        : null;
}

function date_filter_errors(array $source, string $fromField = 'date_from', string $toField = 'date_to'): array
{
    $errors = date_input_errors($source, [
        $fromField => 'start date',
        $toField => 'end date',
    ]);
    $from = parse_display_date($source[$fromField] ?? '');
    $to = parse_display_date($source[$toField] ?? '');

    if ($from !== null && $to !== null && $from > $to) {
        $errors[$toField] = 'End date must be the same as or later than the start date.';
    }

    return $errors;
}

function date_input_errors(array $source, array $fieldLabels): array
{
    $errors = [];

    foreach ($fieldLabels as $field => $label) {
        $value = $source[$field] ?? '';
        if (!is_scalar($value) && $value !== null) {
            $errors[$field] = 'Enter a valid ' . $label . ' in MM/DD/YY format.';
            continue;
        }
        $rawValue = trim((string) $value);
        if ($rawValue !== '' && parse_display_date($rawValue) === null) {
            $errors[$field] = 'Enter a valid ' . $label . ' in MM/DD/YY format.';
        }
    }

    return $errors;
}

function date_input_field(
    string $name,
    mixed $value,
    string $label,
    array $errors = [],
    string $prefix = 'date-filter',
    string $className = 'report-filter-field'
): string {
    $safeId = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $prefix . '-' . $name), '-');
    $rawValue = is_scalar($value) || $value === null ? trim((string) $value) : '';
    $isoValue = parse_display_date($rawValue) ?? '';
    $displayValue = $isoValue !== '' ? format_display_date($isoValue) : $rawValue;
    // A four-digit exception prevents an old ISO bookmark changing century.
    if ($isoValue !== '' && ((int) substr($isoValue, 0, 4) < 2000 || (int) substr($isoValue, 0, 4) > 2099)) {
        $displayValue = substr($isoValue, 5, 2) . '/' . substr($isoValue, 8, 2) . '/' . substr($isoValue, 0, 4);
    }
    $hintId = $safeId . '-hint';
    $errorId = $safeId . '-error';
    $hasError = !empty($errors[$name]);
    $describedBy = $hintId . ($hasError ? ' ' . $errorId : '');
    $classes = trim($className . ' date-input-field');
    $calendarIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>'
        . '</svg>';

    return '<div class="' . e($classes) . '" data-date-input>'
        . '<label for="' . e($safeId) . '">' . e($label) . '</label>'
        . '<div class="date-input-control">'
        . '<input class="date-input-control__display" type="text" id="' . e($safeId) . '" name="' . e($name) . '" value="' . e($displayValue) . '" placeholder="MM/DD/YY" inputmode="text" autocomplete="off" data-date-display aria-describedby="' . e($hintId . ' ' . $errorId) . '"'
        . ($hasError ? ' aria-invalid="true" data-invalid-field' : '') . '>'
        . '<input class="date-input-control__native" type="date" name="' . e($name . '_iso') . '" value="' . e($isoValue) . '" tabindex="-1" aria-hidden="true" data-date-native>'
        . '<button class="date-input-control__button" type="button" aria-label="Choose ' . e(strtolower($label)) . ' from calendar" aria-controls="' . e($safeId) . '" data-date-picker-button>'
        . $calendarIcon
        . '</button>'
        . '</div>'
        . '<div class="report-filter-field__message">'
        . '<small class="date-format-hint" id="' . e($hintId) . '">' . e(date_input_format_hint()) . '</small>'
        . '<small class="field-error" id="' . e($errorId) . '" role="alert" data-date-error' . ($hasError ? '' : ' hidden') . '>' . e((string) ($errors[$name] ?? '')) . '</small>'
        . '</div>'
        . '</div>';
}

function date_input_toolbar(
    string $name,
    mixed $value,
    string $labelText,
    string $prefix = 'toolbar-date'
): string {
    return date_input_field($name, $value, $labelText, [], $prefix);
}

function datetime_input_field(
    string $name,
    mixed $value,
    string $label,
    bool $required = false,
    string $prefix = 'datetime-field',
    string $className = ''
): string {
    $safeId = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $prefix . '-' . $name), '-');
    $timestamp = display_date_timestamp($value);
    $isoValue = $timestamp === null ? '' : date('Y-m-d\\TH:i', $timestamp);
    $displayValue = $timestamp === null ? '' : format_display_datetime($value);
    $hintId = $safeId . '-hint';
    $errorId = $safeId . '-error';
    $classes = trim($className . ' date-input-field date-input-field--datetime');
    $calendarIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3M13 14h3M8 18h3"/>'
        . '</svg>';

    return '<div class="' . e($classes) . '" data-datetime-input>'
        . '<label for="' . e($safeId) . '">' . e($label) . '</label>'
        . '<div class="date-input-control">'
        . '<input class="date-input-control__display" type="text" id="' . e($safeId) . '" name="' . e($name . '_display') . '" value="' . e($displayValue) . '" placeholder="MM/DD/YY hh:mm AM" inputmode="text" autocomplete="off" data-datetime-display aria-describedby="' . e($hintId . ' ' . $errorId) . '"'
        . ($required ? ' required' : '') . '>'
        . '<input class="date-input-control__native" type="datetime-local" name="' . e($name) . '" value="' . e($isoValue) . '" tabindex="-1" aria-hidden="true" data-datetime-native>'
        . '<button class="date-input-control__button" type="button" aria-label="Choose ' . e(strtolower($label)) . '" aria-controls="' . e($safeId) . '" data-datetime-picker-button>'
        . $calendarIcon
        . '</button>'
        . '</div>'
        . '<div class="report-filter-field__message">'
        . '<small class="date-format-hint" id="' . e($hintId) . '">MM/DD/YY with time</small>'
        . '<small class="field-error" id="' . e($errorId) . '" role="alert" data-datetime-error hidden></small>'
        . '</div>'
        . '</div>';
}

function format_display_date(mixed $value, string $fallback = ''): string
{
    $timestamp = display_date_timestamp($value);

    return $timestamp === null ? $fallback : date('m/d/' . display_date_year_format($value), $timestamp);
}

function format_display_datetime(mixed $value, string $fallback = '', string $timeFormat = 'h:i A'): string
{
    $timestamp = display_date_timestamp($value);

    return $timestamp === null ? $fallback : date('m/d/' . display_date_year_format($value) . ' ' . $timeFormat, $timestamp);
}

function url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function app_is_https(): bool
{
    return app_request_is_https();
}

function redirect(string $path): void
{
    $target = url($path);

    if (preg_match('#^https?://#i', $path)) {
        $appHost = parse_url(APP_URL, PHP_URL_HOST);
        $targetHost = parse_url($path, PHP_URL_HOST);
        $target = $appHost && $targetHost && strcasecmp($appHost, $targetHost) === 0 ? $path : url('index.php');
    }

    header('Location: ' . $target);
    exit;
}

function asset(string $path): string
{
    static $versions = [];

    $normalizedPath = ltrim($path, '/');
    $assetUrl = url('../assets/' . $normalizedPath);
    $assetFile = ASSET_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);

    if (is_file($assetFile)) {
        $versions[$assetFile] ??= (string) filemtime($assetFile);
        $separator = str_contains($assetUrl, '?') ? '&' : '?';
        return $assetUrl . $separator . 'v=' . $versions[$assetFile];
    }

    return $assetUrl;
}

function system_theme_toggle_markup(): string
{
    return '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="4"/>'
        . '<path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'
        . '</svg>'
        . '<svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
        . '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'
        . '</svg>';
}

function public_file_url(?string $path, string $fallback = ''): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return $fallback;
    }

    if (
        str_starts_with($path, 'http://')
        || str_starts_with($path, 'https://')
        || str_starts_with($path, '//')
    ) {
        return $path;
    }

    $normalizedPath = str_replace('\\', '/', $path);

    if (str_contains($normalizedPath, '../') || str_starts_with($normalizedPath, '/')) {
        return $fallback;
    }

    return url(ltrim($path, '/'));
}

function render_external_map(string $title, string $embedUrl, string $mapUrl, bool $editablePreview = false): void
{
    ?>
    <div class="external-map" data-external-map>
        <div class="external-map__prompt" data-external-map-prompt>
            <p>Google Maps is off by default. Loading it sends information such as your IP address to Google.</p>
            <div class="external-map__actions">
                <button class="btn btn-primary btn-sm" type="button" data-external-map-load>Load Google Maps</button>
                <button class="btn btn-secondary btn-sm" type="button" data-external-map-reject>Keep map off</button>
                <a class="auth-link" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">Open Google Maps in a new tab</a>
            </div>
            <p class="external-map__status" role="status" data-external-map-status>Map preview is off.</p>
        </div>
        <iframe title="<?= e($title) ?>" data-map-src="<?= e($embedUrl) ?>" loading="lazy" referrerpolicy="no-referrer" allowfullscreen <?= $editablePreview ? 'data-shop-map-preview' : '' ?> hidden></iframe>
        <button class="btn btn-secondary btn-sm external-map__hide" type="button" data-external-map-unload hidden>Hide Google Maps</button>
    </div>
    <?php
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function request(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function post_scalar(string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $_POST)) {
        return $default;
    }

    return is_scalar($_POST[$key]) || $_POST[$key] === null ? $_POST[$key] : $default;
}

function query_scalar(string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $_GET)) {
        return $default;
    }

    return is_scalar($_GET[$key]) || $_GET[$key] === null ? $_GET[$key] : $default;
}

function request_scalar(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_POST)) {
        return post_scalar($key, $default);
    }

    return query_scalar($key, $default);
}

function request_string(string $key, string $default = ''): string
{
    $value = request_scalar($key, $default);

    return is_string($value) || is_numeric($value) ? (string) $value : $default;
}

function request_expects_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $path = strtolower(str_replace('\\', '/', (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '')));

    if (str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest') {
        return true;
    }

    foreach ([
        '/notification-count.php',
        '/notification-read.php',
        '/notification-bulk.php',
        '/support/list_conversations.php',
        '/support/poll_messages.php',
        '/support/check_auto_reply.php',
        '/support/send_message.php',
        '/support/mark_read.php',
        '/support/create_conversation.php',
        '/support/update_conversation.php',
    ] as $endpoint) {
        if (str_ends_with($path, $endpoint)) {
            return true;
        }
    }

    return false;
}

function request_is_background_poll(): bool
{
    if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
        return false;
    }

    $path = strtolower(str_replace('\\', '/', (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '')));

    foreach ([
        '/notification-count.php',
        '/support/list_conversations.php',
        '/support/poll_messages.php',
    ] as $endpoint) {
        if (str_ends_with($path, $endpoint)) {
            return true;
        }
    }

    return false;
}

function run_throttled_maintenance(string $task, int $intervalSeconds, callable $callback, bool $force = false): mixed
{
    $intervalSeconds = max(1, $intervalSeconds);
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'freds-' . substr(hash('sha256', APP_ROOT . '|' . $task), 0, 24) . '.lock';
    $lock = @fopen($lockPath, 'c+');

    if ($lock === false) {
        return $force ? $callback() : null;
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return null;
    }

    rewind($lock);
    $lastRun = (int) trim((string) stream_get_contents($lock));
    $now = time();

    if (!$force && $lastRun > 0 && ($now - $lastRun) < $intervalSeconds) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return null;
    }

    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, (string) $now);
    fflush($lock);

    try {
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function current_public_path(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '');
    $query = (string) (parse_url($requestUri, PHP_URL_QUERY) ?: '');
    $basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');

    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath) + 1);
    } else {
        $path = ltrim($path, '/');
    }

    $path = $path !== '' ? $path : 'index.php';

    return $query !== '' ? $path . '?' . $query : $path;
}

function safe_public_return_path(?string $path, string $fallback = 'customer/catalog.php'): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $path)) {
        $appHost = parse_url(APP_URL, PHP_URL_HOST);
        $targetHost = parse_url($path, PHP_URL_HOST);

        if (!$appHost || !$targetHost || strcasecmp($appHost, $targetHost) !== 0) {
            return $fallback;
        }

        $targetPath = (string) (parse_url($path, PHP_URL_PATH) ?: '');
        $targetQuery = (string) (parse_url($path, PHP_URL_QUERY) ?: '');
        $basePath = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?: ''), '/');

        if ($basePath !== '' && str_starts_with($targetPath, $basePath . '/')) {
            $targetPath = substr($targetPath, strlen($basePath) + 1);
        } else {
            $targetPath = ltrim($targetPath, '/');
        }

        $path = $targetPath . ($targetQuery !== '' ? '?' . $targetQuery : '');
    }

    if (str_starts_with($path, '//') || str_contains($path, '\\') || str_contains($path, "\0")) {
        return $fallback;
    }

    $path = ltrim($path, '/');

    if ($path === '' || str_starts_with($path, '../') || str_contains($path, '/../') || str_starts_with($path, 'private/')) {
        return $fallback;
    }

    return $path;
}

function sanitize_search_query(string $value, int $maxLength = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if (strlen($value) > $maxLength) {
        return substr($value, 0, $maxLength);
    }

    return $value;
}

function client_ip(): string
{
    if (app_request_uses_trusted_proxy()) {
        $forwardedParts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))
        ), static fn (string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_IP) !== false));

        for ($index = count($forwardedParts) - 1; $index >= 0; $index--) {
            $candidate = $forwardedParts[$index];
            $isTrustedProxy = false;

            foreach (app_trusted_proxy_entries() as $entry) {
                if (app_ip_matches_proxy_entry($candidate, $entry)) {
                    $isTrustedProxy = true;
                    break;
                }
            }

            if (!$isTrustedProxy) {
                return $candidate;
            }
        }
    }

    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    return filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : '127.0.0.1';
}

function ensure_rate_limit_schema(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    if (!schema_maintenance_enabled()) {
        $checked = true;
        return;
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rate_key CHAR(64) NOT NULL,
            scope VARCHAR(80) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uniq_rate_key_scope (rate_key, scope),
            INDEX idx_rate_limits_expires (expires_at)
        ) ENGINE=InnoDB'
    );

    $checked = true;
}

function login_rate_limit_enabled(): bool
{
    return !defined('LOGIN_RATE_LIMIT_ENABLED') || LOGIN_RATE_LIMIT_ENABLED;
}

function tester_email_is_authorized(string $email): bool
{
    if (
        !defined('TESTING_MODE')
        || !TESTING_MODE
        || !defined('TESTER_AUTH_RATE_LIMITS_ENABLED')
        || !TESTER_AUTH_RATE_LIMITS_ENABLED
        || !defined('TESTER_EMAILS')
        || !is_array(TESTER_EMAILS)
    ) {
        return false;
    }

    $email = strtolower(trim($email));

    return $email !== '' && in_array($email, TESTER_EMAILS, true);
}

function tester_authenticated_session_active(): bool
{
    if (empty($_SESSION['user_id']) || !function_exists('current_user')) {
        return false;
    }

    $viewer = current_user();
    return is_array($viewer) && tester_email_is_authorized((string) ($viewer['email'] ?? ''));
}

function is_authentication_login_request(): bool
{
    $executedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if ($executedScript === false) {
        return false;
    }

    $loginScripts = [
        realpath(PUBLIC_ROOT . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login.php'),
    ];

    return in_array($executedScript, array_filter($loginScripts, 'is_string'), true);
}

function is_authentication_logout_request(): bool
{
    $executedScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return $executedScript !== false
        && $executedScript === realpath(PUBLIC_ROOT . DIRECTORY_SEPARATOR . 'logout.php');
}

function login_rate_limit_bypassed_for_request(): bool
{
    return is_authentication_login_request()
        && !login_rate_limit_enabled();
}

function rate_limit_key(string $scope, string $identifier = '', bool $includeClientIp = true): string
{
    $ip = $includeClientIp ? client_ip() : 'all-clients';

    return hash('sha256', $scope . '|' . $ip . '|' . strtolower(trim($identifier)));
}

function rate_limit_allow(string $scope, string $identifier, int $maxAttempts, int $windowSeconds, bool $includeClientIp = true): bool
{
    ensure_rate_limit_schema();

    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);
    $key = rate_limit_key($scope, $identifier, $includeClientIp);
    $expiresAt = date('Y-m-d H:i:s', time() + $windowSeconds);

    run_throttled_maintenance(
        'rate-limit-cleanup',
        60,
        static fn (): bool => db_execute('DELETE FROM rate_limits WHERE expires_at < NOW() LIMIT 100')
    );
    db_execute(
        'INSERT INTO rate_limits (rate_key, scope, attempts, first_attempt_at, last_attempt_at, expires_at)
         VALUES (?, ?, 1, NOW(), NOW(), ?)
         ON DUPLICATE KEY UPDATE
            attempts = IF(expires_at < NOW(), 1, attempts + 1),
            first_attempt_at = IF(expires_at < NOW(), NOW(), first_attempt_at),
            last_attempt_at = NOW(),
            expires_at = IF(expires_at < NOW(), VALUES(expires_at), expires_at)',
        'sss',
        [$key, $scope, $expiresAt]
    );

    $row = db_find(
        'SELECT attempts FROM rate_limits WHERE rate_key = ? AND scope = ? LIMIT 1',
        'ss',
        [$key, $scope]
    );

    return (int) ($row['attempts'] ?? 0) <= $maxAttempts;
}

function rate_limit_clear(string $scope, string $identifier, bool $includeClientIp = true): void
{
    ensure_rate_limit_schema();

    db_execute(
        'DELETE FROM rate_limits WHERE rate_key = ? AND scope = ?',
        'ss',
        [rate_limit_key($scope, $identifier, $includeClientIp), $scope]
    );
}

function enforce_post_rate_limit(int $maxAttempts = 120, int $windowSeconds = 60): void
{
    if (!is_post() || login_rate_limit_bypassed_for_request()) {
        return;
    }

    $route = (string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown');
    $testerLogin = is_authentication_login_request()
        && isset($_POST['email'])
        && is_scalar($_POST['email'])
        && tester_email_is_authorized((string) $_POST['email']);
    $testerLogout = is_authentication_logout_request() && tester_authenticated_session_active();
    $scope = $testerLogin || $testerLogout
        ? 'post:tester-' . ($testerLogin ? 'login' : 'logout')
        : 'post:' . substr(hash('sha256', $route), 0, 16);
    if ($testerLogin || $testerLogout) {
        $maxAttempts = max($maxAttempts, TESTER_AUTH_POST_LIMIT);
    }

    if (rate_limit_allow($scope, '', $maxAttempts, $windowSeconds)) {
        return;
    }

    http_response_code(429);

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.']);
        exit;
    }

    exit('Too many requests. Please wait a moment and try again.');
}

function enforce_request_rate_limit(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): void
{
    if (rate_limit_allow($scope, $identifier, $maxAttempts, $windowSeconds)) {
        return;
    }

    http_response_code(429);
    header('Retry-After: ' . (string) max(1, $windowSeconds));
    header('Cache-Control: no-store');

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.']);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    exit('Too many requests. Please wait a moment and try again.');
}

function db_select(string $sql, string $types = '', array $params = []): array
{
    $stmt = get_db()->prepare($sql);

    if ($types !== '' && $params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            $stmt->close();

            return $rows;
        }
    }

    $metadata = $stmt->result_metadata();
    if (!$metadata) {
        $stmt->close();
        return [];
    }

    $fields = $metadata->fetch_fields();
    $metadata->free();
    $values = array_fill(0, count($fields), null);
    $references = [];
    foreach ($values as $index => &$value) {
        $references[$index] = &$value;
    }
    unset($value);
    $stmt->bind_result(...$references);

    $rows = [];
    while ($stmt->fetch()) {
        $row = [];
        foreach ($fields as $index => $field) {
            $row[$field->name] = $values[$index];
        }
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function db_find(string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_select($sql, $types, $params);

    return $rows[0] ?? null;
}

function db_execute(string $sql, string $types = '', array $params = []): bool
{
    $stmt = get_db()->prepare($sql);

    if ($types !== '' && $params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    return $stmt->execute();
}

function db_last_id(): int
{
    return get_db()->insert_id;
}

function old(string $key, mixed $default = ''): string
{
    $value = post_scalar($key, $default);

    return e(is_string($value) || is_numeric($value) ? (string) $value : '');
}

function validation_error_field(string $message, string $default = 'form'): string
{
    $message = strtolower($message);
    $fieldTerms = [
        'confirm_password' => ['passwords do not match', 'confirm password'],
        'current_password' => ['current password'],
        'password' => ['password'],
        'email' => ['email', 'e-mail'],
        'account_number' => ['gcash number'],
        'phone' => ['phone', 'mobile number'],
        'address' => ['address'],
        'full_name' => ['full name'],
        'role' => ['role'],
        'status' => ['status'],
        'category_id' => ['category'],
        'account_name' => ['gcash account name', 'account name'],
        'name' => ['product name', 'category name', 'name is required'],
        'price' => ['price'],
        'stock_quantity' => ['stock'],
        'is_available' => ['availability'],
        'valid_id' => ['valid id', 'identity document'],
        'image_upload' => ['product image', 'upload a product image'],
        'qr_code' => ['qr code'],
        'code' => ['promo code'],
        'description' => ['promo description'],
        'max_quantity' => ['maximum quantity'],
        'min_quantity' => ['minimum quantity'],
        'discount_rate' => ['discount rate'],
        'discount_value' => ['discount value', 'percent discount', 'fixed discount'],
        'minimum_subtotal' => ['minimum subtotal'],
        'maximum_discount' => ['maximum discount'],
        'usage_limit' => ['usage limit'],
        'starts_at' => ['start date'],
        'ends_at' => ['end date'],
        'label' => ['label'],
        'question' => ['question', 'subject'],
        'keywords' => ['keyword'],
        'answer' => ['answer'],
        'google_maps_embed_url' => ['google maps embed'],
        'google_maps_url' => ['google maps public', 'google maps link'],
        'latitude' => ['latitude'],
        'longitude' => ['longitude'],
        'contact_number' => ['contact number'],
    ];

    foreach ($fieldTerms as $field => $terms) {
        foreach ($terms as $term) {
            if (str_contains($message, $term)) {
                return $field;
            }
        }
    }

    return $default;
}

function duplicate_error_field(mysqli_sql_exception $exception, string $default = 'form'): string
{
    if ((int) $exception->getCode() !== 1062) {
        return $default;
    }

    $message = strtolower($exception->getMessage());
    foreach (['email', 'phone', 'code', 'account_number', 'name'] as $field) {
        if (str_contains($message, $field)) {
            return $field;
        }
    }

    return $default;
}

function form_error_attributes(array $errors, string $field, string $prefix = 'form'): string
{
    if (empty($errors[$field])) {
        return '';
    }

    $errorId = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix . '-' . $field . '-error');

    return ' aria-invalid="true" aria-describedby="' . e((string) $errorId) . '" data-invalid-field';
}

function form_field_error(array $errors, string $field, string $prefix = 'form'): string
{
    if (empty($errors[$field])) {
        return '';
    }

    $errorId = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix . '-' . $field . '-error');

    return '<small class="field-error" id="' . e((string) $errorId) . '" role="alert">'
        . e((string) $errors[$field])
        . '</small>';
}

function currency(float|int|string $amount): string
{
    return 'PHP ' . number_format((float) $amount, 2);
}

function status_badge(string $status): string
{
    $classMap = [
        'active' => 'success',
        'inactive' => 'muted',
        'scheduled' => 'info',
        'expired' => 'danger',
        'usage_limit_reached' => 'warning',
        'pending_verification' => 'warning',
        'rejected' => 'danger',
        'approved' => 'success',
        'pending' => 'warning',
        'processing' => 'info',
        'preparing' => 'info',
        'confirmed' => 'info',
        'assigned' => 'info',
        'ready_for_delivery' => 'info',
        'out_for_delivery' => 'accent',
        'delivered' => 'success',
        'issue_reported' => 'warning',
        'failed_delivery' => 'danger',
        'accepted' => 'success',
        'completed' => 'success',
        'paid' => 'success',
        'read' => 'success',
        'unread' => 'warning',
        'cancelled' => 'danger',
        'failed' => 'danger',
        'refunded' => 'warning',
    ];

    $class = $classMap[$status] ?? 'muted';
    $label = ucwords(str_replace('_', ' ', $status));

    return '<span class="badge badge-' . e($class) . '">' . e($label) . '</span>';
}

function priority_level(array $record): string
{
    $level = strtolower((string) ($record['priority_level'] ?? ''));
    if (in_array($level, ['high', 'medium', 'low'], true)) {
        return $level;
    }

    $rank = (int) ($record['priority_rank'] ?? 4);
    return match ($rank) {
        1 => 'high',
        2, 3 => 'medium',
        default => 'low',
    };
}

function priority_action_label(array $record, string $type = ''): string
{
    $type = $type !== '' ? $type : strtolower((string) ($record['record_type'] ?? ''));
    $type = match ($type) {
        'orders' => 'order',
        'deliveries' => 'delivery',
        'transactions' => 'transaction',
        default => $type,
    };
    $status = strtolower((string) ($record['status'] ?? $record['order_status'] ?? $record['delivery_status'] ?? $record['transaction_status'] ?? ''));
    $viewerRole = strtolower((string) (current_user()['role'] ?? ''));

    if ($type === 'order' && function_exists('order_workflow_current_status')) {
        $workflowStatus = order_workflow_current_status($record);
        if (($record['payment_method'] ?? '') === 'gcash' && in_array((string) ($record['transaction_status'] ?? ''), ['pending', 'failed'], true)) {
            return (string) ($record['transaction_status'] ?? '') === 'failed' ? 'Review payment issue' : 'Verify GCash payment';
        }

        if ($viewerRole === 'staff') {
            return match ($workflowStatus) {
                'pending' => 'Confirm order',
                'confirmed' => 'Start preparation',
                'preparing' => 'Mark ready',
                default => '',
            };
        }

        if (in_array($viewerRole, ['administrator', 'manager'], true)) {
            return match ($workflowStatus) {
                'ready_for_delivery' => 'Assign rider',
                'issue_reported', 'failed_delivery' => 'Review delivery issue',
                default => '',
            };
        }

        return '';
    }

    if ($type === 'delivery') {
        $deliveryStatus = (string) ($record['delivery_status'] ?? $record['order_delivery_status'] ?? $status);
        if ($viewerRole === 'rider') {
            return match ($deliveryStatus) {
                'assigned' => 'Start delivery',
                'out_for_delivery' => 'Complete delivery',
                default => '',
            };
        }

        if (in_array($viewerRole, ['administrator', 'manager'], true)) {
            return match ($deliveryStatus) {
                'assigned' => 'Assign rider',
                'issue_reported', 'failed_delivery' => 'Review delivery issue',
                default => '',
            };
        }

        return '';
    }

    if ($type === 'transaction') {
        return match ((string) ($record['transaction_status'] ?? $status)) {
            'pending' => 'Verify payment',
            'failed', 'cancelled' => 'Review payment issue',
            'accepted' => 'Complete payment',
            default => '',
        };
    }

    if ($type === 'product') {
        $stock = (int) ($record['stock_quantity'] ?? 0);
        if ($stock <= 0) {
            return 'Restock item';
        }
        if ($stock <= 10) {
            return 'Low stock';
        }
    }

    return match ($status) {
        'pending_verification' => 'Review verification',
        'rejected' => 'Needs follow-up',
        'unread' => 'Open notification',
        'pending_staff' => $viewerRole === 'customer' ? 'Waiting for support' : 'Reply to customer',
        'pending_customer' => $viewerRole === 'customer' ? 'Reply to support' : 'Waiting for customer',
        default => '',
    };
}

function priority_badge(array $record, string $type = ''): string
{
    $rank = (int) ($record['priority_rank'] ?? 4);
    if ($rank >= 4) {
        return '';
    }

    $level = priority_level($record);
    $action = $rank === 1 ? priority_action_label($record, $type) : '';
    $label = match ($rank) {
        1 => 'Needs attention',
        2 => 'New',
        default => 'In progress',
    };
    if ($rank === 1 && $action !== '') {
        $label .= ': ' . $action;
    }

    return '<span class="priority-badge priority-badge--' . e($level) . '">' . e($label) . '</span>';
}

function priority_item_attrs(array $record): string
{
    $level = priority_level($record);

    return ' data-priority-level="' . e($level) . '" class="priority-item priority-item--' . e($level) . '"';
}

function priority_item_class(array $record): string
{
    $level = priority_level($record);

    return 'priority-item priority-item--' . e($level);
}

function priority_data_attr(array $record): string
{
    return 'data-priority-level="' . e(priority_level($record)) . '"';
}

function current_year(): string
{
    return date('Y');
}

function role_folder(string $role): string
{
    return $role === 'administrator' ? 'admin' : $role;
}

function sidebar_icon_svg(string $iconKey, string $extraClass = ''): string
{
    static $paths = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'refresh' => '<path d="M20 7v5h-5"/><path d="M18.4 16a8 8 0 1 1 .7-7.2L20 12"/>',
        'alert-triangle' => '<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5M12 17h.01"/>',
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.4"/><rect x="13.5" y="3.5" width="7" height="4.5" rx="1.4"/><rect x="13.5" y="11" width="7" height="9.5" rx="1.4"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.4"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3.8 19a5.2 5.2 0 0 1 10.4 0M15.5 5.3a3 3 0 0 1 0 5.4M16.5 14a4.7 4.7 0 0 1 3.7 5"/>',
        'shield-check' => '<path d="M12 3 5 6v5.2c0 4.5 2.9 7.8 7 9.8 4.1-2 7-5.3 7-9.8V6l-7-3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
        'package' => '<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7M12 11v10"/>',
        'categories' => '<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.3"/><rect x="14" y="3.5" width="6.5" height="6.5" rx="1.3"/><rect x="3.5" y="14" width="6.5" height="6.5" rx="1.3"/><rect x="14" y="14" width="6.5" height="6.5" rx="1.3"/>',
        'percent' => '<circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/><path d="m18.5 5.5-13 13"/>',
        'ticket' => '<path d="M4 6.5h16v4a2.5 2.5 0 0 0 0 5v4H4v-4a2.5 2.5 0 0 0 0-5v-4Z"/><path d="M12 7.5v2M12 12v2M12 16.5v1"/>',
        'orders' => '<path d="M6 8h12l1 12H5L6 8Z"/><path d="M9 9V7a3 3 0 0 1 6 0v2"/>',
        'transactions' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'delivery' => '<path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'bell' => '<path d="M18 9.8a6 6 0 0 0-12 0c0 5.2-2 6.4-2 6.4h16s-2-1.2-2-6.4Z"/><path d="M9.7 19a2.5 2.5 0 0 0 4.6 0"/>',
        'chat' => '<path d="M4 5.5h16v11H9l-5 4v-15Z"/><path d="M8 10h8M8 13h5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1.2.9-1.2 1.7v.5M12 17h.01"/>',
        'location' => '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'wallet' => '<path d="M4 6.5h14a2 2 0 0 1 2 2v10H5.5A2.5 2.5 0 0 1 3 16V6a2 2 0 0 1 2-2h12"/><path d="M15 11h6v4h-6a2 2 0 0 1 0-4Z"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'phone' => '<path d="M8.1 5.1 9.5 8c.4.8.2 1.7-.5 2.2l-1 .8a11 11 0 0 0 5 5l.8-1c.5-.7 1.5-.9 2.2-.5l2.9 1.4c.8.4 1.2 1.3 1 2.1l-.5 2a2.2 2.2 0 0 1-2.3 1.7C8.9 21 3 15.1 2.3 6.9A2.2 2.2 0 0 1 4 4.6l2-.5c.8-.2 1.7.2 2.1 1Z"/>',
        'inbox' => '<path d="M4 5h16v14H4z"/><path d="m4 6 8 7 8-7"/>',
        'reports' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'download' => '<path d="M12 3v12m-4-4 4 4 4-4M4 17v3h16v-3"/>',
        'records' => '<path d="M3.5 7h6l2-2h9v15h-17V7Z"/><path d="M7.5 12h9M7.5 16h6"/>',
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/>',
        'link' => '<path d="M10 13a4 4 0 0 0 5.7.1l2-2a4 4 0 0 0-5.6-5.7l-1.2 1.2M14 11a4 4 0 0 0-5.7-.1l-2 2a4 4 0 0 0 5.6 5.7l1.2-1.2"/>',
    ];

    $classes = trim('sidebar__icon ' . $extraClass);
    $iconPaths = $paths[$iconKey] ?? $paths['link'];

    return '<span class="' . e($classes) . '" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
        . $iconPaths
        . '</svg></span>';
}

function status_action_button_content(
    string $iconKey,
    string $label,
    string $loadingLabel = 'Updating...'
): string {
    return sidebar_icon_svg($iconKey, 'status-action-button__icon')
        . '<span data-status-action-label data-ready-label="' . e($label) . '" data-loading-label="' . e($loadingLabel) . '">' . e($label) . '</span>';
}

function assigned_rider_locked_control(string $riderName, string $layoutClass = 'inline-form'): string
{
    return '<div class="rider-assignment-locked ' . e($layoutClass) . '">'
        . '<select aria-label="Assigned rider" disabled><option selected>' . e($riderName) . '</option></select>'
        . '<button class="btn btn-secondary btn-sm status-action-button" type="button" disabled>'
        . status_action_button_content('shield-check', 'Assigned', 'Assigned')
        . '</button></div>';
}

function trash_icon_svg(): string
{
    return '<svg class="trash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.5 6.5h13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M7.2 9l.7 9.1A2.1 2.1 0 0 0 10 20h4a2.1 2.1 0 0 0 2.1-1.9l.7-9.1" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.4 11.3v5.2M13.6 11.3v5.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
}

function edit_icon_svg(): string
{
    return '<svg class="edit-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5.2 16.8 4.4 20l3.2-.8 10.1-10.1a2 2 0 0 0 0-2.8l-.1-.1a2 2 0 0 0-2.8 0L5.2 16.8z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="m13.9 7.1 3 3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 21h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
}

function eye_slash_icon_svg(): string
{
    return '<svg class="eye-slash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.6 12s3.1-5.2 8.4-5.2c1.3 0 2.5.3 3.6.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.4 12s-3.1 5.2-8.4 5.2c-1.3 0-2.5-.3-3.6-.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.1 10a2.8 2.8 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 4.5 19.5 19.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
}

function eye_icon_svg(): string
{
    return '<svg class="eye-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.6 12s3.1-5.2 8.4-5.2 8.4 5.2 8.4 5.2-3.1 5.2-8.4 5.2S3.6 12 3.6 12z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.7" fill="none" stroke="currentColor" stroke-width="1.9"/></svg>';
}

function archive_icon_svg(): string
{
    return '<svg class="archive-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5.2 7.8h13.6v10.1A2.1 2.1 0 0 1 16.7 20H7.3a2.1 2.1 0 0 1-2.1-2.1V7.8z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.4 4h15.2a1.2 1.2 0 0 1 1.2 1.2v1.4a1.2 1.2 0 0 1-1.2 1.2H4.4a1.2 1.2 0 0 1-1.2-1.2V5.2A1.2 1.2 0 0 1 4.4 4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.2 11.2h5.6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
}

function recover_icon_svg(): string
{
    return '<svg class="recover-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.1 7.4H4.7V4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 7.4a7.1 7.1 0 1 1 .9 8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.2 17.2v.8A2.1 2.1 0 0 0 9.3 20h5.4a2.1 2.1 0 0 0 2.1-2v-.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.5 20v-3.8h5V20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function logout_icon_svg(): string
{
    return '<svg class="logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 5.2H6.6A2.1 2.1 0 0 0 4.5 7.3v9.4a2.1 2.1 0 0 0 2.1 2.1H10" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 8.2 17.3 12l-3.8 3.8M8.8 12h8.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function home_icon_svg(): string
{
    return '<svg class="nav-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.2 11.1 12 4.5l7.8 6.6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 10.4v8a1.6 1.6 0 0 0 1.6 1.6h7.8a1.6 1.6 0 0 0 1.6-1.6v-8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 20v-5h4v5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function products_icon_svg(): string
{
    return '<svg class="nav-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="6.2" height="6.2" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.9"/><rect x="13.8" y="4" width="6.2" height="6.2" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.9"/><rect x="4" y="13.8" width="6.2" height="6.2" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.9"/><rect x="13.8" y="13.8" width="6.2" height="6.2" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.9"/></svg>';
}

function cart_icon_svg(): string
{
    return '<svg class="cart-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.2 5.2h2.1l1.7 9.1a2 2 0 0 0 2 1.7h6.5a2 2 0 0 0 1.9-1.4l1.1-4.7H7.2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10.2" cy="19.2" r="1.3" fill="none" stroke="currentColor" stroke-width="1.9"/><circle cx="17.1" cy="19.2" r="1.3" fill="none" stroke="currentColor" stroke-width="1.9"/></svg>';
}

function cart_items(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(array_column(cart_items(), 'quantity'));
}

function cart_total(): float
{
    $total = 0;

    foreach (cart_items() as $item) {
        $total += ((float) $item['price']) * ((int) $item['quantity']);
    }

    return $total;
}

function vat_rate(?float $percentage = null): float
{
    if ($percentage === null) {
        $settings = function_exists('receipt_settings') ? receipt_settings() : [];
        $percentage = (float) ($settings['tax_rate'] ?? 12.0);
    }

    return max(0.0, min(100.0, $percentage)) / 100;
}

function vat_inclusive_breakdown(float $grossAmount, ?float $taxRate = null): array
{
    $grossAmount = round(max(0, $grossAmount), 2);
    $rate = vat_rate($taxRate);
    $vatableSales = $rate > 0 ? round($grossAmount / (1 + $rate), 2) : $grossAmount;
    $vatAmount = round($grossAmount - $vatableSales, 2);

    return [
        'vatable_sales' => $vatableSales,
        'vat_amount' => $vatAmount,
        'subtotal' => $grossAmount,
        'total' => $grossAmount,
    ];
}

function cart_vat_summary(): array
{
    return vat_inclusive_breakdown(cart_total());
}

function active_bulk_discount_rules(): array
{
    ensure_pricing_schema();

    return db_select(
        'SELECT * FROM bulk_discount_rules
         WHERE status = "active" AND max_quantity IS NOT NULL AND max_quantity > min_quantity
         ORDER BY min_quantity DESC, discount_rate DESC, id ASC'
    );
}

function bulk_discount_rate_for_quantity(int $quantity): float
{
    foreach (active_bulk_discount_rules() as $rule) {
        $minQuantity = (int) $rule['min_quantity'];
        $maxQuantity = isset($rule['max_quantity']) ? (int) $rule['max_quantity'] : 0;

        if ($quantity < $minQuantity) {
            continue;
        }

        if ($maxQuantity > 0 && $quantity > $maxQuantity) {
            continue;
        }

        return max(0.0, (float) $rule['discount_rate']);
    }

    return 0.0;
}

function pricing_summary(
    float $subtotal,
    int $quantity,
    ?float $discountRate = null,
    ?float $discountAmount = null,
    ?float $serviceFee = null,
    ?float $deliveryFee = null,
    ?float $taxRate = null,
    ?string $promoCode = null,
    ?float $promoDiscountAmount = null
): array
{
    $settings = function_exists('receipt_settings') ? receipt_settings() : [];
    $subtotal = round(max(0, $subtotal), 2);
    $quantity = max(0, $quantity);
    $discountRate = $discountRate ?? bulk_discount_rate_for_quantity($quantity);
    $discountRate = max(0.0, min(100.0, $discountRate));
    $discountAmount = $discountAmount ?? round($subtotal * ($discountRate / 100), 2);
    $discountAmount = round(max(0, min($subtotal, $discountAmount)), 2);
    $promoCode = strtoupper(trim((string) $promoCode));
    $maximumPromoDiscount = max(0, $subtotal - $discountAmount);
    $promoDiscountAmount = round(max(0, min($maximumPromoDiscount, (float) ($promoDiscountAmount ?? 0))), 2);
    $serviceFee = round(max(0, $serviceFee ?? (float) ($settings['service_fee'] ?? 0)), 2);
    $deliveryFee = round(max(0, $deliveryFee ?? (float) ($settings['delivery_fee'] ?? 0)), 2);
    $taxRate = max(0, min(100, $taxRate ?? (float) ($settings['tax_rate'] ?? 12)));
    $total = round($subtotal - $discountAmount - $promoDiscountAmount + $serviceFee + $deliveryFee, 2);
    $vat = vat_inclusive_breakdown($total, $taxRate);

    return [
        'quantity' => $quantity,
        'subtotal' => $subtotal,
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'promo_code' => $promoCode !== '' ? $promoCode : null,
        'promo_discount_amount' => $promoDiscountAmount,
        'service_fee' => $serviceFee,
        'delivery_fee' => $deliveryFee,
        'tax_rate' => $taxRate,
        'vatable_sales' => $vat['vatable_sales'],
        'vat_amount' => $vat['vat_amount'],
        'total' => $total,
    ];
}

function cart_pricing_summary(): array
{
    return pricing_summary(cart_total(), cart_count());
}

function order_items_vat_summary(array $items): array
{
    $subtotal = array_reduce(
        $items,
        static fn (float $carry, array $item): float => $carry + (float) ($item['subtotal'] ?? 0),
        0.0
    );

    return vat_inclusive_breakdown($subtotal);
}

function order_pricing_summary(array $items, array $order): array
{
    $subtotal = (float) ($order['subtotal_amount'] ?? 0);

    if ($subtotal <= 0) {
        $subtotal = array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + (float) ($item['subtotal'] ?? 0),
            0.0
        );
    }

    $quantity = array_reduce(
        $items,
        static fn (int $carry, array $item): int => $carry + (int) ($item['quantity'] ?? 0),
        0
    );
    $discountRate = (float) ($order['discount_rate'] ?? 0);
    $discountAmount = (float) ($order['discount_amount'] ?? 0);
    $promoCode = trim((string) ($order['promo_code'] ?? ''));
    $promoDiscountAmount = (float) ($order['promo_discount_amount'] ?? 0);

    if ($discountRate <= 0 && $discountAmount <= 0 && !array_key_exists('subtotal_amount', $order)) {
        return pricing_summary(
            $subtotal,
            $quantity,
            0.0,
            0.0,
            (float) ($order['service_fee'] ?? 0),
            (float) ($order['delivery_fee'] ?? 0),
            (float) ($order['tax_rate'] ?? 12),
            $promoCode,
            $promoDiscountAmount
        );
    }

    return pricing_summary(
        $subtotal,
        $quantity,
        $discountRate,
        $discountAmount,
        (float) ($order['service_fee'] ?? 0),
        (float) ($order['delivery_fee'] ?? 0),
        (float) ($order['tax_rate'] ?? 12),
        $promoCode,
        $promoDiscountAmount
    );
}

function product_stock_quantity(?array $product): int
{
    return max(0, (int) ($product['stock_quantity'] ?? 0));
}

function product_can_be_ordered(?array $product): bool
{
    if (!$product) {
        return false;
    }

    return ($product['status'] ?? '') === 'active'
        && (int) ($product['is_available'] ?? 0) === 1
        && product_stock_quantity($product) > 0;
}

function flash_many(array $messages, string $type = 'warning', string $prefix = 'flash'): void
{
    foreach (array_values($messages) as $index => $message) {
        flash($prefix . '_' . $index, $message, $type);
    }
}

function sync_cart_with_inventory(): array
{
    $cart = cart_items();
    $messages = [];

    if ($cart === []) {
        return [];
    }

    $productIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $productId): int => (int) $productId,
        array_keys($cart)
    ), static fn (int $productId): bool => $productId > 0)));
    sort($productIds, SORT_NUMERIC);
    $products = $productIds === [] ? [] : db_select(
        'SELECT p.*, c.status AS category_status
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         WHERE p.id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')',
        str_repeat('i', count($productIds)),
        $productIds
    );
    $productsById = [];
    foreach ($products as $product) {
        $productsById[(int) $product['id']] = $product;
    }

    foreach ($cart as $productId => $item) {
        $product = $productsById[(int) $productId] ?? null;

        if (!$product || ($product['status'] ?? '') !== 'active' || ($product['category_status'] ?? '') !== 'active' || (int) ($product['is_available'] ?? 0) !== 1) {
            unset($cart[$productId]);
            $messages[] = $item['name'] . ' is no longer available and was removed from your cart.';
            continue;
        }

        $stock = product_stock_quantity($product);

        if ($stock === 0) {
            unset($cart[$productId]);
            $messages[] = $product['name'] . ' is out of stock and was removed from your cart.';
            continue;
        }

        $cart[$productId]['name'] = $product['name'];
        $cart[$productId]['price'] = (float) $product['price'];
        $cart[$productId]['image_url'] = $product['image_url'] ?? '';
        $cart[$productId]['stock_quantity'] = $stock;

        if ((int) $cart[$productId]['quantity'] > $stock) {
            $cart[$productId]['quantity'] = $stock;
            $messages[] = $product['name'] . ' quantity was adjusted to ' . $stock . ' because only ' . $stock . ' item(s) are available.';
        }
    }

    $_SESSION['cart'] = $cart;

    return array_values(array_unique($messages));
}

function add_cart_item(array $product, int $quantity = 1): void
{
    if (!product_can_be_ordered($product)) {
        return;
    }

    unset($_SESSION['last_order_fingerprint'], $_SESSION['last_order_id']);
    $cart = cart_items();
    $productId = (int) $product['id'];
    $stock = product_stock_quantity($product);
    $quantity = max(1, $quantity);
    $existingQuantity = (int) ($cart[$productId]['quantity'] ?? 0);
    $newQuantity = min($stock, $existingQuantity + $quantity);

    if ($newQuantity <= 0) {
        return;
    }

    $cart[$productId] = [
        'product_id' => $productId,
        'name' => $product['name'],
        'price' => (float) $product['price'],
        'image_url' => $product['image_url'] ?? '',
        'quantity' => $newQuantity,
        'stock_quantity' => $stock,
    ];

    $_SESSION['cart'] = $cart;
}

function add_product_to_cart_with_feedback(int $productId, int $requestedQuantity = 1): void
{
    $requestedQuantity = max(1, $requestedQuantity);
    $product = fetch_product_by_id($productId);

    if (product_can_be_ordered($product)) {
        $cart = cart_items();
        $existingQuantity = (int) ($cart[$productId]['quantity'] ?? 0);
        $availableToAdd = max(0, product_stock_quantity($product) - $existingQuantity);

        if ($availableToAdd <= 0) {
            flash('cart', 'You already have the maximum available stock of ' . $product['name'] . ' in your cart.', 'warning');
            return;
        }

        $quantityToAdd = min($requestedQuantity, $availableToAdd);
        add_cart_item($product, $quantityToAdd);

        if ($quantityToAdd < $requestedQuantity) {
            flash('cart', 'Only ' . $quantityToAdd . ' more item(s) of ' . $product['name'] . ' were added because stock is limited.', 'warning');
            return;
        }

        flash('cart', $product['name'] . ' added to cart.', 'success');
        return;
    }

    if ($product && product_stock_quantity($product) === 0) {
        flash('cart', $product['name'] . ' is currently out of stock.', 'danger');
        return;
    }

    flash('cart', 'Selected product is unavailable.', 'danger');
}

function update_cart_quantities(array $quantities): array
{
    unset($_SESSION['last_order_fingerprint'], $_SESSION['last_order_id']);
    $cart = cart_items();
    $messages = [];

    foreach ($quantities as $productId => $quantity) {
        $productId = (int) $productId;
        $quantity = max(0, (int) $quantity);

        if (!isset($cart[$productId])) {
            continue;
        }

        if ($quantity === 0) {
            unset($cart[$productId]);
            continue;
        }

        $product = fetch_product_by_id($productId);

        if (!$product || ($product['status'] ?? '') !== 'active' || (int) ($product['is_available'] ?? 0) !== 1) {
            $productName = $cart[$productId]['name'];
            unset($cart[$productId]);
            $messages[] = $productName . ' is no longer available and was removed from your cart.';
            continue;
        }

        $stock = product_stock_quantity($product);

        if ($stock === 0) {
            unset($cart[$productId]);
            $messages[] = $product['name'] . ' is out of stock and was removed from your cart.';
            continue;
        }

        if ($quantity > $stock) {
            $quantity = $stock;
            $messages[] = $product['name'] . ' quantity was adjusted to ' . $stock . ' because only ' . $stock . ' item(s) are available.';
        }

        $cart[$productId]['name'] = $product['name'];
        $cart[$productId]['price'] = (float) $product['price'];
        $cart[$productId]['image_url'] = $product['image_url'] ?? '';
        $cart[$productId]['stock_quantity'] = $stock;
        $cart[$productId]['quantity'] = $quantity;
    }

    $_SESSION['cart'] = $cart;

    return array_values(array_unique($messages));
}

function remove_cart_item(int $productId): bool
{
    $cart = cart_items();

    if (!isset($cart[$productId])) {
        return false;
    }

    unset($cart[$productId], $_SESSION['last_order_fingerprint'], $_SESSION['last_order_id']);
    $_SESSION['cart'] = $cart;

    return true;
}

function clear_cart(): void
{
    unset($_SESSION['cart'], $_SESSION['last_order_fingerprint'], $_SESSION['last_order_id']);
}

function table_search_value(): string
{
    return sanitize_search_query((string) query_scalar('search', ''));
}

function csv_spreadsheet_safe_value(mixed $value): mixed
{
    if (!is_string($value)) {
        return $value;
    }

    return preg_match('/^[\x00-\x20\x7f]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

function export_csv(string $filename, array $headers, array $rows): never
{
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename)) ?: 'export.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');

    $output = fopen('php://output', 'wb');
    fputcsv($output, array_map('csv_spreadsheet_safe_value', $headers));

    foreach ($rows as $row) {
        fputcsv($output, array_map('csv_spreadsheet_safe_value', $row));
    }

    fclose($output);
    exit;
}
