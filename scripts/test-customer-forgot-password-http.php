<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function recovery_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recovery_http_import(mysqli $database, string $sql): void
{
    recovery_http_assert($database->multi_query($sql), 'Test schema import failed.');
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    recovery_http_assert($database->errno === 0, 'Test schema import failed.');
}

function recovery_http_request(string $url, ?string $cookie = null, ?array $post = null, bool $ajax = true): array
{
    $headers = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_COOKIE => $cookie ?? '',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $headers[] = $line;
            return strlen($line);
        },
    ]);
    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        if ($ajax) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
        }
    }
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $error = curl_error($handle);
    curl_close($handle);
    $sessionCookie = '';
    $location = '';
    foreach ($headers as $line) {
        if (stripos($line, 'Set-Cookie: ') === 0) {
            $pair = explode(';', trim(substr($line, 12)), 2)[0];
            if (str_starts_with($pair, APP_SESSION_NAME . '=')) {
                $sessionCookie = $pair;
            }
        }
        if (stripos($line, 'Location: ') === 0) {
            $location = trim(substr($line, 10));
        }
    }
    recovery_http_assert($error === '', 'HTTP request failed: ' . $error);
    return compact('status', 'contentType', 'body', 'sessionCookie', 'location');
}

function recovery_http_session(string $baseUrl): array
{
    $response = recovery_http_request($baseUrl . '/public/forgot-password.php');
    recovery_http_assert($response['status'] === 200, 'Forgot Password page did not load.');
    recovery_http_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $response['body'], $match) === 1, 'CSRF token missing.');
    recovery_http_assert($response['sessionCookie'] !== '', 'Session cookie missing.');
    return [$response['sessionCookie'], $match[1]];
}

function recovery_http_json(array $response, int $expectedStatus): array
{
    recovery_http_assert(
        $response['status'] === $expectedStatus,
        'Unexpected HTTP status at test line ' . (int) (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[0]['line'] ?? 0)
            . ': ' . $response['status'] . ' (expected ' . $expectedStatus . ')'
    );
    recovery_http_assert(str_starts_with($response['contentType'], 'application/json'), 'AJAX response was not JSON.');
    $payload = json_decode((string) $response['body'], true);
    recovery_http_assert(is_array($payload) && array_key_exists('success', $payload), 'AJAX response was malformed.');
    return $payload;
}

function recovery_http_redirect(array $response, string $target): void
{
    recovery_http_assert($response['status'] === 302, 'Forgot Password POST did not return an HTTP redirect.');
    recovery_http_assert(str_contains($response['location'], $target), 'Forgot Password redirected to the wrong page.');
    recovery_http_assert(!str_starts_with($response['contentType'], 'application/json'), 'Forgot Password unexpectedly returned JSON.');
}

