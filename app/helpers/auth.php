<?php

declare(strict_types=1);

function remember_cookie_name(): string
{
    // Retain the established name so existing valid customer tokens continue
    // working after Remember Me is expanded to every supported account role.
    return 'freds_customer_remember';
}

function authentication_role_is_supported(string $role): bool
{
    return in_array($role, ['administrator', 'manager', 'staff', 'rider', 'customer'], true);
}

function remember_cookie_path(): string
{
    $path = parse_url(APP_URL, PHP_URL_PATH) ?: '/';
    $path = str_replace('\\', '/', $path);

    return preg_match('/[,\; \t\r\n\013\014]/', $path) ? '/' : $path;
}

function ensure_remember_tokens_table(): void
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
        'CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remember_tokens_expires (expires_at),
            CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $checked = true;
}

function set_remember_cookie(string $selector, string $token, int $expiresAt): void
{
    setcookie(remember_cookie_name(), $selector . ':' . $token, [
        'expires' => $expiresAt,
        'path' => remember_cookie_path(),
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void
{
    setcookie(remember_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => remember_cookie_path(),
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function create_remember_login(int $userId): void
{
    ensure_remember_tokens_table();

    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + (60 * 60 * 24 * 30);

    db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);
    db_execute(
        'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())',
        'isss',
        [$userId, $selector, hash('sha256', $token), date('Y-m-d H:i:s', $expiresAt)]
    );

    set_remember_cookie($selector, $token, $expiresAt);
}

function clear_remember_login(?int $userId = null): void
{
    ensure_remember_tokens_table();

    $cookie = (string) ($_COOKIE[remember_cookie_name()] ?? '');
    $selector = explode(':', $cookie, 2)[0] ?? '';

    if (preg_match('/^[a-f0-9]{24}$/', $selector) === 1) {
        db_execute('DELETE FROM remember_tokens WHERE selector = ?', 's', [$selector]);
    }

    if ($userId !== null) {
        db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);
    }

    clear_remember_cookie();
    unset($_COOKIE[remember_cookie_name()]);
}

function revoke_user_remember_tokens(int $userId): void
{
    if ($userId < 1) {
        return;
    }

    ensure_remember_tokens_table();
    db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);
}

function clear_current_user_cache(): void
{
    unset($GLOBALS['freds_current_user_cache'], $GLOBALS['freds_current_user_cache_resolved']);
}

function set_current_user_cache(?array $user): void
{
    $GLOBALS['freds_current_user_cache'] = $user;
    $GLOBALS['freds_current_user_cache_resolved'] = true;
}

function revoke_user_authentication(int $userId, bool $clearCurrentSession = true, bool $rotateCsrf = true): void
{
    if ($userId < 1) {
        return;
    }

    revoke_user_remember_tokens($userId);

    if ($clearCurrentSession && (int) ($_SESSION['user_id'] ?? 0) === $userId) {
        auth_history_end('revoked');
        clear_remember_cookie();
        unset($_COOKIE[remember_cookie_name()]);
        unset(
            $_SESSION['user_id'],
            $_SESSION['checkout'],
            $_SESSION['authenticated_at'],
            $_SESSION['last_activity'],
            $_SESSION['last_regenerated_at'],
            $_SESSION['login_history_id'],
            $_SESSION['login_history_synced_at']
        );

        if ($rotateCsrf) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        clear_current_user_cache();
    }
}

function restore_remembered_user(): bool
{
    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    $cookie = (string) ($_COOKIE[remember_cookie_name()] ?? '');

    if ($cookie === '') {
        return false;
    }

    if (preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/D', $cookie, $matches) !== 1) {
        clear_remember_cookie();
        unset($_COOKIE[remember_cookie_name()]);
        return false;
    }

    $selector = $matches[1];
    $token = $matches[2];

    ensure_remember_tokens_table();

    $remember = db_find(
        'SELECT rt.*, u.full_name, u.email, u.role, u.status, u.deleted_at
         FROM remember_tokens rt
         INNER JOIN users u ON u.id = rt.user_id
         WHERE rt.selector = ? LIMIT 1',
        's',
        [$selector]
    );

    if (
        !$remember
        || strtotime((string) $remember['expires_at']) < time()
        || ($remember['status'] ?? '') !== 'active'
        || !empty($remember['deleted_at'])
        || !authentication_role_is_supported((string) ($remember['role'] ?? ''))
    ) {
        clear_remember_login();
        return false;
    }

    if (!hash_equals((string) $remember['token_hash'], hash('sha256', $token))) {
        clear_remember_login((int) $remember['user_id']);
        return false;
    }

    if (auth_account_is_locked((string) $remember['email'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $remember['user_id'];
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['last_regenerated_at'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    clear_current_user_cache();
    create_remember_login((int) $remember['user_id']);
    auth_history_start($remember, 'remember');

    return true;
}

function current_user(): ?array
{
    if (!empty($GLOBALS['freds_current_user_cache_resolved'])) {
        $cached = $GLOBALS['freds_current_user_cache'] ?? null;

        return is_array($cached) ? $cached : null;
    }

    if (empty($_SESSION['user_id'])) {
        restore_remembered_user();
    }

    if (empty($_SESSION['user_id'])) {
        set_current_user_cache(null);
        return null;
    }

    $user = db_find(
        'SELECT id, full_name, email, email_verified_at, phone, address, delivery_street, delivery_barangay, delivery_city, delivery_province, delivery_landmark,
                role, status, profile_image, created_at, deleted_at
         FROM users WHERE id = ? LIMIT 1',
        'i',
        [(int) $_SESSION['user_id']]
    );

    if (!$user) {
        auth_history_end('revoked');
        unset($_SESSION['user_id']);
        set_current_user_cache(null);
        return null;
    }

    if (($user['status'] ?? '') !== 'active' || !empty($user['deleted_at'])) {
        auth_history_end('revoked');
        clear_remember_login((int) $user['id']);
        unset(
            $_SESSION['user_id'],
            $_SESSION['checkout'],
            $_SESSION['authenticated_at'],
            $_SESSION['last_activity'],
            $_SESSION['last_regenerated_at']
        );
        set_current_user_cache(null);
        return null;
    }

    set_current_user_cache($user);

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(array|string $roles): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    $roles = (array) $roles;

    return in_array($user['role'], $roles, true);
}

function can_manage_customer_verifications(?array $user = null): bool
{
    $user ??= current_user();

    if (!$user || ($user['status'] ?? '') !== 'active' || !empty($user['deleted_at'])) {
        return false;
    }

    return in_array((string) ($user['role'] ?? ''), ['administrator', 'manager', 'staff'], true);
}

function require_login(array|string|null $roles = null): void
{
    if (!is_logged_in()) {
        if (request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => false,
                'message' => !empty($_SESSION['auth_expired'])
                    ? 'Your session expired. Please log in again.'
                    : 'Please log in to continue.',
            ]);
            exit;
        }

        flash(
            'auth',
            !empty($_SESSION['auth_expired'])
                ? 'Your session expired for security. Please log in again.'
                : 'Please log in to continue.',
            'warning'
        );
        redirect('auth/login.php');
    }

    if ($roles !== null && !has_role($roles)) {
        $user = current_user();

        if (request_expects_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
            exit;
        }

        flash('auth', 'Your account does not have permission to access that page.', 'danger');
        redirect(role_home((string) ($user['role'] ?? 'customer')));
    }
}

function require_developer_tools_access(bool $apiDocumentation = false): void
{
    require_login('administrator');

    $enabled = $apiDocumentation
        ? (defined('API_DOCS_ENABLED') && API_DOCS_ENABLED)
        : (defined('DEV_TOOLS_ENABLED') && DEV_TOOLS_ENABLED);

    if ($enabled) {
        return;
    }

    http_response_code(404);
    header('Cache-Control: no-store');

    if (request_expects_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not found.']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found.';
    }

    exit;
}

function maintain_secure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $now = time();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return;
    }

    $historyId = (int) ($_SESSION['login_history_id'] ?? 0);
    if ($historyId < 1 || !auth_history_session_is_active($historyId, $userId)) {
        revoke_user_authentication($userId, true, false);
        $_SESSION['auth_expired'] = true;

        if (!request_expects_json()) {
            flash('auth', 'Your session is no longer valid. Please log in again.', 'warning');
        }

        return;
    }

    $_SESSION['authenticated_at'] ??= $now;
    $_SESSION['last_activity'] ??= $now;
    $_SESSION['last_regenerated_at'] ??= $now;

    $idleExpired = ($now - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS;
    $absoluteExpired = ($now - (int) $_SESSION['authenticated_at']) > SESSION_ABSOLUTE_TIMEOUT_SECONDS;

    if ($idleExpired || $absoluteExpired) {
        auth_history_end('expired');
        revoke_user_authentication($userId, true, false);
        $_SESSION['auth_expired'] = true;

        if (!request_expects_json()) {
            flash('auth', 'Your session expired for security. Please log in again.', 'warning');
        }

        return;
    }

    unset($_SESSION['auth_expired']);

    if (request_is_background_poll()) {
        return;
    }

    if (($now - (int) $_SESSION['last_regenerated_at']) >= SESSION_REGENERATE_INTERVAL_SECONDS) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated_at'] = $now;
    }

    $_SESSION['last_activity'] = $now;
    auth_history_touch();
}

function set_login_failure_reason(string $reason): void
{
    $GLOBALS['freds_login_failure_reason'] = $reason;
}

function login_failure_reason(): string
{
    return (string) ($GLOBALS['freds_login_failure_reason'] ?? 'credentials');
}

function login_safe_user_record(array $user): array
{
    return array_intersect_key($user, array_flip([
        'id',
        'full_name',
        'email',
        'phone',
        'address',
        'role',
        'status',
        'deleted_at',
        'email_verified_at',
        'profile_image',
        'created_at',
    ]));
}

function login_user(string $email, string $password, bool $remember = false): bool
{
    set_login_failure_reason('credentials');
    $email = strtolower(trim($email));
    $rateIdentifier = $email !== '' ? $email : '(empty)';
    $user = db_find('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1', 's', [$email]);

    // Always perform one password verification so unknown accounts do not
    // have a materially cheaper authentication path than known accounts.
    $dummyHash = '$2y$10$G6DiAMxDNdq6weS8ug0uwevJJ93xgcsRcU6jhEnu3Qo1W0stDijAK';
    $candidateHash = is_array($user) ? (string) ($user['password'] ?? '') : '';
    $hashInfo = $candidateHash !== '' ? password_get_info($candidateHash) : [];
    $verificationHash = !empty($hashInfo['algo']) ? $candidateHash : $dummyHash;
    $passwordValid = password_verify($password, $verificationHash);
    $testerLogin = $passwordValid
        && is_array($user)
        && (string) ($user['status'] ?? '') === 'active'
        && empty($user['deleted_at'])
        && authentication_role_is_supported((string) ($user['role'] ?? ''))
        && tester_email_is_authorized($email);
    $rateScope = $testerLogin ? 'tester_login' : 'login';
    $rateLimit = $testerLogin ? TESTER_LOGIN_RATE_LIMIT_MAX : 100;

    if (login_rate_limit_enabled()) {
        $ipAllowed = rate_limit_allow($rateScope . '_ip', '', $rateLimit, 5 * 60);
        $pairAllowed = rate_limit_allow($rateScope . '_pair', $rateIdentifier, $rateLimit, 5 * 60);
        $accountAllowed = rate_limit_allow($rateScope . '_account', $rateIdentifier, $rateLimit, 5 * 60, false);

        if (!$ipAllowed || !$pairAllowed || !$accountAllowed) {
            set_login_failure_reason('throttled');
            auth_history_failed($email, $user, 'throttled');
            return false;
        }
    }

    if ($email !== '' && auth_account_is_locked($email)) {
        set_login_failure_reason('throttled');
        auth_history_failed($email, $user, 'temporarily_locked');
        return false;
    }

    if (!$user || !$passwordValid) {
        auth_history_failed($email, $user, $user ? 'wrong_password' : 'unknown_account');
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $status = (string) ($user['status'] ?? '');
    $roleAllowed = authentication_role_is_supported($role);

    if (!$roleAllowed) {
        set_login_failure_reason('role');
        auth_history_failed($email, $user, 'unsupported_role');
        return false;
    }

    if ($status !== 'active') {
        set_login_failure_reason(in_array($status, ['pending_verification', 'rejected', 'inactive'], true) ? $status : 'inactive');
        auth_history_failed($email, $user, login_failure_reason());
        return false;
    }

    if (!empty($user['deleted_at'])) {
        set_login_failure_reason('inactive');
        auth_history_failed($email, $user, 'inactive');
        return false;
    }

    if (password_needs_rehash($candidateHash, PASSWORD_DEFAULT)) {
        $replacementHash = password_hash($password, PASSWORD_DEFAULT);
        db_execute('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', 'si', [$replacementHash, (int) $user['id']]);
        $user['password'] = $replacementHash;
    }

    ensure_remember_tokens_table();

    auth_history_end('replaced');
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['last_regenerated_at'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['auth_expired']);
    set_current_user_cache(login_safe_user_record($user));

    if ($remember) {
        create_remember_login((int) $user['id']);
    } else {
        // Revoke only the selector carried by this browser. Do not sign the
        // user out of a different remembered browser when this login is meant
        // to create a normal session only.
        clear_remember_login();
    }

    if (login_rate_limit_enabled()) {
        rate_limit_clear($rateScope . '_pair', $rateIdentifier);
        rate_limit_clear($rateScope . '_account', $rateIdentifier, false);
    }
    auth_history_start($user);
    log_audit((int) $user['id'], 'login', 'User logged in');
    set_login_failure_reason('');

    return true;
}

function logout_user(): void
{
    $user = current_user();

    if ($user) {
        log_audit((int) $user['id'], 'logout', 'User logged out');
    }

    auth_history_end('logout');

    clear_remember_login($user ? (int) $user['id'] : null);

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $cookieOptions = [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ];

        if (!empty($params['domain'])) {
            $cookieOptions['domain'] = $params['domain'];
        }

        setcookie(session_name(), '', $cookieOptions);
    }

    $sessionName = session_name();
    session_destroy();
    clear_current_user_cache();

    // Start a clean anonymous session so the caller can persist a logout
    // confirmation flash message after the authenticated session is destroyed.
    unset($_COOKIE[$sessionName]);
    session_id('');
    session_start();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['last_regenerated_at'] = time();
}

function role_home(string $role): string
{
    return match ($role) {
        'administrator' => 'admin/dashboard.php',
        'manager' => 'manager/dashboard.php',
        'staff' => 'staff/dashboard.php',
        'rider' => 'rider/dashboard.php',
        'customer' => 'customer/catalog.php',
        default => 'customer/dashboard.php',
    };
}
