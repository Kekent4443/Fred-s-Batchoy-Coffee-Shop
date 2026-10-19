<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';
$host = database_setting('DB_HOST', 'host', '127.0.0.1');
if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Disposable authentication tests require a local database host.');
}

function auth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function auth_test_schema(mysqli $db, string $sql): void
{
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
    if ($db->errno) {
        throw new RuntimeException('Disposable schema import failed.');
    }
}

$databaseName = 'freds_auth_test_' . bin2hex(random_bytes(5));
auth_test_assert((bool) preg_match('/^freds_auth_test_[a-f0-9]{10}$/', $databaseName), 'Unsafe test database name.');
$admin = new mysqli($host, database_setting('DB_USER', 'user', 'root'), database_setting('DB_PASS', 'password'), '', (int) database_setting('DB_PORT', 'port', '3306'));
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('TESTER_AUTH_RATE_LIMITS_ENABLED=1');
    putenv('TESTER_EMAILS=tester@auth-history.example');
    putenv('APP_AUTO_MIGRATE=0');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.2';
    $_SERVER['HTTP_USER_AGENT'] = 'AuthHistoryTest/1';
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    set_exception_handler(static function (Throwable $exception): void {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    });
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    auth_test_schema(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    auth_test_schema(get_db(), (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_28_authentication_activity.sql'));
    auth_test_schema(get_db(), (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_28_authentication_activity.sql'));

    $roles = ['administrator', 'manager', 'staff', 'rider', 'customer'];
    $password = 'AuthHistoryTest123!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    foreach ($roles as $role) {
        $email = $role . '@auth-history.example';
        db_execute('INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sssssss', [ucfirst($role) . ' Test', $email, $hash, '0000000000', 'Test address', $role, 'active']);
        auth_test_assert(login_user($email, $password), 'Successful ' . $role . ' login failed.');
        $sessionId = (int) ($_SESSION['login_history_id'] ?? 0);
        auth_test_assert($sessionId > 0, 'No login-history session ID for ' . $role);
        auth_test_assert(db_find('SELECT id FROM login_history WHERE id = ? AND logout_at IS NULL', 'i', [$sessionId]) !== null, 'Login history missing.');
        logout_user();
        auth_test_assert(db_find('SELECT id FROM login_history WHERE id = ? AND logout_at IS NOT NULL', 'i', [$sessionId]) !== null, 'Logout did not close history session.');
    }
    auth_test_assert((int) (db_find('SELECT COUNT(*) AS n FROM login_history')['n'] ?? 0) === 5, 'Duplicate success rows created.');

    $testerEmail = 'tester@auth-history.example';
    db_execute('INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'sssssss', ['Approved Tester', $testerEmail, $hash, '0000000000', 'Test address', 'manager', 'active']);
    auth_test_assert(tester_email_is_authorized($testerEmail)
        && !tester_email_is_authorized('other@auth-history.example'), 'Tester allowance is not restricted to the configured account.');
    for ($attempt = 0; $attempt < LOGIN_ACCOUNT_LOCK_THRESHOLD; $attempt++) {
        auth_test_assert(!login_user($testerEmail, 'Wrong123!'), 'Wrong tester password authenticated.');
    }
    auth_test_assert(auth_account_is_locked($testerEmail), 'Approved tester wrong-password attempts did not trigger a lock.');
    db_execute('UPDATE rate_limits SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE scope = ? AND rate_key = ?',
        'ss', ['login_account_lock', rate_limit_key('login_account_lock', $testerEmail, false)]);
    auth_test_assert(login_user($testerEmail, $password), 'Approved tester could not log in after lock expiry.');
    auth_test_assert((int) (db_find('SELECT attempts FROM rate_limits WHERE scope = ? AND rate_key = ?',
        'ss', ['tester_login_ip', rate_limit_key('tester_login_ip')])['attempts'] ?? 0) === 1,
        'Approved tester login did not use a finite, separate rate-limit bucket.');
    $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    $originalPost = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/auth/login.php';
    $_SERVER['SCRIPT_NAME'] = '/public/auth/login.php';
    $_POST = ['email' => $testerEmail];
    enforce_post_rate_limit();
    auth_test_assert(db_find('SELECT rate_key FROM rate_limits WHERE scope = ?', 's', ['post:tester-login']) !== null,
        'Allowlisted login POST did not use a finite tester bucket.');
    $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/logout.php';
    $_SERVER['SCRIPT_NAME'] = '/public/logout.php';
    $_POST = [];
    enforce_post_rate_limit();
    auth_test_assert(db_find('SELECT rate_key FROM rate_limits WHERE scope = ?', 's', ['post:tester-logout']) !== null,
        'Authenticated tester logout POST did not use a finite tester bucket.');
    $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/contact.php';
    $_SERVER['SCRIPT_NAME'] = '/public/contact.php';
    $_POST = ['email' => $testerEmail];
    enforce_post_rate_limit();
    $ordinaryPostScope = 'post:' . substr(hash('sha256', '/public/contact.php'), 0, 16);
    auth_test_assert(db_find('SELECT rate_key FROM rate_limits WHERE scope = ?', 's', [$ordinaryPostScope]) !== null,
        'An allowlisted email bypassed an unrelated POST rate limit.');
    $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
    $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
    $_POST = $originalPost;
    logout_user();
    db_execute('UPDATE rate_limits SET attempts = ? WHERE scope = ? AND rate_key = ?', 'iss',
        [TESTER_LOGIN_RATE_LIMIT_MAX, 'tester_login_ip', rate_limit_key('tester_login_ip')]);
    auth_test_assert(!login_user($testerEmail, $password) && login_failure_reason() === 'throttled',
        'Approved tester login exceeded its finite rate limit.');
    auth_test_assert(rate_limit_allow('tester_other_action', $testerEmail, 1, 60)
        && !rate_limit_allow('tester_other_action', $testerEmail, 1, 60),
        'Tester allowlist bypassed an unrelated action rate limit.');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.3';
    $_SERVER['HTTP_USER_AGENT'] = 'AuthHistoryTest/2';
    auth_test_assert(login_user('administrator@auth-history.example', $password), 'Second administrator login failed.');
    $newEnvironment = db_find('SELECT new_ip, new_browser FROM login_history ORDER BY id DESC LIMIT 1');
    auth_test_assert((int) $newEnvironment['new_ip'] === 1 && (int) $newEnvironment['new_browser'] === 1, 'New IP/browser signature was not flagged.');
    logout_user();

    auth_test_assert(!login_user('missing@auth-history.example', 'Wrong123!'), 'Unknown account authenticated.');
    auth_test_assert((string) (db_find('SELECT failure_reason FROM failed_logins ORDER BY id DESC LIMIT 1')['failure_reason'] ?? '') === 'unknown_account', 'Unknown account not audited.');
    db_execute('UPDATE users SET status = ? WHERE email = ?', 'ss', ['inactive', 'rider@auth-history.example']);
    auth_test_assert(!login_user('rider@auth-history.example', $password), 'Inactive account authenticated.');
    auth_test_assert((string) (db_find('SELECT failure_reason FROM failed_logins ORDER BY id DESC LIMIT 1')['failure_reason'] ?? '') === 'inactive', 'Inactive attempt not audited.');

    $email = 'customer@auth-history.example';
    for ($attempt = 0; $attempt < LOGIN_ACCOUNT_LOCK_THRESHOLD; $attempt++) {
        auth_test_assert(!login_user($email, 'Wrong123!'), 'Wrong password authenticated.');
    }
    auth_test_assert(auth_account_is_locked($email), 'Repeated wrong passwords did not trigger temporary lock.');
    auth_test_assert(!login_user($email, $password), 'Temporary lock allowed a correct password.');
    db_execute('UPDATE rate_limits SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE scope = ?', 's', ['login_account_lock']);
    auth_test_assert(login_user($email, $password), 'Login did not recover after lock expiry.');
    logout_user();

    $filters = auth_history_filters(['tab' => 'failed', 'role' => 'customer', 'period' => 'today']);
    $page = auth_history_page($filters, 1, 20);
    $failedRows = $page['rows'];
    auth_test_assert($page['total'] >= LOGIN_ACCOUNT_LOCK_THRESHOLD, 'Failed role/date filter missed attempts.');
    auth_test_assert(count($page['rows']) <= 20, 'Pagination exceeded page size.');
    $failedNameSearch = auth_history_page(auth_history_filters(['tab' => 'failed', 'search' => 'Customer Test']), 1);
    auth_test_assert($failedNameSearch['total'] >= LOGIN_ACCOUNT_LOCK_THRESHOLD
        && ($failedNameSearch['rows'][0]['account_name'] ?? '') === 'Customer Test', 'Failed attempts cannot be searched by the linked account name.');
    auth_test_assert(auth_history_page(auth_history_filters(['tab' => 'failed', 'search' => 'missing@auth-history.example']), 1)['total'] === 1,
        'Unknown-account attempts cannot be searched by submitted email.');
    $logout = auth_history_page(auth_history_filters(['tab' => 'logout']), 1);
    auth_test_assert($logout['total'] >= 7, 'Logout view missed closed sessions.');
    $summary = auth_history_summary();
    auth_test_assert($summary['logins_today'] >= 7 && $summary['failed_today'] >= 7, 'Summary counts are incorrect.');
    for ($index = 0; $index < 25; $index++) {
        auth_history_failed('pagination-' . $index . '@auth-history.example', null, 'unknown_account');
    }
    $firstPage = auth_history_page(auth_history_filters(['tab' => 'failed']), 1);
    $secondPage = auth_history_page(auth_history_filters(['tab' => 'failed']), 2);
    auth_test_assert(count($firstPage['rows']) === 20 && count($secondPage['rows']) > 0 && $firstPage['rows'][0]['id'] > $secondPage['rows'][0]['id'], 'SQL pagination/newest-first ordering failed.');
    auth_test_assert(auth_history_page(auth_history_filters(['tab' => 'successful', 'search' => 'manager@auth-history.example']), 1)['total'] === 1, 'Success search failed.');
    auth_test_assert(auth_history_page(auth_history_filters(['tab' => 'login', 'period' => 'week']), 1)['total'] >= 7, 'This Week filter failed.');
    auth_test_assert(auth_history_page(auth_history_filters(['tab' => 'login', 'period' => 'custom', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')]), 1)['total'] >= 7, 'Custom date range failed.');
    $historyBoundaryRows = [
        ['2024-03-05 00:00:00.000000', '2024-03-05 23:59:59.999999'],
        ['2024-03-05 23:59:59.999999', '2024-03-06 00:00:00.000000'],
        ['2024-03-04 23:59:59.999999', '2024-03-05 00:00:00.000000'],
    ];
    $historyBoundaryIds = [];
    foreach ($historyBoundaryRows as [$loginAt, $logoutAt]) {
        db_execute('INSERT INTO login_history
            (full_name, email, role, ip_address, user_agent, user_agent_hash, login_source, login_at, logout_at, last_activity_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'ssssssssss', [
                'Boundary Test', 'boundary-test@auth-history.example', 'staff', '127.0.0.1',
                'AuthHistoryBoundaryTest/1', str_repeat('a', 64), 'password', $loginAt, $logoutAt, $logoutAt,
            ]);
        $historyBoundaryIds[] = db_last_id();
    }
    $failedBoundaryIds = [];
    foreach (['2024-03-05 00:00:00.000000', '2024-03-05 23:59:59.999999', '2024-03-06 00:00:00.000000'] as $attemptedAt) {
        db_execute('INSERT INTO failed_logins (email_tried, failure_reason, ip_address, user_agent, attempted_at)
            VALUES (?, ?, ?, ?, ?)', 'sssss', [
                'boundary-test@auth-history.example', 'wrong_password', '127.0.0.1', 'AuthHistoryBoundaryTest/1', $attemptedAt,
            ]);
        $failedBoundaryIds[] = db_last_id();
    }
    foreach (['login', 'logout', 'successful', 'failed'] as $tab) {
        $boundaryFilters = auth_history_filters(['tab' => $tab, 'period' => 'all', 'search' => 'boundary-test', 'from' => '03/05/24', 'to' => '03/05/24']);
        auth_test_assert($boundaryFilters['period'] === 'custom' && $boundaryFilters['from'] === '2024-03-05'
            && $boundaryFilters['to'] === '2024-03-05' && $boundaryFilters['date_errors'] === [], $tab . ' did not parse MM/DD/YY dates.');
        $boundaryPage = auth_history_page($boundaryFilters, 1);
        $expectedIds = $tab === 'failed' ? array_slice($failedBoundaryIds, 0, 2)
            : ($tab === 'logout' ? [$historyBoundaryIds[0], $historyBoundaryIds[2]] : array_slice($historyBoundaryIds, 0, 2));
        $actualIds = array_map('intval', array_column($boundaryPage['rows'], 'id'));
        sort($actualIds);
        sort($expectedIds);
        auth_test_assert($boundaryPage['total'] === 2 && $actualIds === $expectedIds,
            $tab . ' same-day range missed a boundary or included the next day.');
    }
    $fromOnly = auth_history_page(auth_history_filters(['tab' => 'login', 'search' => 'boundary-test', 'from' => '03/05/24']), 1);
    $toOnly = auth_history_page(auth_history_filters(['tab' => 'login', 'search' => 'boundary-test', 'to' => '03/05/24']), 1);
    auth_test_assert($fromOnly['total'] === 2 && $toOnly['total'] === 3, 'Open-ended MM/DD/YY date ranges filtered incorrectly.');
    foreach ([
        ['from' => '31/12/24', 'to' => '03/05/24'],
        ['from' => '03/06/24', 'to' => '03/05/24'],
        ['from' => ['03/05/24'], 'to' => '03/05/24'],
        ['from' => '02/29/25', 'to' => ''],
    ] as $invalidDates) {
        $invalidFilters = auth_history_filters(['tab' => 'login', 'search' => 'boundary-test'] + $invalidDates);
        auth_test_assert($invalidFilters['date_errors'] !== [] && auth_history_page($invalidFilters, 1)['total'] === 0,
            'An invalid or reversed authentication date range returned records.');
    }
    auth_test_assert(auth_history_filters(['from' => '02/29/24'])['date_errors'] === [], 'Valid leap day was rejected.');
    auth_test_assert(auth_history_page(auth_history_filters(['tab' => 'logout', 'state' => 'open']), 1)['total'] === 0, 'Logout tab included open sessions.');
    $_SERVER['REMOTE_ADDR'] = '::1';
    auth_test_assert(login_user('administrator@auth-history.example', $password), 'Administrator page test login failed.');
    $activeSessionId = (int) ($_SESSION['login_history_id'] ?? 0);
    auth_test_assert($activeSessionId > 0 && auth_history_summary()['active_now'] >= 1, 'An active session was not counted as online.');
    auth_test_assert((string) (db_find('SELECT ip_address FROM login_history WHERE id = ?', 'i', [$activeSessionId])['ip_address'] ?? '') === '::1',
        'IPv6 client IP was not recorded unchanged.');
    db_execute('UPDATE login_history SET last_activity_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?',
        'ii', [ONLINE_WINDOW_SECONDS + 60, $activeSessionId]);
    auth_test_assert(auth_history_summary()['active_now'] === 0, 'A stale session was still counted as online.');
    $_SESSION['login_history_synced_at'] = time() - AUTH_ACTIVITY_SYNC_SECONDS - 1;
    auth_history_touch();
    auth_test_assert(auth_history_summary()['active_now'] >= 1, 'Session activity did not restore online status.');
    db_execute(
        'INSERT INTO failed_logins (email_tried, failure_reason, ip_address, user_agent, attempted_at)
         VALUES (?, "unknown_account", ?, "AuthHistoryTest/invalid", NOW(6))',
        'ss',
        ['invalid-ip@auth-history.example', 'not-an-ip']
    );
    $_GET = ['tab' => 'failed'];
    ob_start();
    include APP_ROOT . '/public/admin/auth-records.php';
    $html = (string) ob_get_clean();
    auth_test_assert(str_contains($html, 'Login History') && str_contains($html, 'Logout History')
        && str_contains($html, 'Successful') && str_contains($html, 'Failed')
        && str_contains($html, 'Export CSV') && str_contains($html, 'Export PDF'), 'Admin records page did not render all views/exports.');
    auth_test_assert(!str_contains($html, 'delete_record') && !str_contains($html, 'archive_record'), 'Auth records page contains business-record mutation actions.');
    auth_test_assert(str_contains($html, 'auth-records.php?') && str_contains($html, 'Authentication Records'), 'Authentication records links use the wrong route.');
    auth_test_assert(str_contains($html, 'id="auth-records-tabs"')
        && substr_count($html, '#auth-records-tabs') === 4
        && str_contains($html, 'auth-records.js'), 'Authentication tabs do not preserve their position.');
    auth_test_assert(str_contains($html, 'auth-records__table--failed')
        && str_contains($html, 'auth-records__failed')
        && str_contains($html, 'data-label="Account / Email Tried"')
        && str_contains($html, 'data-label="Browser / User Agent"'), 'Failed audit records lack responsive labels.');
    auth_test_assert(str_contains($html, '>IPv4</span>') && str_contains($html, 'auth-records__ip-unknown">Unknown</span>'),
        'Failed audit records did not distinguish valid and invalid IPs.');
    $_GET = ['tab' => 'login'];
    ob_start();
    include APP_ROOT . '/public/admin/auth-records.php';
    $loginHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($loginHtml, 'auth-records__table--login')
        && str_contains($loginHtml, 'data-label="Account"')
        && str_contains($loginHtml, 'data-label="Last Activity"')
        && str_contains($loginHtml, 'data-label="Flags"'), 'Login audit records lack responsive labels.');
    auth_test_assert(str_contains($loginHtml, '>::1</span>') && str_contains($loginHtml, '>IPv6</span>'),
        'Login audit records did not preserve and classify the IPv6 address.');
    foreach (['login', 'logout', 'successful', 'failed'] as $tab) {
        $_GET = ['tab' => $tab, 'search' => 'boundary-test', 'from' => '03/05/24', 'to' => '03/05/24'];
        ob_start();
        include APP_ROOT . '/public/admin/auth-records.php';
        $datedHtml = (string) ob_get_clean();
        auth_test_assert(str_contains($datedHtml, 'auth-records__table--' . $tab)
            && preg_match('/name="from"[^>]*value="03\/05\/24"[^>]*placeholder="MM\/DD\/YY"/', $datedHtml) === 1
            && preg_match('/name="to"[^>]*value="03\/05\/24"[^>]*placeholder="MM\/DD\/YY"/', $datedHtml) === 1,
            $tab . ' did not render the selected MM/DD/YY date values.');
    }
    $_GET = ['tab' => 'failed', 'from' => '03/06/24', 'to' => '03/05/24'];
    ob_start();
    include APP_ROOT . '/public/admin/auth-records.php';
    $invalidDateHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($invalidDateHtml, 'End date must be the same as or later than the start date.')
        && str_contains($invalidDateHtml, 'aria-invalid="true"')
        && str_contains($invalidDateHtml, 'Correct the date filters to view authentication records.'),
        'Invalid authentication date range was not shown as a validation error.');
    $customerId = (int) (db_find('SELECT id FROM users WHERE email = ?', 's', ['customer@auth-history.example'])['id'] ?? 0);
    db_execute('INSERT INTO orders (reference_no, customer_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
        'si', ['RESPONSIVE-RECORD-TEST', $customerId]);
    $_GET = ['record_type' => 'all'];
    ob_start();
    include APP_ROOT . '/public/admin/records.php';
    $businessHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($businessHtml, 'Active Records') && str_contains($businessHtml, 'Archived Records')
        && str_contains($businessHtml, 'Deleted Records') && str_contains($businessHtml, 'Customer Verifications')
        && str_contains($businessHtml, 'View Authentication Records'), 'Business Record Management view was not restored.');
    auth_test_assert(str_contains($businessHtml, 'RESPONSIVE-RECORD-TEST')
        && str_contains($businessHtml, 'data-label="Reference"')
        && str_contains($businessHtml, 'data-label="Actions"'), 'Business records lack mobile labels or actions.');
    $_GET = ['record_type' => 'customer_verification'];
    ob_start();
    include APP_ROOT . '/public/admin/records.php';
    $verificationHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($verificationHtml, 'data-label="Customer Full Name"')
        && str_contains($verificationHtml, 'data-label="Approver Role"')
        && str_contains($verificationHtml, 'data-label="Actions"'), 'Customer verification records lack mobile labels.');
    logout_user();

    $orderId = (int) (db_find('SELECT id FROM orders WHERE reference_no = ?', 's', ['RESPONSIVE-RECORD-TEST'])['id'] ?? 0);
    db_execute('INSERT INTO deliveries (order_id, delivery_address) VALUES (?, ?)', 'is', [$orderId, 'Responsive Test Address']);
    auth_test_assert(login_user('manager@auth-history.example', $password), 'Manager page test login failed.');
    $_GET = ['type' => 'orders'];
    ob_start();
    include APP_ROOT . '/public/manager/records.php';
    $managerOrdersHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($managerOrdersHtml, 'Manager Records')
        && str_contains($managerOrdersHtml, 'record-management__table')
        && str_contains($managerOrdersHtml, 'data-label="Reference"')
        && str_contains($managerOrdersHtml, 'RESPONSIVE-RECORD-TEST')
        && !str_contains($managerOrdersHtml, 'archive_record'), 'Manager order records did not render the read-only responsive view.');
    $_GET = ['type' => 'deliveries', 'search' => 'Responsive Test Address'];
    ob_start();
    include APP_ROOT . '/public/manager/records.php';
    $managerDeliveryHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($managerDeliveryHtml, 'data-label="Address"')
        && str_contains($managerDeliveryHtml, 'Responsive Test Address'), 'Manager delivery search or responsive labels failed.');
    $_GET = ['type' => 'deliveries', 'search' => 'No Matching Delivery'];
    ob_start();
    include APP_ROOT . '/public/manager/records.php';
    $managerEmptyHtml = (string) ob_get_clean();
    auth_test_assert(str_contains($managerEmptyHtml, 'No records found.')
        && !str_contains($managerEmptyHtml, 'Responsive Test Address'), 'Manager delivery search did not filter unmatched records.');
    logout_user();

    require_once APP_ROOT . '/app/helpers/auth_history_export.php';
    require_once APP_ROOT . '/app/helpers/pdf_receipt.php';
    auth_test_assert(auth_history_export_values($failedNameSearch['rows'][0], true)[1] === 'Customer Test',
        'Failed-attempt CSV export omitted the available account name.');
    $pdf = auth_history_build_pdf('Failed', $failedRows, true);
    auth_test_assert(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF'), 'PDF export is malformed.');
    echo "Authentication history, finite tester login/logout limits, wrong-password locks, filters, summary, PDF generation, and Manager Records rendering/search passed in a disposable database.\n";
} finally {
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
}
