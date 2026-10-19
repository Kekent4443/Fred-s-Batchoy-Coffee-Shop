<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function email_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function email_test_sql(mysqli $database, string $sql): void
{
    if (!$database->multi_query($sql)) {
        throw new RuntimeException('Test SQL failed.');
    }
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    if ($database->errno !== 0) {
        throw new RuntimeException('Test SQL failed: ' . $database->error);
    }
}

function email_test_user(string $email, string $role = 'customer'): int
{
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())',
        'ssssss',
        ['Email Test User', $email, password_hash('OldPassword123', PASSWORD_DEFAULT),
            '09' . (string) random_int(100000000, 999999999), 'Test address only', $role]
    );

    return db_last_id();
}

function email_test_code(array $mail): string
{
    email_test_assert(preg_match('/\b([0-9]{6})\b/', (string) ($mail['body'] ?? ''), $matches) === 1, 'No six-digit code reached the test mail transport.');

    return $matches[1];
}

function email_test_topbar(): string
{
    ob_start();
    include APP_ROOT . '/app/includes/topbar.php';

    return (string) ob_get_clean();
}

function email_test_verify_page(int $customerId, string $email): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    unset($_SESSION['user_id']);
    clear_current_user_cache();
    $_SESSION['registration_verification_pending'] = [
        'customer_id' => $customerId,
        'email' => $email,
        'started_at' => time(),
    ];
    ob_start();
    try {
        include APP_ROOT . '/public/auth/verify-registration.php';
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
email_test_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_email_test_' . bin2hex(random_bytes(5));
email_test_assert(preg_match('/^freds_email_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe test database name.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    putenv('APP_DEBUG=1');
    putenv('APP_URL=http://127.0.0.1/public');
    putenv('SMTP_HOST=smtp-relay.brevo.com');
    putenv('SMTP_PORT=587');
    putenv('SMTP_USERNAME=test-smtp-login');
    putenv('SMTP_PASSWORD=test-smtp-key');
    putenv('SMTP_FROM_EMAIL=test@example.test');
    putenv('SMTP_FROM_NAME=Test Sender');
    putenv('EMAIL_CODE_HMAC_KEY=' . bin2hex(random_bytes(32)));
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    email_test_assert(PASSWORD_RESET_EXPIRY_MINUTES === 5, 'Password recovery expiry is not configured for five minutes.');
    email_test_assert(registration_verification_expiry_minutes() === 5, 'Email verification expiry is not configured for five minutes.');

    $sampleHash = email_code_hash('123456', 42, 'account@example.test', 'email_verification');
    email_test_assert(str_starts_with($sampleHash, 'hmac-sha256:'), 'New email codes did not use keyed hashes.');
    email_test_assert(email_code_verify('123456', $sampleHash, 42, 'account@example.test', 'email_verification'), 'Matching keyed code was rejected.');
    email_test_assert(!email_code_verify('123456', $sampleHash, 43, 'account@example.test', 'email_verification'), 'Keyed code was not bound to account.');
    email_test_assert(!email_code_verify('123456', $sampleHash, 42, 'account@example.test', 'password_reset'), 'Keyed code was not bound to purpose.');
    email_test_assert(!email_code_verify('123456', $sampleHash, 42, 'other@example.test', 'email_verification'), 'Keyed code was not bound to email.');
    email_test_assert(email_code_verify('123456', password_hash('123456', PASSWORD_DEFAULT), 42, 'account@example.test', 'email_verification'), 'An outstanding legacy code could not be verified.');
    $testSecret = (string) getenv('EMAIL_CODE_HMAC_KEY');
    putenv('EMAIL_CODE_HMAC_KEY=' . bin2hex(random_bytes(32)));
    email_test_assert(!email_code_verify('123456', $sampleHash, 42, 'account@example.test', 'email_verification'), 'Rotated secret accepted an old keyed code.');
    putenv('EMAIL_CODE_HMAC_KEY=' . $testSecret);

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $schema = explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    email_test_sql(get_db(), $schema);

    // Simulate the previous schema, then verify the phpMyAdmin migration twice.
    db_execute('ALTER TABLE password_resets DROP COLUMN purpose');
    $legacyCustomerEmail = 'legacy-customer-' . bin2hex(random_bytes(3)) . '@example.test';
    $legacyAdminEmail = 'legacy-admin-' . bin2hex(random_bytes(3)) . '@example.test';
    $legacyCustomerId = email_test_user($legacyCustomerEmail);
    $legacyAdminId = email_test_user($legacyAdminEmail, 'administrator');
    db_execute('UPDATE users SET email_verified_at = NOW() WHERE id = ?', 'i', [$legacyCustomerId]);
    foreach ([[$legacyCustomerId, $legacyCustomerEmail], [$legacyAdminId, $legacyAdminEmail]] as [$legacyUserId, $legacyEmail]) {
        db_execute(
            'INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE))',
            'iss',
            [$legacyUserId, $legacyEmail, hash('sha256', random_bytes(32))]
        );
    }
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_28_customer_email_verification_codes.sql');
    email_test_sql(get_db(), $migration);
    email_test_sql(get_db(), $migration);
    email_test_assert(database_column_exists('password_resets', 'purpose'), 'Migration did not add reset purpose.');
    email_test_assert(!customer_registration_email_is_verified($legacyCustomerId), 'Unproven legacy email remained verified.');
    email_test_assert((int) db_find('SELECT is_used FROM password_resets WHERE user_id = ?', 'i', [$legacyCustomerId])['is_used'] === 1, 'Legacy customer reset link remained active.');
    $legacyAdminReset = db_find('SELECT purpose, is_used FROM password_resets WHERE user_id = ?', 'i', [$legacyAdminId]);
    email_test_assert(($legacyAdminReset['purpose'] ?? '') === 'legacy_link' && (int) $legacyAdminReset['is_used'] === 0, 'Migration disrupted non-customer reset links.');

    $mailbox = [];
    $GLOBALS['freds_test_mail_transport'] = static function (string $to, string $name, string $subject, string $body) use (&$mailbox): array {
        $mailbox[] = compact('to', 'name', 'subject', 'body');
        return ['success' => true, 'temporary' => false, 'message_id' => 'test-' . count($mailbox), 'error' => ''];
    };
    $registrationPageSource = (string) file_get_contents(APP_ROOT . '/public/auth/customer-register.php');
    email_test_assert(!str_contains($registrationPageSource, 'request_registration_email_verification('),
        'Registration sent a verification code before the customer clicked Send Code.');
    $buttonEmail = 'button-' . bin2hex(random_bytes(3)) . '@example.test';
    $buttonCustomerId = email_test_user($buttonEmail);
    $initialButtonPage = email_test_verify_page($buttonCustomerId, $buttonEmail);
    email_test_assert(str_contains($initialButtonPage, '>Send Code</button>')
        && !str_contains($initialButtonPage, '>Send New Code</button>'), 'Verify Email initially showed the resend label.');
    email_test_assert(str_contains(email_test_verify_page($buttonCustomerId, $buttonEmail), '>Send Code</button>'),
        'Refreshing before the first send changed the button label.');
    $firstButtonSend = request_registration_email_verification($buttonCustomerId, $buttonEmail, true);
    email_test_assert(($firstButtonSend['status'] ?? '') === 'sent', 'Interactive verification email was not confirmed sent.');
    $firstButtonCode = email_test_code(end($mailbox));
    $sentButtonPage = email_test_verify_page($buttonCustomerId, $buttonEmail);
    email_test_assert(str_contains($sentButtonPage, '>Send New Code</button>')
        && str_contains($sentButtonPage, 'data-email-code-send')
        && str_contains($sentButtonPage, 'disabled')
        && str_contains($sentButtonPage, 'name="verification_code"'), 'Sent code did not show a disabled resend button and visible code input.');
    email_test_assert(str_contains(email_test_verify_page($buttonCustomerId, $buttonEmail), '>Send New Code</button>'),
        'Refreshing after a successful send lost the resend label.');
    $buttonCooldown = request_registration_email_verification($buttonCustomerId, $buttonEmail, true);
    email_test_assert(($buttonCooldown['status'] ?? '') === 'cooldown', 'Interactive resend ignored cooldown.');
    db_execute('UPDATE registration_verifications SET last_sent_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE user_id = ?',
        'ii', [registration_verification_cooldown_seconds() + 1, $buttonCustomerId]);
    $secondButtonSend = request_registration_email_verification($buttonCustomerId, $buttonEmail, true);
    email_test_assert(($secondButtonSend['status'] ?? '') === 'sent'
        && (verify_registration_email_code($buttonCustomerId, $buttonEmail, $firstButtonCode)['status'] ?? '') !== 'verified',
        'Resend did not replace the previous verification code.');

    $buttonFailureEmail = 'button-failure-' . bin2hex(random_bytes(3)) . '@example.test';
    $buttonFailureId = email_test_user($buttonFailureEmail);
    $successfulTransport = $GLOBALS['freds_test_mail_transport'];
    $GLOBALS['freds_test_mail_transport'] = static fn (): array => [
        'success' => false, 'temporary' => false, 'message_id' => '', 'error' => 'Test delivery failure.',
    ];
    $buttonFailure = request_registration_email_verification($buttonFailureId, $buttonFailureEmail, true);
    email_test_assert(($buttonFailure['status'] ?? '') === 'delivery_failed', 'Failed interactive send reported success.');
    $failedButtonPage = email_test_verify_page($buttonFailureId, $buttonFailureEmail);
    email_test_assert(str_contains($failedButtonPage, '>Send Code</button>')
        && !str_contains($failedButtonPage, '>Send New Code</button>')
        && (int) (registration_email_verification_status($buttonFailureId)['retry_after'] ?? -1) === 0,
        'Failed send switched the label or left a resend cooldown.');
    email_test_assert(str_contains(email_test_verify_page($buttonFailureId, $buttonFailureEmail), '>Send Code</button>'),
        'Refreshing after a failed send changed the initial label.');
    $GLOBALS['freds_test_mail_transport'] = $successfulTransport;
    unset($_SESSION['registration_verification_pending']);

    $customerEmail = 'verified-' . bin2hex(random_bytes(3)) . '@example.test';
    $pendingCustomerId = create_user([
        'full_name' => 'Optional Email Test',
        'email' => 'pending-' . bin2hex(random_bytes(3)) . '@example.test',
        'password' => 'OldPassword123',
        'phone' => '09' . (string) random_int(100000000, 999999999),
        'address' => '123 Test Street, Test Barangay, Test City',
        'role' => 'customer',
        'status' => 'pending_verification',
    ]);
    email_test_assert(!customer_registration_email_is_verified($pendingCustomerId), 'Registration without email verification did not remain unverified.');
    $customerId = email_test_user($customerEmail);
    email_test_assert(!customer_registration_email_is_verified($customerId), 'A new customer started verified.');
    $verification = request_registration_email_verification($customerId, $customerEmail);
    email_test_assert(($verification['status'] ?? '') === 'queued', 'Verification code was not queued.');
    $storedVerification = db_find(
        'SELECT expires_at FROM registration_verifications WHERE user_id = ?',
        'i',
        [$customerId]
    );
    $verificationLifetimeSeconds = strtotime((string) ($storedVerification['expires_at'] ?? '')) - time();
    email_test_assert(
        $verificationLifetimeSeconds >= 290 && $verificationLifetimeSeconds <= 300,
        'Email-verification code did not receive a five-minute expiry.'
    );
    email_test_assert(str_starts_with((string) db_find('SELECT code_hash FROM registration_verifications WHERE user_id = ?', 'i', [$customerId])['code_hash'], 'hmac-sha256:'), 'Registration code was not stored as a keyed hash.');
    process_email_queue_batch(1);
    $verificationCode = email_test_code(end($mailbox));
    email_test_assert((verify_registration_email_code($customerId, $customerEmail, '000000')['status'] ?? '') !== 'verified', 'Wrong verification code succeeded.');
    email_test_assert((verify_registration_email_code($customerId, $customerEmail, $verificationCode)['status'] ?? '') === 'verified', 'Correct verification code failed.');
    email_test_assert(customer_registration_email_is_verified($customerId), 'Verified status was not stored.');
    email_test_assert((verify_registration_email_code($customerId, $customerEmail, $verificationCode)['status'] ?? '') !== 'verified', 'Used verification code was accepted again.');

    $reset = save_password_reset_request($customerEmail);
    email_test_assert(($reset['status'] ?? '') === 'queued', 'Customer reset code was not queued.');
    $resetLifetimeSeconds = strtotime((string) $reset['expires_at']) - time();
    email_test_assert(
        $resetLifetimeSeconds >= 290 && $resetLifetimeSeconds <= 300,
        'Password-reset credential did not receive a five-minute expiry.'
    );
    email_test_assert(str_starts_with((string) db_find('SELECT code_hash FROM password_resets WHERE id = ?', 'i', [(int) $reset['reset_id']])['code_hash'], 'hmac-sha256:'), 'Password-reset code was not stored as a keyed hash.');
    $forgotCodeRecord = db_find(
        'SELECT pr.*, u.email AS user_email, u.role AS user_role, u.email_verified_at AS user_email_verified_at,
                rv.verified_at AS user_email_proof_at
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
         WHERE pr.id = ?',
        'i',
        [(int) $reset['reset_id']]
    );
    email_test_assert(!password_reset_record_supports_link($forgotCodeRecord ?? []), 'Customer Forgot Password code was accepted as an Administrator reset link.');
    process_email_queue_batch(1);
    $resetCode = email_test_code(end($mailbox));
    email_test_assert((verify_password_reset_code((int) $reset['reset_id'], $customerEmail, '000000')['status'] ?? '') !== 'verified', 'Wrong reset code succeeded.');
    $grant = verify_password_reset_code((int) $reset['reset_id'], $customerEmail, $resetCode);
    email_test_assert(($grant['status'] ?? '') === 'verified', 'Correct reset code failed.');
    email_test_assert(
        reset_password_with_verified_link((int) $grant['reset_id'], (string) $grant['verification_token'], 'WrongFlow123') === 'invalid',
        'Forgot Password code grant was accepted by the Administrator link flow.'
    );
    email_test_assert(reset_password_with_verified_code((int) $grant['reset_id'], (string) $grant['verification_token'], 'NewPassword123') === 'success', 'Reset did not update the password.');
    email_test_assert(password_verify('NewPassword123', (string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$customerId])['password']), 'New password hash is invalid.');
    email_test_assert(reset_password_with_verified_code((int) $grant['reset_id'], (string) $grant['verification_token'], 'AnotherPassword123') !== 'success', 'Used reset grant worked twice.');

    $unverifiedEmail = 'unverified-' . bin2hex(random_bytes(3)) . '@example.test';
    $unverifiedId = email_test_user($unverifiedEmail);
    email_test_assert(login_user($unverifiedEmail, 'OldPassword123', false), 'Active unverified customer could not log in.');
    email_test_assert(str_contains(email_test_topbar(), 'email-verification-reminder'), 'Unverified customer did not see a reminder.');
    $_SESSION['email_verification_reminder_dismissed'] = true;
    email_test_assert(!str_contains(email_test_topbar(), 'email-verification-reminder'), 'Dismissed reminder remained visible.');
    unset($_SESSION['email_verification_reminder_dismissed']);
    $firstStep = save_password_reset_request($unverifiedEmail);
    email_test_assert(($firstStep['status'] ?? '') === 'queued', 'Forgot Password did not queue a password-reset code.');
    email_test_assert((int) db_find('SELECT COUNT(*) AS total FROM password_resets WHERE user_id = ?', 'i', [$unverifiedId])['total'] === 1, 'Forgot Password did not create exactly one reset record.');
    process_email_queue_batch(1);
    $firstCode = email_test_code(end($mailbox));
    $firstGrant = verify_password_reset_code((int) $firstStep['reset_id'], $unverifiedEmail, $firstCode);
    email_test_assert(($firstGrant['status'] ?? '') === 'verified', 'Forgot Password code failed.');
    email_test_assert(!customer_registration_email_is_verified($unverifiedId), 'Forgot Password silently changed the separate registration-email status.');
    email_test_assert(reset_password_with_verified_code((int) $firstGrant['reset_id'], (string) $firstGrant['verification_token'], 'RecoveredPassword123') === 'success', 'Forgot Password reset failed.');

    // The HTTP Forgot Password handler requests immediate delivery so its
    // response reflects PHPMailer/Brevo success or failure instead of waiting
    // for the generic shutdown worker.
    $immediateEmail = 'immediate-' . bin2hex(random_bytes(3)) . '@example.test';
    $immediateId = email_test_user($immediateEmail);
    $mailCountBeforeImmediate = count($mailbox);
    $immediateReset = save_password_reset_request($immediateEmail, false, true);
    email_test_assert(($immediateReset['status'] ?? '') === 'queued', 'Immediate Forgot Password delivery failed.');
    email_test_assert(count($mailbox) === $mailCountBeforeImmediate + 1, 'Immediate Forgot Password did not invoke the mail transport.');
    $immediateCode = email_test_code(end($mailbox));
    email_test_assert(
        (db_find('SELECT status FROM email_notifications WHERE id = ?', 'i', [(int) $immediateReset['queue_id']])['status'] ?? '') === 'sent',
        'Immediate Forgot Password email was not marked sent.'
    );
    $cooldownReset = save_password_reset_request($immediateEmail, false, true);
    email_test_assert(
        ($cooldownReset['status'] ?? '') === 'cooldown'
            && (int) ($cooldownReset['reset_id'] ?? 0) === (int) $immediateReset['reset_id']
            && (int) ($cooldownReset['retry_after'] ?? 0) > 0,
        'A still-valid code was not protected by the resend cooldown.'
    );

    db_execute(
        'UPDATE password_resets SET created_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?',
        'ii',
        [PASSWORD_RESET_COOLDOWN_SECONDS + 1, (int) $immediateReset['reset_id']]
    );
    $resentReset = save_password_reset_request($immediateEmail, true, true);
    email_test_assert(($resentReset['status'] ?? '') === 'queued', 'Resend did not issue a replacement code.');
    $resentCode = email_test_code(end($mailbox));
    email_test_assert((int) $resentReset['reset_id'] !== (int) $immediateReset['reset_id'], 'Resend reused the old reset record.');
    email_test_assert(
        (verify_password_reset_code((int) $immediateReset['reset_id'], $immediateEmail, $immediateCode)['status'] ?? '') !== 'verified',
        'Resend left the previous code usable.'
    );
    email_test_assert(
        (verify_password_reset_code((int) $resentReset['reset_id'], $immediateEmail, $resentCode)['status'] ?? '') === 'verified',
        'Resent password-reset code was rejected.'
    );

    $unknownImmediateMailboxCount = count($mailbox);
    $resetCountBeforeUnknownImmediate = (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'];
    $unknownImmediate = save_password_reset_request('missing-immediate-' . bin2hex(random_bytes(3)) . '@example.test', false, true);
    email_test_assert(($unknownImmediate['status'] ?? '') === 'unknown' && empty($unknownImmediate['reset_id']), 'Unknown email was given a verification-code step.');
    email_test_assert(count($mailbox) === $unknownImmediateMailboxCount, 'Unknown email triggered an outbound message.');
    email_test_assert((int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetCountBeforeUnknownImmediate, 'Unknown email created a reset code.');

    $lockedCooldownEmail = 'locked-cooldown-' . bin2hex(random_bytes(3)) . '@example.test';
    email_test_user($lockedCooldownEmail);
    $lockedCooldownReset = save_password_reset_request($lockedCooldownEmail, false, true);
    email_test_assert(($lockedCooldownReset['status'] ?? '') === 'queued', 'Could not issue the cooldown test code.');
    for ($attempt = 0; $attempt < PASSWORD_RESET_MAX_ATTEMPTS; $attempt++) {
        verify_password_reset_code((int) $lockedCooldownReset['reset_id'], $lockedCooldownEmail, '000000');
    }
    $afterLock = save_password_reset_request($lockedCooldownEmail, true, true);
    email_test_assert(
        ($afterLock['status'] ?? '') === 'queued' && (int) ($afterLock['reset_id'] ?? 0) !== (int) $lockedCooldownReset['reset_id'],
        'A locked code was incorrectly returned as usable during cooldown.'
    );

    $databaseFailureEmail = 'database-failure-' . bin2hex(random_bytes(3)) . '@example.test';
    email_test_user($databaseFailureEmail);
    db_execute('RENAME TABLE email_notifications TO email_notifications_failure_test');
    try {
        $databaseFailure = save_password_reset_request($databaseFailureEmail, false, true);
    } finally {
        db_execute('RENAME TABLE email_notifications_failure_test TO email_notifications');
    }
    email_test_assert(
        ($databaseFailure['status'] ?? '') === 'unavailable',
        'Database failure did not return a safe, actionable recovery error.'
    );

    $expiredEmail = 'expired-' . bin2hex(random_bytes(3)) . '@example.test';
    $expiredId = email_test_user($expiredEmail);
    request_registration_email_verification($expiredId, $expiredEmail);
    process_email_queue_batch(2);
    $expiredCode = email_test_code(end($mailbox));
    db_execute('UPDATE registration_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), last_sent_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = ?', 'i', [$expiredId]);
    email_test_assert((verify_registration_email_code($expiredId, $expiredEmail, $expiredCode)['status'] ?? '') === 'expired', 'Expired verification code was accepted.');
    request_registration_email_verification($expiredId, $expiredEmail);
    process_email_queue_batch(1);
    $replacementCode = email_test_code(end($mailbox));
    email_test_assert((verify_registration_email_code($expiredId, $expiredEmail, $expiredCode)['status'] ?? '') !== 'verified', 'Superseded verification code was accepted.');
    email_test_assert((verify_registration_email_code($expiredId, $expiredEmail, $replacementCode)['status'] ?? '') === 'verified', 'Replacement verification code failed.');
    $guessEmail = 'guesses-' . bin2hex(random_bytes(3)) . '@example.test';
    $guessId = email_test_user($guessEmail);
    request_registration_email_verification($guessId, $guessEmail);
    process_email_queue_batch(1);
    $guessCode = email_test_code(end($mailbox));
    for ($attempt = 0; $attempt < registration_verification_max_attempts(); $attempt++) {
        verify_registration_email_code($guessId, $guessEmail, '000000');
    }
    email_test_assert((verify_registration_email_code($guessId, $guessEmail, $guessCode)['status'] ?? '') === 'too_many_attempts', 'Verification code survived repeated guessing.');
    $expiredReset = save_password_reset_request($expiredEmail);
    email_test_assert(($expiredReset['status'] ?? '') === 'queued', 'Reset code for expiry test was not queued.');
    process_email_queue_batch(1);
    $expiredResetCode = email_test_code(end($mailbox));
    db_execute('UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?', 'i', [(int) $expiredReset['reset_id']]);
    email_test_assert((verify_password_reset_code((int) $expiredReset['reset_id'], $expiredEmail, $expiredResetCode)['status'] ?? '') === 'expired', 'Expired reset code was accepted.');
    $lockedReset = save_password_reset_request($expiredEmail, true);
    email_test_assert(($lockedReset['status'] ?? '') === 'queued', 'Replacement reset code was not queued.');
    process_email_queue_batch(1);
    $lockedCode = email_test_code(end($mailbox));
    email_test_assert((verify_password_reset_code((int) $expiredReset['reset_id'], $expiredEmail, $expiredResetCode)['status'] ?? '') !== 'verified', 'Superseded reset code was accepted.');
    for ($attempt = 0; $attempt < PASSWORD_RESET_MAX_ATTEMPTS; $attempt++) {
        verify_password_reset_code((int) $lockedReset['reset_id'], $expiredEmail, '000000');
    }
    email_test_assert((verify_password_reset_code((int) $lockedReset['reset_id'], $expiredEmail, $lockedCode)['status'] ?? '') === 'too_many_attempts', 'Reset code survived repeated guessing.');

    $adminId = email_test_user('admin-' . bin2hex(random_bytes(3)) . '@example.test', 'administrator');
    $_SESSION['user_id'] = $adminId;
    clear_current_user_cache();
    try {
        request_administrator_password_reset(email_test_user('blocked-' . bin2hex(random_bytes(3)) . '@example.test'), $adminId);
        throw new RuntimeException('Administrator reset accepted an unverified customer.');
    } catch (InvalidArgumentException $exception) {
        email_test_assert(str_contains($exception->getMessage(), 'verified'), 'Wrong admin rejection reason.');
    }
    email_test_assert(
        (int) db_find(
            'SELECT COUNT(*) AS total FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")'
        )['total'] === 0,
        'Blocked unverified-customer reset consumed an Administrator reset limit.'
    );
    $adminReset = request_administrator_password_reset($customerId, $adminId);
    email_test_assert(
        ($adminReset['status'] ?? '') === 'queued' && ($adminReset['delivery_method'] ?? '') === 'link',
        'Administrator customer reset link was not queued.'
    );
    process_email_queue_batch(2);
    $adminMail = end($mailbox);
    email_test_assert(($adminMail['to'] ?? '') === $customerEmail, 'Administrator customer reset went to the wrong recipient.');
    email_test_assert(
        preg_match('/reset-password\.php\?token=([a-f0-9]{64})/', (string) ($adminMail['body'] ?? ''), $customerLinkMatch) === 1,
        'Customer reset email omitted its secure link.'
    );
    $customerRawToken = $customerLinkMatch[1];
    email_test_assert(!str_contains(json_encode($adminReset), $customerRawToken), 'Administrator response exposed the customer reset token.');
    $customerStoredToken = (string) db_find(
        'SELECT token FROM password_resets WHERE id = ?',
        'i',
        [(int) $adminReset['reset_id']]
    )['token'];
    email_test_assert(
        $customerStoredToken === hash('sha256', $customerRawToken) && $customerStoredToken !== $customerRawToken,
        'Raw customer reset token was stored in the database.'
    );
    email_test_assert(
        empty(db_find('SELECT code_hash FROM password_resets WHERE id = ?', 'i', [(int) $adminReset['reset_id']])['code_hash'])
            && (verify_password_reset_code((int) $adminReset['reset_id'], $customerEmail, '123456', 'admin_password_reset')['status'] ?? '') !== 'verified',
        'Administrator customer reset link was accepted as a Forgot Password code.'
    );
    email_test_assert((inspect_password_reset_token($customerRawToken)['status'] ?? '') === 'valid', 'Valid customer reset link was not inspectable.');
    email_test_assert((verify_password_reset_link(str_repeat('0', 64))['status'] ?? '') === 'invalid', 'Modified customer reset link was accepted.');
    $adminGrant = verify_password_reset_link($customerRawToken);
    email_test_assert(($adminGrant['status'] ?? '') === 'verified', 'Customer could not verify the Administrator reset link.');
    email_test_assert((verify_password_reset_link($customerRawToken)['status'] ?? '') === 'invalid', 'Customer reset link was reusable after verification.');
    email_test_assert(
        reset_password_with_verified_code((int) $adminGrant['reset_id'], (string) $adminGrant['verification_token'], 'WrongFlow123') === 'invalid',
        'Administrator link grant was accepted by the Forgot Password code flow.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $adminGrant['reset_id'], (string) $adminGrant['verification_token'], 'allletters') === 'weak_password',
        'Weak customer reset-link password was accepted.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $adminGrant['reset_id'], (string) $adminGrant['verification_token'], 'FinalPassword123') === 'success',
        'Customer could not finish the Administrator reset-link flow.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $adminGrant['reset_id'], (string) $adminGrant['verification_token'], 'ReplayPassword123') === 'invalid',
        'Completed customer reset credential was reusable.'
    );
    $customerPasswordHash = (string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$customerId])['password'];
    email_test_assert($customerPasswordHash !== 'FinalPassword123' && password_verify('FinalPassword123', $customerPasswordHash), 'Customer password was not securely hashed.');
    email_test_assert(!login_user($customerEmail, 'OldPassword123'), 'Customer could still log in using the old password.');
    email_test_assert(login_user($customerEmail, 'FinalPassword123'), 'Customer could not log in using the new password.');
    $_SESSION['user_id'] = $adminId;
    clear_current_user_cache();

    $expiredCustomerLink = create_password_reset_record_for_user(
        db_find('SELECT id, email FROM users WHERE id = ?', 'i', [$customerId]),
        false,
        'admin_password_reset'
    );
    db_execute(
        'UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?',
        'i',
        [(int) $expiredCustomerLink['reset_id']]
    );
    email_test_assert(
        (verify_password_reset_link((string) $expiredCustomerLink['token'])['status'] ?? '') === 'expired',
        'Expired customer reset link was accepted.'
    );

    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');
    $limitedAdminReset = [];
    for ($attempt = 0; $attempt <= ADMIN_PASSWORD_RESET_TARGET_HOURLY_LIMIT; $attempt++) {
        $limitedAdminReset = request_administrator_password_reset($customerId, $adminId);
    }
    email_test_assert(
        ($limitedAdminReset['status'] ?? '') === 'rate_limited',
        'Repeated Administrator customer-reset requests were not rate-limited.'
    );
    db_execute(
        'UPDATE email_notifications SET status = "expired", failed_at = NOW(), message = "", html_message = NULL
         WHERE user_id = ? AND status IN ("queued", "deferred")',
        'i',
        [$customerId]
    );
    db_execute(
        'UPDATE password_resets SET is_used = 1, used_at = COALESCE(used_at, NOW()) WHERE user_id = ? AND is_used = 0',
        'i',
        [$customerId]
    );
    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');

    // Every non-Administrator role must be rejected by the backend service,
    // even when it supplies the real Administrator id as the actor parameter.
    $roleTargetIds = [];
    foreach (['manager', 'staff', 'rider', 'customer'] as $unauthorizedRole) {
        $unauthorizedId = email_test_user(
            'unauthorized-' . $unauthorizedRole . '-' . bin2hex(random_bytes(3)) . '@example.test',
            $unauthorizedRole
        );
        $roleTargetIds[$unauthorizedRole] = $unauthorizedId;
        $_SESSION['user_id'] = $unauthorizedId;
        clear_current_user_cache();
        try {
            request_administrator_password_reset($adminId, $adminId);
            throw new RuntimeException(ucfirst($unauthorizedRole) . ' initiated an Administrator password reset.');
        } catch (RuntimeException $exception) {
            email_test_assert(
                str_contains($exception->getMessage(), 'authenticated Administrator'),
                'Wrong authorization failure for ' . $unauthorizedRole . '.'
            );
        }
    }

    $listedUsers = fetch_users();
    $eligibilityById = [];
    foreach ($listedUsers as $listedUser) {
        $eligibilityById[(int) $listedUser['id']] = (int) ($listedUser['password_reset_eligible'] ?? 0);
    }
    email_test_assert(($eligibilityById[$customerId] ?? 0) === 1, 'Verified customer was hidden from the Administrator reset action.');
    email_test_assert(($eligibilityById[$roleTargetIds['customer']] ?? 1) === 0, 'Unverified customer was offered a broken Administrator reset action.');
    foreach (['manager', 'staff', 'rider'] as $eligibleRole) {
        email_test_assert(
            ($eligibilityById[$roleTargetIds[$eligibleRole]] ?? 0) === 1,
            ucfirst($eligibleRole) . ' was incorrectly excluded from Administrator reset.'
        );
    }

    $_SESSION['user_id'] = $adminId;
    clear_current_user_cache();
    foreach (['manager', 'staff', 'rider'] as $targetRole) {
        $targetId = $roleTargetIds[$targetRole];
        $target = db_find('SELECT email FROM users WHERE id = ?', 'i', [$targetId]);
        $roleReset = request_administrator_password_reset($targetId, $adminId);
        email_test_assert(
            ($roleReset['status'] ?? '') === 'queued' && ($roleReset['delivery_method'] ?? '') === 'link',
            'Administrator did not queue the ' . $targetRole . ' reset link.'
        );
        process_email_queue_batch(1);
        $roleMail = end($mailbox);
        email_test_assert(($roleMail['to'] ?? '') === ($target['email'] ?? ''), ucfirst($targetRole) . ' reset went to the wrong recipient.');
        email_test_assert(
            preg_match('/reset-password\.php\?token=([a-f0-9]{64})/', (string) ($roleMail['body'] ?? ''), $roleLinkMatch) === 1,
            ucfirst($targetRole) . ' reset email omitted its secure link.'
        );
        $roleGrant = verify_password_reset_link($roleLinkMatch[1]);
        email_test_assert(($roleGrant['status'] ?? '') === 'verified', ucfirst($targetRole) . ' reset link was rejected.');
        email_test_assert(
            reset_password_with_verified_link((int) $roleGrant['reset_id'], (string) $roleGrant['verification_token'], 'RoleResetPass123', (string) $roleGrant['purpose']) === 'success',
            ucfirst($targetRole) . ' could not complete the reset.'
        );
    }

    // Exercise the Administrator-to-Administrator secure-link flow. The raw
    // token may appear in the email link but must never be stored in the DB.
    $_SESSION['user_id'] = $adminId;
    clear_current_user_cache();
    $selfReset = request_administrator_password_reset($adminId, $adminId);
    email_test_assert(
        ($selfReset['status'] ?? '') === 'queued' && ($selfReset['delivery_method'] ?? '') === 'link',
        'Administrator self-reset link was not queued.'
    );
    process_email_queue_batch(1);
    $selfResetMail = end($mailbox);
    email_test_assert(
        preg_match('/reset-password\.php\?token=([a-f0-9]{64})/', (string) ($selfResetMail['body'] ?? ''), $linkMatch) === 1,
        'Administrator reset email did not contain a secure link.'
    );
    $rawLinkToken = $linkMatch[1];
    $storedLinkToken = (string) db_find(
        'SELECT token FROM password_resets WHERE id = ?',
        'i',
        [(int) $selfReset['reset_id']]
    )['token'];
    email_test_assert($storedLinkToken === hash('sha256', $rawLinkToken) && $storedLinkToken !== $rawLinkToken, 'Raw reset link token was stored in the database.');
    email_test_assert((inspect_password_reset_token(str_repeat('0', 64))['status'] ?? '') === 'invalid', 'Modified Administrator reset link was accepted.');
    $linkGrant = verify_password_reset_link($rawLinkToken);
    email_test_assert(($linkGrant['status'] ?? '') === 'verified', 'Valid Administrator reset link was rejected.');
    email_test_assert((verify_password_reset_link($rawLinkToken)['status'] ?? '') === 'invalid', 'Administrator reset link was reusable after verification.');
    email_test_assert(
        reset_password_with_verified_link((int) $linkGrant['reset_id'], (string) $linkGrant['verification_token'], 'allletters', (string) $linkGrant['purpose']) === 'weak_password',
        'Letter-only Administrator password was accepted.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $linkGrant['reset_id'], (string) $linkGrant['verification_token'], '12345678', (string) $linkGrant['purpose']) === 'weak_password',
        'Number-only Administrator password was accepted.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $linkGrant['reset_id'], str_repeat('f', 64), 'AdminLinkPass123', (string) $linkGrant['purpose']) === 'invalid',
        'Modified verified reset credential was accepted.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $linkGrant['reset_id'], (string) $linkGrant['verification_token'], 'AdminLinkPass123', (string) $linkGrant['purpose']) === 'success',
        'Administrator secure-link password reset failed.'
    );
    email_test_assert(
        reset_password_with_verified_link((int) $linkGrant['reset_id'], (string) $linkGrant['verification_token'], 'AdminReplay123', (string) $linkGrant['purpose']) === 'invalid',
        'Completed Administrator reset credential was reusable.'
    );
    $adminPasswordHash = (string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$adminId])['password'];
    email_test_assert($adminPasswordHash !== 'AdminLinkPass123' && password_verify('AdminLinkPass123', $adminPasswordHash), 'Administrator password was not securely hashed.');
    email_test_assert(!login_user((string) db_find('SELECT email FROM users WHERE id = ?', 'i', [$adminId])['email'], 'OldPassword123'), 'Old Administrator password remained valid.');
    $adminEmail = (string) db_find('SELECT email FROM users WHERE id = ?', 'i', [$adminId])['email'];
    email_test_assert(login_user($adminEmail, 'AdminLinkPass123'), 'Administrator could not log in with the reset-link password.');

    $expiredLink = create_password_reset_record_for_user(
        db_find('SELECT id, email FROM users WHERE id = ?', 'i', [$adminId]),
        false,
        'legacy_link'
    );
    db_execute(
        'UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?',
        'i',
        [(int) $expiredLink['reset_id']]
    );
    email_test_assert(
        (verify_password_reset_link((string) $expiredLink['token'])['status'] ?? '') === 'expired',
        'Expired Administrator reset link was accepted.'
    );

    // Exercise the public Forgot Password code flow for the Administrator and
    // prove it revokes every recorded browser session and remember token.
    db_execute(
        'UPDATE password_resets SET created_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE user_id = ?',
        'ii',
        [PASSWORD_RESET_COOLDOWN_SECONDS + 1, $adminId]
    );
    $forgotAdmin = save_password_reset_request($adminEmail, true);
    email_test_assert(($forgotAdmin['status'] ?? '') === 'queued', 'Administrator Forgot Password code was not queued.');
    process_email_queue_batch(2);
    $forgotAdminCode = email_test_code(end($mailbox));
    $forgotAdminGrant = verify_password_reset_code((int) $forgotAdmin['reset_id'], $adminEmail, $forgotAdminCode);
    email_test_assert(($forgotAdminGrant['status'] ?? '') === 'verified', 'Administrator Forgot Password code was rejected.');

    $now = auth_history_now();
    db_execute(
        'INSERT INTO login_history
            (user_id, full_name, email, role, ip_address, user_agent, user_agent_hash, login_source, new_ip, new_browser, login_at, last_activity_at)
         VALUES (?, "Other Administrator Browser", ?, "administrator", "127.0.0.2", "test-browser", ?, "password", 0, 0, ?, ?)',
        'issss',
        [$adminId, $adminEmail, hash('sha256', 'test-browser'), $now, $now]
    );
    $otherBrowserHistoryId = db_last_id();
    db_execute(
        'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
        'iss',
        [$adminId, bin2hex(random_bytes(12)), hash('sha256', random_bytes(32))]
    );
    email_test_assert(
        reset_password_with_verified_code((int) $forgotAdminGrant['reset_id'], (string) $forgotAdminGrant['verification_token'], 'AdminFinalPass456') === 'success',
        'Administrator Forgot Password completion failed.'
    );
    email_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM remember_tokens WHERE user_id = ?', 'i', [$adminId])['total'] === 0,
        'Administrator remember tokens survived password reset.'
    );
    email_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM login_history WHERE user_id = ? AND logout_at IS NULL', 'i', [$adminId])['total'] === 0,
        'An existing Administrator browser session survived password reset.'
    );
    email_test_assert(!auth_history_session_is_active($otherBrowserHistoryId, $adminId), 'Revoked Administrator session was still considered active.');
    $_SESSION['user_id'] = $adminId;
    $_SESSION['login_history_id'] = $otherBrowserHistoryId;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['last_regenerated_at'] = time();
    clear_current_user_cache();
    maintain_secure_session();
    email_test_assert(empty($_SESSION['user_id']), 'A stale Administrator session was not rejected on its next request.');
    email_test_assert(!login_user($adminEmail, 'AdminLinkPass123'), 'Previous Administrator password remained valid after the Forgot Password flow.');
    email_test_assert(login_user($adminEmail, 'AdminFinalPass456'), 'Administrator could not log in using the final reset password.');

    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');
    $immediateAdminTargetEmail = 'immediate-admin-target-' . bin2hex(random_bytes(3)) . '@example.test';
    $immediateAdminTargetId = email_test_user($immediateAdminTargetEmail, 'staff');
    $mailCountBeforeAdminImmediate = count($mailbox);
    $immediateAdminReset = request_administrator_password_reset($immediateAdminTargetId, $adminId, true);
    email_test_assert(($immediateAdminReset['status'] ?? '') === 'sent', 'Administrator reset reported success before the mail transport accepted the link.');
    email_test_assert(count($mailbox) === $mailCountBeforeAdminImmediate + 1, 'Immediate Administrator reset did not invoke the mail transport.');
    email_test_assert(
        preg_match('/reset-password\.php\?token=([a-f0-9]{64})/', (string) (end($mailbox)['body'] ?? '')) === 1,
        'Immediately delivered Administrator reset omitted its secure link.'
    );

    // Unknown and malformed identifiers must not create reset rows.
    $resetCountBeforeUnknown = (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'];
    foreach (['missing-' . bin2hex(random_bytes(3)) . '@example.test', "invalid-address' OR 1=1 --"] as $unknownIdentifier) {
        $unknownResult = save_password_reset_request($unknownIdentifier);
        email_test_assert(($unknownResult['status'] ?? '') === 'unknown' && empty($unknownResult['reset_id']), 'Unknown reset identifier was offered a code step.');
    }
    email_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetCountBeforeUnknown,
        'Unknown or injected reset identifier created a password-reset record.'
    );
    db_execute('DELETE FROM rate_limits WHERE scope = "password_reset_ip_hour"');
    $limitedResult = [];
    for ($attempt = 0; $attempt <= PASSWORD_RESET_IP_HOURLY_LIMIT; $attempt++) {
        $limitedResult = save_password_reset_request('rate-limit-' . $attempt . '-' . bin2hex(random_bytes(2)) . '@example.test');
    }
    email_test_assert(
        ($limitedResult['status'] ?? '') === 'rate_limited' && !empty($limitedResult['accepted']),
        'Forgot Password IP rate limit did not fail closed with a generic response.'
    );

    $failedEmail = 'failed-' . bin2hex(random_bytes(3)) . '@example.test';
    $failedId = email_test_user($failedEmail);
    $GLOBALS['freds_test_mail_transport'] = static fn (): array => ['success' => false, 'temporary' => false, 'message_id' => '', 'error' => 'Test transport rejected delivery.'];
    request_registration_email_verification($failedId, $failedEmail);
    process_email_queue_batch(1);
    email_test_assert(!customer_registration_email_is_verified($failedId), 'Failed email delivery verified the account.');
    $failedMail = db_find('SELECT status, message, html_message FROM email_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', 'i', [$failedId]);
    email_test_assert(($failedMail['status'] ?? '') === 'failed' && ($failedMail['message'] ?? null) === '' && empty($failedMail['html_message']), 'Failed security email retained its code body.');
    email_test_assert(empty(registration_email_verification_status($failedId)['code_sent'])
        && (int) (registration_email_verification_status($failedId)['retry_after'] ?? -1) === 0,
        'A failed queued email was shown as sent or kept in resend cooldown.');
    $GLOBALS['freds_test_mail_transport'] = $successfulTransport;
    email_test_assert((request_registration_email_verification($failedId, $failedEmail, true)['status'] ?? '') === 'sent',
        'A failed queued verification email could not be retried.');
    $GLOBALS['freds_test_mail_transport'] = static fn (): array => ['success' => false, 'temporary' => false, 'message_id' => '', 'error' => 'Test transport rejected delivery.'];

    db_execute('DELETE FROM rate_limits WHERE scope = "password_reset_ip_hour"');
    $failedForgotEmail = 'failed-forgot-' . bin2hex(random_bytes(3)) . '@example.test';
    $failedForgotId = email_test_user($failedForgotEmail);
    $failedForgot = save_password_reset_request($failedForgotEmail, false, true);
    email_test_assert(($failedForgot['status'] ?? '') === 'delivery_failed', 'PHPMailer/Brevo failure was reported as a successful Forgot Password send.');
    $failedForgotReset = db_find(
        'SELECT is_used, code_hash FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$failedForgotId]
    );
    email_test_assert(
        (int) ($failedForgotReset['is_used'] ?? 0) === 1 && empty($failedForgotReset['code_hash']),
        'Failed Forgot Password delivery left a usable verification code.'
    );

    db_execute('DELETE FROM rate_limits WHERE scope = "password_reset_ip_hour"');
    $temporaryFailureEmail = 'temporary-failure-' . bin2hex(random_bytes(3)) . '@example.test';
    $temporaryFailureId = email_test_user($temporaryFailureEmail);
    $GLOBALS['freds_test_mail_transport'] = static fn (): array => ['success' => false, 'temporary' => true, 'message_id' => '', 'error' => 'Test transport timed out.'];
    $temporaryFailure = save_password_reset_request($temporaryFailureEmail, false, true);
    email_test_assert(($temporaryFailure['status'] ?? '') === 'delivery_failed', 'Temporary SMTP failure incorrectly advanced Forgot Password to code verification.');
    $temporaryFailureReset = db_find(
        'SELECT is_used, code_hash FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$temporaryFailureId]
    );
    email_test_assert(
        (int) ($temporaryFailureReset['is_used'] ?? 0) === 1 && empty($temporaryFailureReset['code_hash']),
        'Temporary SMTP failure left an undelivered verification code usable.'
    );

    $_SESSION['user_id'] = $adminId;
    clear_current_user_cache();
    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');
    $temporaryAdminTargetId = email_test_user('temporary-admin-' . bin2hex(random_bytes(3)) . '@example.test', 'staff');
    $temporaryAdminReset = request_administrator_password_reset($temporaryAdminTargetId, $adminId, true);
    email_test_assert(($temporaryAdminReset['status'] ?? '') === 'deferred', 'Temporary Administrator SMTP failure was incorrectly reported as sent.');

    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');
    $GLOBALS['freds_test_mail_transport'] = static fn (): array => ['success' => false, 'temporary' => false, 'message_id' => '', 'error' => 'Test transport rejected delivery.'];
    $immediateFailedAdminTargetId = email_test_user('failed-admin-immediate-' . bin2hex(random_bytes(3)) . '@example.test', 'staff');
    $immediateFailedAdminReset = request_administrator_password_reset($immediateFailedAdminTargetId, $adminId, true);
    email_test_assert(($immediateFailedAdminReset['status'] ?? '') === 'delivery_failed', 'Failed immediate Administrator email was incorrectly reported as sent.');
    email_test_assert(
        (int) (db_find('SELECT is_used FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1', 'i', [$immediateFailedAdminTargetId])['is_used'] ?? 0) === 1,
        'Failed immediate Administrator delivery left its link usable.'
    );

    db_execute('DELETE FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")');
    $failedAdminReset = request_administrator_password_reset($customerId, $adminId);
    email_test_assert(($failedAdminReset['status'] ?? '') === 'queued', 'Administrator customer reset was not durably queued before transport failure.');
    process_email_queue_batch(50);
    $failedAdminMail = db_find(
        'SELECT status, message, html_message, provider_response FROM email_notifications WHERE password_reset_id = ? LIMIT 1',
        'i',
        [(int) $failedAdminReset['reset_id']]
    );
    email_test_assert(
        ($failedAdminMail['status'] ?? '') === 'failed'
            && ($failedAdminMail['message'] ?? null) === ''
            && empty($failedAdminMail['html_message'])
            && (string) ($failedAdminMail['provider_response'] ?? '') === 'Test transport rejected delivery.',
        'Failed Administrator reset delivery was not recorded safely.'
    );

    echo "Email verification, recovery, admin reset, queue redaction, and repeatable migration tests passed.\n";
} finally {
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
}