function recovery_http_mailbox(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_map(static fn (string $line): array => (array) json_decode($line, true), $lines);
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
recovery_http_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_recovery_http_' . bin2hex(random_bytes(5));
$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freds-recovery-' . bin2hex(random_bytes(8));
recovery_http_assert(mkdir($testDirectory, 0700), 'Could not create test directory.');
$mailboxPath = $testDirectory . DIRECTORY_SEPARATOR . 'mailbox.jsonl';
$mailModePath = $testDirectory . DIRECTORY_SEPARATOR . 'mail-mode.txt';
$serverLog = $testDirectory . DIRECTORY_SEPARATOR . 'server.log';
file_put_contents($mailboxPath, '');
file_put_contents($mailModePath, 'success');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
recovery_http_assert(is_resource($socket), 'Could not reserve a local HTTP port.');
$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);
$baseUrl = 'http://127.0.0.1:' . $port;
$admin = new mysqli($host, database_setting('DB_USER', 'user', 'root'), database_setting('DB_PASS', 'password'), '', (int) database_setting('DB_PORT', 'port', '3306'));
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server = null;
$failure = null;

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    putenv('APP_DEBUG=0');
    putenv('APP_FORCE_HTTPS=0');
    putenv('APP_URL=' . $baseUrl . '/public');
    putenv('APP_SESSION_NAME=FREDSRECOVERYHTTP');
    putenv('SMTP_HOST=smtp-relay.brevo.com');
    putenv('SMTP_PORT=587');
    putenv('SMTP_USERNAME=test-login');
    putenv('SMTP_PASSWORD=test-key');
    putenv('SMTP_FROM_EMAIL=test@example.test');
    putenv('EMAIL_CODE_HMAC_KEY=' . bin2hex(random_bytes(32)));
    putenv('FREDS_TEST_MAIL_CAPTURE_FILE=' . $mailboxPath);
    putenv('FREDS_TEST_MAIL_MODE_FILE=' . $mailModePath);
    putenv('FREDS_TEST_SESSION_DIR=' . $testDirectory);
    session_save_path($testDirectory);
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    recovery_http_import(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    foreach ([['verified@example.test', true], ['unverified@example.test', false]] as [$email, $verified]) {
        db_execute(
            'INSERT INTO users (full_name, email, password, phone, address, role, status, email_verified_at)
             VALUES (?, ?, ?, ?, ?, "customer", "active", ?)',
            'ssssss',
            ['Recovery Test', $email, password_hash('OldPassword123', PASSWORD_DEFAULT), '09175550001', 'Test address', $verified ? date('Y-m-d H:i:s') : null]
        );
        if ($verified) {
            db_execute(
                'INSERT INTO registration_verifications
                    (user_id, email, code_hash, expires_at, last_sent_at, verified_at, created_at, updated_at)
                 VALUES (?, ?, "", DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW(), NOW(), NOW(), NOW())',
                'is',
                [db_last_id(), $email]
            );
        }
    }
    session_write_close();
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $testDirectory,
            '-S', '127.0.0.1:' . $port, '-t', APP_ROOT,
            APP_ROOT . '/scripts/fixtures/forgot-password-http-router.php'],
        [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        APP_ROOT,
        getenv()
    );
    recovery_http_assert(is_resource($server), 'Could not start test HTTP server.');
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if (recovery_http_request($baseUrl . '/public/forgot-password.php')['status'] === 200) {
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    recovery_http_assert($ready, 'Test HTTP server did not become ready.');

    [$unknownCookie, $unknownCsrf] = recovery_http_session($baseUrl);
    $unknownRepeat = recovery_http_request($baseUrl . '/public/forgot-password.php', $unknownCookie);
    recovery_http_assert(
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $unknownRepeat['body'], $unknownRepeatMatch) === 1
            && hash_equals($unknownCsrf, $unknownRepeatMatch[1]),
        'Test HTTP client did not preserve the recovery session cookie.'
    );
    $unknown = recovery_http_request($baseUrl . '/public/forgot-password.php', $unknownCookie, [
        'csrf_token' => $unknownCsrf, 'action' => 'request_code', 'email' => 'missing@example.test',
    ], false);
    recovery_http_redirect($unknown, 'forgot-password.php');
    recovery_http_assert(recovery_http_mailbox($mailboxPath) === [], 'Unknown email caused an outbound message.');
    recovery_http_assert((int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === 0, 'Unknown email created a reset code.');
    $unknownPage = recovery_http_request($baseUrl . '/public/forgot-password.php', $unknownCookie);
    recovery_http_assert(str_contains((string) $unknownPage['body'], 'Send Verification Code')
        && str_contains((string) $unknownPage['body'], 'We could not start recovery for this address.')
        && !str_contains((string) $unknownPage['body'], '<h1>Enter Verification Code</h1>'), 'Unknown email showed Verify Code after redirect.');
    recovery_http_assert(!str_contains((string) $unknownPage['body'], 'data-password-recovery-form')
        && !str_contains((string) $unknownPage['body'], '/js/forgot-password.js'), 'Forgot Password still loads the AJAX form handler.');

    foreach (['verified@example.test', 'unverified@example.test'] as $email) {
        [$cookie, $csrf] = recovery_http_session($baseUrl);
        $sent = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie, [
            'csrf_token' => $csrf, 'action' => 'request_code', 'email' => $email,
        ], false);
        recovery_http_redirect($sent, 'forgot-password.php');
        $mailbox = recovery_http_mailbox($mailboxPath);
        $mail = end($mailbox);
        recovery_http_assert(($mail['to'] ?? '') === $email && preg_match('/\b([0-9]{6})\b/', (string) ($mail['body'] ?? ''), $codeMatch) === 1, 'Registered customer did not receive a six-digit email code.');
        $code = $codeMatch[1];
        $codePage = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie);
        recovery_http_assert(str_contains((string) $codePage['body'], '<h1>Enter Verification Code</h1>')
            && str_contains((string) $codePage['body'], 'check your inbox'), 'Registered customer did not see Verify Code and the success message.');
        if ($email === 'verified@example.test') {
            $mailCount = count($mailbox);
            $cooldown = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie, [
                'csrf_token' => $csrf, 'action' => 'resend_code',
            ], false);
            recovery_http_redirect($cooldown, 'forgot-password.php');
            recovery_http_assert(count(recovery_http_mailbox($mailboxPath)) === $mailCount, 'Resend ignored the cooldown.');
            $previousReset = db_find('SELECT id FROM password_resets WHERE email = ? ORDER BY id DESC LIMIT 1', 's', [$email]);
            db_execute(
                'UPDATE password_resets SET created_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?',
                'ii',
                [PASSWORD_RESET_COOLDOWN_SECONDS + 1, (int) $previousReset['id']]
            );
            $resent = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie, [
                'csrf_token' => $csrf, 'action' => 'resend_code',
            ], false);
            recovery_http_redirect($resent, 'forgot-password.php');
            $mailbox = recovery_http_mailbox($mailboxPath);
            $latestMail = end($mailbox);
            recovery_http_assert(count($mailbox) === $mailCount + 1
                && preg_match('/\b([0-9]{6})\b/', (string) ($latestMail['body'] ?? ''), $resentCodeMatch) === 1,
                'Resend did not deliver a replacement code.');
            recovery_http_assert(
                (verify_password_reset_code((int) $previousReset['id'], $email, $code)['status'] ?? '') !== 'verified',
                'The old code remained usable after resend.'
            );
            $code = $resentCodeMatch[1];
        }
        $wrong = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie, [
            'csrf_token' => $csrf, 'action' => 'verify_code', 'verification_code' => '000000',
        ], false);
        recovery_http_redirect($wrong, 'forgot-password.php');
        $wrongPage = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie);
        recovery_http_assert(str_contains((string) $wrongPage['body'], 'Invalid or expired verification code.'), 'Wrong code did not show an error.');
        $verified = recovery_http_request($baseUrl . '/public/forgot-password.php', $cookie, [
            'csrf_token' => $csrf, 'action' => 'verify_code', 'verification_code' => $code,
        ], false);
        recovery_http_redirect($verified, 'reset-password.php');
        $resetPage = recovery_http_request($baseUrl . '/public/reset-password.php', $cookie);
        recovery_http_assert(str_contains((string) $resetPage['body'], 'Confirm New Password'), 'New Password page did not render.');
        $newPassword = $email === 'verified@example.test' ? 'VerifiedNew123' : 'UnverifiedNew123';
        $updatedResponse = recovery_http_request($baseUrl . '/public/reset-password.php', $cookie, [
            'csrf_token' => $csrf, 'password' => $newPassword, 'confirm_password' => $newPassword,
        ]);
        $updated = recovery_http_json($updatedResponse, 200);
        recovery_http_assert($updated['success'] === true && str_contains((string) ($updated['redirect'] ?? ''), 'auth/login.php'), 'Password update did not return the login redirect.');
        $stored = db_find('SELECT password, email_verified_at FROM users WHERE email = ?', 's', [$email]);
        recovery_http_assert(password_verify($newPassword, (string) $stored['password']) && !password_verify('OldPassword123', (string) $stored['password']), 'New password failed or old password still works.');
        if ($email === 'unverified@example.test') {
            recovery_http_assert($stored['email_verified_at'] === null, 'Forgot Password incorrectly marked an email verified.');
        }
        $reset = db_find('SELECT id, is_used, code_hash FROM password_resets WHERE email = ? ORDER BY id DESC LIMIT 1', 's', [$email]);
        recovery_http_assert((int) $reset['is_used'] === 1 && empty($reset['code_hash']), 'Used code was not invalidated.');
        recovery_http_assert((verify_password_reset_code((int) $reset['id'], $email, $code)['status'] ?? '') !== 'verified', 'Used code could be replayed.');

        $loginPage = recovery_http_request($baseUrl . '/public/auth/login.php');
        recovery_http_assert($loginPage['status'] === 200 && $loginPage['sessionCookie'] !== '', 'Login page did not load.');
        recovery_http_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage['body'], $loginCsrfMatch) === 1, 'Login CSRF token missing.');
        $loginCookie = $loginPage['sessionCookie'];
        $oldLogin = recovery_http_request($baseUrl . '/public/auth/login.php', $loginCookie, [
            'csrf_token' => $loginCsrfMatch[1], 'email' => $email, 'password' => 'OldPassword123',
        ], false);
        recovery_http_assert($oldLogin['status'] === 200 && str_contains((string) $oldLogin['body'], 'Invalid credentials'), 'Old password was accepted by login.');
        $newLogin = recovery_http_request($baseUrl . '/public/auth/login.php', $loginCookie, [
            'csrf_token' => $loginCsrfMatch[1], 'email' => $email, 'password' => $newPassword,
        ], false);
        recovery_http_assert($newLogin['status'] === 302 && str_contains($newLogin['location'], 'customer/'), 'New password failed at login.');
    }

    file_put_contents($mailModePath, 'fail');
    [$failureCookie, $failureCsrf] = recovery_http_session($baseUrl);
    $failureResult = recovery_http_request($baseUrl . '/public/forgot-password.php', $failureCookie, [
        'csrf_token' => $failureCsrf, 'action' => 'request_code', 'email' => 'verified@example.test',
    ], false);
    recovery_http_redirect($failureResult, 'forgot-password.php');
    $failurePage = recovery_http_request($baseUrl . '/public/forgot-password.php', $failureCookie);
    recovery_http_assert(!str_contains((string) $failurePage['body'], '<h1>Enter Verification Code</h1>')
        && str_contains((string) $failurePage['body'], 'We could not send a verification code right now.'), 'Mail failure did not return to the form with an error.');

    echo "Customer Forgot Password native POST/redirect flow passed: unknown stays on email form; verified and unverified customers receive codes, verify, reset, and invalidate old credentials; mail failure returns to the form.\n";
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
    foreach (scandir($testDirectory) ?: [] as $name) {
        if ($name !== '.' && $name !== '..' && (in_array($name, ['mailbox.jsonl', 'mail-mode.txt', 'server.log'], true) || preg_match('/^sess_[a-zA-Z0-9,-]+$/', $name))) {
            unlink($testDirectory . DIRECTORY_SEPARATOR . $name);
        }
    }
    rmdir($testDirectory);
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
