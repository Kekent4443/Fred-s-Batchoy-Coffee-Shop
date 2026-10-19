<?php

declare(strict_types=1);

function ensure_auth_history_schema(): void
{
    if (!schema_maintenance_enabled()) {
        return;
    }
    // Production installations use database/migrations/2026_09_28_authentication_activity.sql.
    static $checked = false;
    if ($checked) {
        return;
    }
    db_execute('CREATE TABLE IF NOT EXISTS login_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL,
        full_name VARCHAR(150) NOT NULL, email VARCHAR(150) NOT NULL, role VARCHAR(30) NOT NULL,
        ip_address VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL,
        user_agent_hash CHAR(64) NOT NULL, login_source VARCHAR(20) NOT NULL DEFAULT \'password\',
        new_ip TINYINT(1) NOT NULL DEFAULT 0, new_browser TINYINT(1) NOT NULL DEFAULT 0,
        login_at DATETIME(6) NOT NULL, logout_at DATETIME(6) NULL,
        last_activity_at DATETIME(6) NOT NULL, end_reason VARCHAR(20) NULL,
        INDEX idx_login_history_login (login_at, id), INDEX idx_login_history_logout (logout_at, id),
        INDEX idx_login_history_role_login (role, login_at),
        INDEX idx_login_history_user_login (user_id, login_at),
        INDEX idx_login_history_activity (logout_at, last_activity_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db_execute('CREATE TABLE IF NOT EXISTS failed_logins (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL,
        email_tried VARCHAR(150) NOT NULL, role_tried VARCHAR(30) NULL,
        failure_reason VARCHAR(40) NOT NULL, lock_triggered TINYINT(1) NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) NOT NULL, user_agent VARCHAR(512) NOT NULL,
        attempted_at DATETIME(6) NOT NULL,
        INDEX idx_failed_logins_attempted (attempted_at, id),
        INDEX idx_failed_logins_role_attempted (role_tried, attempted_at),
        INDEX idx_failed_logins_email_attempted (email_tried, attempted_at),
        INDEX idx_failed_logins_user_attempted (user_id, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $checked = true;
}

function auth_history_schema_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $row = db_find("SELECT COUNT(DISTINCT table_name) AS tables_found
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name IN ('login_history', 'failed_logins')");
        $ready = (int) ($row['tables_found'] ?? 0) === 2;
    }
    return $ready;
}

function auth_history_now(): string
{
    return (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
}

function auth_history_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
}

function auth_history_ip_markup(?string $storedIp): string
{
    $ip = (string) $storedIp;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $version = 'IPv4';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $version = 'IPv6';
    } else {
        return '<span class="auth-records__ip-unknown">Unknown</span>';
    }

    return '<span class="auth-records__ip-value">' . e($ip) . '</span> '
        . '<span class="auth-records__badge auth-records__ip-version">' . $version . '</span>';
}

function auth_history_client_ip(): string
{
    // Do not record the shared client's localhost fallback as if it were a
    // real visitor address when the web server supplied no valid peer IP.
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? client_ip() : 'Unknown';
}

function auth_history_start(array $user, string $source = 'password'): void
{
    ensure_auth_history_schema();
    $userId = (int) $user['id'];
    $ip = auth_history_client_ip();
    $agent = auth_history_agent();
    $hash = hash('sha256', $agent);
    $prior = db_find('SELECT id FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 1', 'i', [$userId]);
    $newIp = $prior && !db_find('SELECT id FROM login_history WHERE user_id = ? AND ip_address = ? LIMIT 1', 'is', [$userId, $ip]);
    $newBrowser = $prior && !db_find('SELECT id FROM login_history WHERE user_id = ? AND user_agent_hash = ? LIMIT 1', 'is', [$userId, $hash]);
    $now = auth_history_now();
    db_execute('INSERT INTO login_history
        (user_id, full_name, email, role, ip_address, user_agent, user_agent_hash, login_source, new_ip, new_browser, login_at, last_activity_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'isssssssiiss', [
            $userId, (string) $user['full_name'], (string) $user['email'], (string) $user['role'],
            $ip, $agent, $hash, $source, (int) $newIp, (int) $newBrowser, $now, $now,
        ]);
    $_SESSION['login_history_id'] = db_last_id();
    $_SESSION['login_history_synced_at'] = time();
}

function auth_history_touch(): void
{
    $id = (int) ($_SESSION['login_history_id'] ?? 0);
    if ($id < 1 || time() - (int) ($_SESSION['login_history_synced_at'] ?? 0) < AUTH_ACTIVITY_SYNC_SECONDS) {
        return;
    }
    db_execute('UPDATE login_history SET last_activity_at = ? WHERE id = ? AND logout_at IS NULL',
        'si', [auth_history_now(), $id]);
    $_SESSION['login_history_synced_at'] = time();
}

function auth_history_end(string $reason = 'logout'): void
{
    $id = (int) ($_SESSION['login_history_id'] ?? 0);
    if ($id > 0) {
        $now = auth_history_now();
        db_execute('UPDATE login_history SET logout_at = ?, last_activity_at = ?, end_reason = ?
            WHERE id = ? AND logout_at IS NULL', 'sssi', [$now, $now, $reason, $id]);
    }
    unset($_SESSION['login_history_id'], $_SESSION['login_history_synced_at']);
}

function auth_history_revoke_user_sessions(int $userId, string $reason = 'revoked'): void
{
    if ($userId < 1 || !auth_history_schema_ready()) {
        return;
    }

    $reason = mb_substr(trim($reason), 0, 20);
    $reason = $reason !== '' ? $reason : 'revoked';
    $now = auth_history_now();
    db_execute(
        'UPDATE login_history
         SET logout_at = ?, last_activity_at = ?, end_reason = ?
         WHERE user_id = ? AND logout_at IS NULL',
        'sssi',
        [$now, $now, $reason, $userId]
    );
}

function auth_history_session_is_active(int $historyId, int $userId): bool
{
    if ($historyId < 1 || $userId < 1 || !auth_history_schema_ready()) {
        return false;
    }

    return db_find(
        'SELECT id FROM login_history
         WHERE id = ? AND user_id = ? AND logout_at IS NULL
         LIMIT 1',
        'ii',
        [$historyId, $userId]
    ) !== null;
}

function auth_account_is_locked(string $email): bool
{
    if (!login_rate_limit_enabled()) {
        return false;
    }
    $key = rate_limit_key('login_account_lock', $email, false);
    return db_find('SELECT rate_key FROM rate_limits WHERE rate_key = ? AND scope = ? AND expires_at > NOW() LIMIT 1',
        'ss', [$key, 'login_account_lock']) !== null;
}

function auth_history_failed(string $email, ?array $user, string $reason): void
{
    ensure_auth_history_schema();
    $email = mb_substr(strtolower(trim($email)), 0, 150);
    $userId = $user ? (int) $user['id'] : null;
    $role = $user ? (string) $user['role'] : null;
    $now = auth_history_now();
    db_execute('INSERT INTO failed_logins
        (user_id, email_tried, role_tried, failure_reason, ip_address, user_agent, attempted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)', 'issssss',
        [$userId, $email, $role, $reason, auth_history_client_ip(), auth_history_agent(), $now]);
    $failedId = db_last_id();
    if (!$user || $reason !== 'wrong_password' || !login_rate_limit_enabled()) {
        return;
    }
    $lastSuccess = db_find('SELECT login_at FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 1', 'i', [$userId]);
    $windowStart = (new DateTimeImmutable())->modify('-' . LOGIN_ACCOUNT_LOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
    $since = max((string) ($lastSuccess['login_at'] ?? ''), $windowStart);
    $recent = db_select('SELECT failure_reason FROM failed_logins
        WHERE user_id = ? AND attempted_at > ? ORDER BY id DESC LIMIT ' . LOGIN_ACCOUNT_LOCK_THRESHOLD, 'is', [$userId, $since]);
    if (count($recent) < LOGIN_ACCOUNT_LOCK_THRESHOLD
        || count(array_filter($recent, static fn (array $row): bool => $row['failure_reason'] === 'wrong_password')) < LOGIN_ACCOUNT_LOCK_THRESHOLD) {
        return;
    }
    if (auth_account_is_locked($email)) {
        return;
    }
    $key = rate_limit_key('login_account_lock', $email, false);
    db_execute('INSERT INTO rate_limits
        (rate_key, scope, attempts, first_attempt_at, last_attempt_at, expires_at)
        VALUES (?, ?, 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
        ON DUPLICATE KEY UPDATE attempts = 1, first_attempt_at = NOW(), last_attempt_at = NOW(), expires_at = VALUES(expires_at)',
        'ssi', [$key, 'login_account_lock', LOGIN_ACCOUNT_LOCK_SECONDS]);
    db_execute('UPDATE failed_logins SET lock_triggered = 1 WHERE id = ?', 'i', [$failedId]);
}

function auth_history_filters(array $input): array
{
    $tab = (string) ($input['tab'] ?? 'login');
    $tab = in_array($tab, ['login', 'logout', 'successful', 'failed'], true) ? $tab : 'login';
    $role = (string) ($input['role'] ?? '');
    $role = authentication_role_is_supported($role) ? $role : '';
    $period = (string) ($input['period'] ?? 'all');
    $period = in_array($period, ['all', 'today', 'week', 'custom'], true) ? $period : 'all';
    $fromInput = $input['from'] ?? '';
    $toInput = $input['to'] ?? '';
    $dateErrors = date_filter_errors($input, 'from', 'to');
    $dateFrom = parse_display_date($fromInput) ?? '';
    $dateTo = parse_display_date($toInput) ?? '';
    if (trim((string) (is_scalar($fromInput) ? $fromInput : '')) !== ''
        || trim((string) (is_scalar($toInput) ? $toInput : '')) !== '') {
        $period = 'custom';
    }
    $state = (string) ($input['state'] ?? '');
    $allowed = $tab === 'failed'
        ? ['unknown_account', 'wrong_password', 'inactive', 'pending_verification', 'rejected', 'unsupported_role', 'throttled', 'temporarily_locked']
        : ['open', 'logged_out'];
    return [
        'tab' => $tab, 'role' => $role, 'period' => $period,
        'from' => $dateFrom, 'to' => $dateTo,
        'from_input' => $fromInput, 'to_input' => $toInput, 'date_errors' => $dateErrors,
        'state' => in_array($state, $allowed, true) ? $state : '',
        'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 100),
    ];
}

function auth_history_where(array $filters): array
{
    if (!empty($filters['date_errors'])) {
        // Never broaden an invalid date request into an unfiltered audit query.
        return [' WHERE 1 = 0', '', []];
    }
    $failed = $filters['tab'] === 'failed';
    $dateColumn = $failed ? 'attempted_at' : ($filters['tab'] === 'logout' ? 'logout_at' : 'login_at');
    $clauses = [];
    $types = '';
    $values = [];
    if ($filters['tab'] === 'logout') {
        $clauses[] = 'logout_at IS NOT NULL';
    }
    if ($filters['role'] !== '') {
        $clauses[] = ($failed ? 'role_tried' : 'role') . ' = ?';
        $types .= 's';
        $values[] = $filters['role'];
    }
    if ($filters['search'] !== '') {
        $clauses[] = $failed
            ? '(email_tried LIKE ? OR EXISTS (
                SELECT 1 FROM users WHERE users.id = failed_logins.user_id AND users.full_name LIKE ?
            ))'
            : '(full_name LIKE ? OR email LIKE ?)';
        $search = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']) . '%';
        $types .= 'ss';
        $values[] = $search;
        $values[] = $search;
    }
    if ($filters['period'] === 'today') {
        $clauses[] = $dateColumn . ' >= ? AND ' . $dateColumn . ' < ?';
        $types .= 'ss';
        $values[] = date('Y-m-d 00:00:00');
        $values[] = date('Y-m-d 00:00:00', strtotime('tomorrow'));
    } elseif ($filters['period'] === 'week') {
        $clauses[] = $dateColumn . ' >= ? AND ' . $dateColumn . ' < ?';
        $types .= 'ss';
        $values[] = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $values[] = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } elseif ($filters['period'] === 'custom') {
        if ($filters['from'] !== '') {
            $clauses[] = $dateColumn . ' >= ?';
            $types .= 's';
            $values[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $clauses[] = $dateColumn . ' < ?';
            $types .= 's';
            $values[] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
    }
    if ($filters['state'] !== '') {
        if ($failed) {
            $clauses[] = 'failure_reason = ?';
            $types .= 's';
            $values[] = $filters['state'];
        } else {
            $clauses[] = $filters['state'] === 'open' ? 'logout_at IS NULL' : 'logout_at IS NOT NULL';
        }
    }
    return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $types, $values];
}

function auth_history_page(array $filters, int $page = 1, int $perPage = 20): array
{
    $failed = $filters['tab'] === 'failed';
    $table = $failed ? 'failed_logins' : 'login_history';
    $sort = $filters['tab'] === 'logout' ? 'logout_at' : ($failed ? 'attempted_at' : 'login_at');
    [$where, $types, $values] = auth_history_where($filters);
    $total = (int) (db_find('SELECT COUNT(*) AS total FROM ' . $table . $where, $types, $values)['total'] ?? 0);
    $perPage = max(1, min(100, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    $limit = ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
    $columns = $failed
        ? 'failed_logins.*, (SELECT users.full_name FROM users WHERE users.id = failed_logins.user_id LIMIT 1) AS account_name'
        : '*';
    $rows = db_select('SELECT ' . $columns . ' FROM ' . $table . $where . ' ORDER BY ' . $sort . ' DESC, id DESC' . $limit, $types, $values);
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function auth_history_summary(): array
{
    $today = date('Y-m-d 00:00:00');
    $tomorrow = date('Y-m-d 00:00:00', strtotime('tomorrow'));
    $onlineSince = date('Y-m-d H:i:s', time() - ONLINE_WINDOW_SECONDS);
    return [
        'logins_today' => (int) (db_find('SELECT COUNT(*) AS n FROM login_history WHERE login_at >= ? AND login_at < ?', 'ss', [$today, $tomorrow])['n'] ?? 0),
        'active_now' => (int) (db_find('SELECT COUNT(DISTINCT user_id) AS n FROM login_history WHERE logout_at IS NULL AND last_activity_at >= ?', 's', [$onlineSince])['n'] ?? 0),
        'failed_today' => (int) (db_find('SELECT COUNT(*) AS n FROM failed_logins WHERE attempted_at >= ? AND attempted_at < ?', 'ss', [$today, $tomorrow])['n'] ?? 0),
        'roles' => db_select('SELECT role, COUNT(*) AS n FROM login_history WHERE login_at >= ? AND login_at < ? GROUP BY role ORDER BY n DESC', 'ss', [$today, $tomorrow]),
    ];
}
