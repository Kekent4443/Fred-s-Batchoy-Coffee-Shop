<?php

declare(strict_types=1);

function log_password_recovery_exception(string $context, Throwable $exception): void
{
    $message = preg_replace('/[\r\n\t]+/', ' ', $exception->getMessage()) ?? 'Unknown error.';
    $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $message) ?? $message;

    error_log(
        $context . ' (' . $exception::class . ', code ' . (int) $exception->getCode() . '): '
        . mb_substr(trim($message), 0, 1000)
    );
}

function find_password_reset_user(string $identifier): ?array
{
    $identifier = mb_substr(strtolower(trim($identifier)), 0, 150);

    if ($identifier === '' || filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    return db_find(
        'SELECT id, full_name, email, phone, role, status
         FROM users
         WHERE LOWER(email) = ?
           AND status IN ("active", "pending_verification")
         LIMIT 1',
        's',
        [$identifier]
    );
}

function create_password_reset_record_for_user(array $user, bool $includeVerificationCode, string $purpose = 'password_reset'): array
{
    $userId = (int) ($user['id'] ?? 0);
    $destination = strtolower(trim((string) ($user['email'] ?? '')));

    if ($userId < 1 || filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('This account does not have a valid registered email address.');
    }
    if (!in_array($purpose, ['password_reset', 'admin_password_reset', 'legacy_link'], true)) {
        throw new InvalidArgumentException('Invalid password-reset purpose.');
    }

    // Bounded, request-time cleanup keeps shared hosting installations healthy
    // without requiring cron and only runs when a reset is actually issued.
    db_execute(
        'DELETE FROM password_resets
         WHERE (is_used = 1 OR expires_at < NOW())
           AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
         LIMIT 100'
    );

    // A new request supersedes every unused credential and queued reset email
    // for this account, regardless of whether the request came from the user
    // or an Administrator.
    db_execute(
        'UPDATE password_resets
         SET is_used = 1, used_at = COALESCE(used_at, NOW()), code_hash = NULL, verification_token_hash = NULL
         WHERE user_id = ? AND is_used = 0',
        'i',
        [$userId]
    );
    db_execute(
        'UPDATE email_notifications en
         INNER JOIN password_resets pr ON pr.id = en.password_reset_id
         SET en.status = "expired", en.failed_at = NOW(), en.provider_response = "Superseded by a newer reset request.",
             en.claim_token = NULL, en.locked_until = NULL
         WHERE pr.user_id = ? AND en.status IN ("queued", "deferred")',
        'i',
        [$userId]
    );

    $rawToken = bin2hex(random_bytes(32));
    $verificationCode = $includeVerificationCode ? (string) random_int(100000, 999999) : '';
    $codeHash = $verificationCode !== '' ? email_code_hash($verificationCode, $userId, $destination, $purpose) : null;
    $expiresAt = date('Y-m-d H:i:s', time() + (PASSWORD_RESET_EXPIRY_MINUTES * 60));

    db_execute(
        'INSERT INTO password_resets
            (user_id, email, token, purpose, code_hash, delivery_channel, destination, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, "email", ?, ?, NOW())',
        'issssss',
        [$userId, $destination, hash('sha256', $rawToken), $purpose, $codeHash, $destination, $expiresAt]
    );

    return [
        'reset_id' => db_last_id(),
        'token' => $rawToken,
        'verification_code' => $verificationCode,
        'expires_at' => $expiresAt,
        'destination' => $destination,
    ];
}

function password_reset_link(string $rawToken): string
{
    if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
        throw new InvalidArgumentException('Invalid password-reset token.');
    }

    $link = url('reset-password.php?token=' . rawurlencode($rawToken));
    $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
    $host = (string) parse_url($link, PHP_URL_HOST);

    if ($scheme !== 'https' && !app_host_is_local($host)) {
        throw new RuntimeException('Password-reset links require an HTTPS application URL in production.');
    }

    return $link;
}

function password_reset_record_supports_link(array $reset): bool
{
    $purpose = (string) ($reset['purpose'] ?? '');
    $role = (string) ($reset['user_role'] ?? '');

    if (!empty($reset['code_hash'])
        || !($purpose === 'admin_password_reset' || ($purpose === 'legacy_link' && $role !== 'customer'))
        || !hash_equals(strtolower((string) ($reset['email'] ?? '')), strtolower((string) ($reset['user_email'] ?? '')))) {
        return false;
    }

    return $role !== 'customer'
        || (!empty($reset['user_email_verified_at']) && !empty($reset['user_email_proof_at']));
}

function inspect_password_reset_token(string $rawToken): array
{
    ensure_password_reset_schema();

    if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
        return ['status' => 'invalid', 'reset' => null];
    }

    $reset = db_find(
        'SELECT pr.*, u.email AS user_email, u.status AS user_status, u.role AS user_role,
                u.deleted_at AS user_deleted_at, u.email_verified_at AS user_email_verified_at,
                rv.verified_at AS user_email_proof_at
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
         WHERE pr.token = ? LIMIT 1',
        's',
        [hash('sha256', $rawToken)]
    );

    if (!$reset || (int) ($reset['is_used'] ?? 0) === 1 || !empty($reset['verified_at'])
        || !password_reset_record_supports_link($reset)) {
        return ['status' => 'invalid', 'reset' => $reset];
    }

    if (strtotime((string) $reset['expires_at']) <= time()) {
        return ['status' => 'expired', 'reset' => $reset];
    }

    if (!empty($reset['user_deleted_at']) || !in_array((string) $reset['user_status'], ['active', 'pending_verification'], true)) {
        return ['status' => 'inactive', 'reset' => $reset];
    }

    return ['status' => 'valid', 'reset' => $reset];
}

function verify_password_reset_link(string $rawToken): array
{
    ensure_password_reset_schema();

    if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
        return ['status' => 'invalid'];
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $reset = db_find(
            'SELECT pr.id, pr.user_id, pr.email, pr.code_hash, pr.expires_at, pr.is_used, pr.verified_at, pr.purpose,
                    u.email AS user_email, u.status AS user_status, u.role AS user_role,
                    u.deleted_at AS user_deleted_at, u.email_verified_at AS user_email_verified_at,
                    rv.verified_at AS user_email_proof_at
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
             WHERE pr.token = ? LIMIT 1 FOR UPDATE',
            's',
            [hash('sha256', $rawToken)]
        );

        if (!$reset || (int) $reset['is_used'] === 1 || !empty($reset['verified_at'])
            || !password_reset_record_supports_link($reset)) {
            $database->rollback();
            return ['status' => 'invalid'];
        }
        if (strtotime((string) $reset['expires_at']) <= time()) {
            db_execute(
                'UPDATE password_resets SET is_used = 1, used_at = COALESCE(used_at, NOW()) WHERE id = ?',
                'i',
                [(int) $reset['id']]
            );
            $database->commit();
            return ['status' => 'expired'];
        }
        if (!empty($reset['user_deleted_at']) || !in_array((string) $reset['user_status'], ['active', 'pending_verification'], true)) {
            $database->rollback();
            return ['status' => 'inactive'];
        }

        $verificationToken = bin2hex(random_bytes(32));
        db_execute(
            'UPDATE password_resets
             SET verified_at = NOW(), verification_token_hash = ?
             WHERE id = ?',
            'si',
            [hash('sha256', $verificationToken), (int) $reset['id']]
        );
        $database->commit();

        return [
            'status' => 'verified',
            'reset_id' => (int) $reset['id'],
            'verification_token' => $verificationToken,
            'purpose' => (string) $reset['purpose'],
            'method' => 'link',
        ];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function save_password_reset_request(
    string $identifier,
    bool $requestNewCode = false,
    bool $deliverImmediately = false
): array
{
    ensure_password_reset_schema();
    $identifier = mb_substr(strtolower(trim($identifier)), 0, 150);

    // A system-wide mail outage is not an account-specific condition. Return
    // before consuming request limits so users are not locked out by retries
    // made while delivery is unavailable.
    require_once APP_ROOT . '/app/config/mail.php';
    if (!transactional_mail_is_configured()) {
        error_log('Password recovery mail configuration is invalid: ' . implode('; ', mail_configuration_errors()) . '.');
        return ['status' => 'unavailable', 'accepted' => true];
    }

    if (!rate_limit_allow('password_reset_ip_hour', '', PASSWORD_RESET_IP_HOURLY_LIMIT, 60 * 60)) {
        return ['status' => 'rate_limited', 'accepted' => true];
    }

    // Perform the same deliberately expensive password operation before the
    // account lookup for every identifier. This prevents registered and
    // unknown addresses from having materially different baseline timing.
    password_verify(
        $identifier,
        '$2y$10$G6DiAMxDNdq6weS8ug0uwevJJ93xgcsRcU6jhEnu3Qo1W0stDijAK'
    );

    $database = get_db();
    $database->begin_transaction();

    try {
        // Locking the account row serializes simultaneous requests for the same user.
        $user = db_find(
            'SELECT id, full_name, email, role, status FROM users
             WHERE LOWER(email) = ? AND status IN ("active", "pending_verification")
             LIMIT 1 FOR UPDATE',
            's',
            [$identifier]
        );

        if (!$user) {
            $database->commit();
            return ['status' => 'unknown', 'accepted' => true];
        }

        $userId = (int) $user['id'];
        $destination = (string) $user['email'];
        $latestReset = db_find(
            'SELECT id, purpose, code_hash, is_used, verified_at, expires_at,
                    GREATEST(0, ? - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS retry_after,
                    EXISTS (
                        SELECT 1 FROM email_notifications en
                        WHERE en.password_reset_id = password_resets.id
                          AND en.status IN ("sent", "delivered")
                    ) AS email_sent
             FROM password_resets WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE',
            'ii',
            [PASSWORD_RESET_COOLDOWN_SECONDS, $userId]
        );

        if ($latestReset && ($latestReset['purpose'] ?? '') === 'password_reset'
            && !empty($latestReset['code_hash'])
            && (int) ($latestReset['is_used'] ?? 0) === 0
            && empty($latestReset['verified_at'])
            && strtotime((string) $latestReset['expires_at']) > time()
            && (int) $latestReset['retry_after'] > 0) {
            $database->commit();
            if ((int) ($latestReset['email_sent'] ?? 0) !== 1) {
                return ['status' => 'pending_delivery', 'accepted' => true];
            }
            return [
                'status' => 'cooldown',
                'accepted' => true,
                'purpose' => (string) ($latestReset['purpose'] ?? 'password_reset'),
                'reset_id' => (int) $latestReset['id'],
                'expires_at' => (string) $latestReset['expires_at'],
                'retry_after' => (int) $latestReset['retry_after'],
            ];
        }

        $hourly = db_find(
            'SELECT COUNT(*) AS total FROM password_resets WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            'i',
            [$userId]
        );
        if ((int) ($hourly['total'] ?? 0) >= PASSWORD_RESET_HOURLY_LIMIT) {
            $database->commit();
            return ['status' => 'rate_limited', 'accepted' => true];
        }

        $issuance = create_password_reset_record_for_user($user, true, 'password_reset');
        $resetCode = (string) $issuance['verification_code'];
        $expiresAt = (string) $issuance['expires_at'];
        $channel = 'email';
        $resetId = (int) $issuance['reset_id'];

        $message = 'Password reset request for ' . app_settings()['brand_name'] . '.' . PHP_EOL
            . 'Verification code: ' . $resetCode . PHP_EOL
            . 'This reset request expires in ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes. If you did not request this, please ignore it.';

        $safeBrand = e((string) app_settings()['brand_name']);
        $safeCode = e($resetCode);
        $htmlMessage = '<h2>Password reset request</h2>'
            . '<p>We received a password reset request for your ' . $safeBrand . ' account.</p>'
            . '<p>Your verification code is:</p>'
            . '<p style="font-size:28px;letter-spacing:6px;font-weight:bold">' . $safeCode . '</p>'
            . '<p>This code expires in ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes and can only be used once.</p>'
            . '<p>If you did not request this, you can safely ignore this email.</p>';

        $queued = enqueue_email(
            $userId,
            $destination,
            (string) $user['full_name'],
            'Password Reset Request',
            $message,
            $htmlMessage,
            'password_reset',
            'password-reset:' . $resetId,
            $expiresAt,
            $resetId
        );

        if (empty($queued['queued'])) {
            throw new RuntimeException('Unable to queue password reset email.');
        }
        $queueId = (int) ($queued['id'] ?? 0);

        log_audit($userId, 'password_reset_queued', 'Password reset email queued for asynchronous delivery.');
        $database->commit();

        $deliveryStatus = 'queued';
        if ($deliverImmediately) {
            try {
                $delivery = process_email_queue_batch(1, $queueId);
            } catch (Throwable $exception) {
                log_password_recovery_exception('Immediate password reset delivery failed', $exception);
                $delivery = ['sent' => 0, 'deferred' => 0];
            }

            if ((int) ($delivery['sent'] ?? 0) !== 1) {
                // Interactive recovery may continue only after Brevo accepts
                // the email. Invalidate temporary and permanent failures so
                // an undelivered code cannot be exposed through cooldown or
                // a later queue retry after the UI reported failure.
                db_execute(
                    'UPDATE password_resets
                     SET is_used = 1, used_at = COALESCE(used_at, NOW()), code_hash = NULL, verification_token_hash = NULL
                     WHERE id = ?',
                    'i',
                    [$resetId]
                );
                db_execute(
                    'UPDATE email_notifications
                     SET status = "expired", failed_at = COALESCE(failed_at, NOW()), message = "", html_message = NULL,
                         claim_token = NULL, locked_until = NULL
                     WHERE id = ?',
                    'i',
                    [$queueId]
                );
                log_audit($userId, 'password_reset_delivery_failed', 'Password reset code delivery failed before the recovery response completed.');

                return ['status' => 'delivery_failed', 'accepted' => true];
            }

            $deliveryStatus = 'sent';
        } elseif (function_exists('schedule_opportunistic_email_queue_processing')) {
            schedule_opportunistic_email_queue_processing(true);
        }

        return [
            'status' => 'queued',
            'accepted' => true,
            'purpose' => 'password_reset',
            'reset_id' => $resetId,
            'queue_id' => $queueId,
            'channel' => $channel,
            'expires_at' => $expiresAt,
            'retry_after' => PASSWORD_RESET_COOLDOWN_SECONDS,
            'delivery_status' => $deliveryStatus,
        ];
    } catch (Throwable $exception) {
        try {
            $database->rollback();
        } catch (Throwable) {
            // The connection may have failed or the transaction may already
            // have committed before immediate mail delivery was attempted.
        }
        log_password_recovery_exception('Password reset queueing failed', $exception);
        return ['status' => 'unavailable', 'accepted' => true];
    }
}

function request_administrator_password_reset(
    int $targetUserId,
    int $administratorId,
    bool $deliverImmediately = false
): array
{
    ensure_password_reset_schema();
    $actor = current_user();

    if (
        !$actor
        || (int) ($actor['id'] ?? 0) !== $administratorId
        || ($actor['role'] ?? '') !== 'administrator'
        || ($actor['status'] ?? '') !== 'active'
        || !empty($actor['deleted_at'])
    ) {
        throw new RuntimeException('Only an authenticated Administrator can initiate password resets.');
    }

    require_once APP_ROOT . '/app/config/mail.php';
    $ipAddress = client_ip();
    $database = get_db();
    $database->begin_transaction();

    try {
        $target = db_find(
            'SELECT id, full_name, email, role, status, deleted_at, email_verified_at
             FROM users WHERE id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$targetUserId]
        );

        if (!$target || !empty($target['deleted_at'])) {
            throw new InvalidArgumentException('The selected user account does not exist or has been deleted.');
        }
        if (!in_array((string) $target['status'], ['active', 'pending_verification'], true)) {
            throw new InvalidArgumentException('Password reset is unavailable while this account is inactive or rejected.');
        }
        if (filter_var((string) $target['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('This account does not have a valid registered email address.');
        }

        $customerReset = ($target['role'] ?? '') === 'customer';
        if ($customerReset && !customer_registration_email_is_verified($targetUserId)) {
            throw new InvalidArgumentException('Password reset cannot be sent because this customer\'s email address is not verified.');
        }

        if (!transactional_mail_is_configured()) {
            $database->commit();
            return ['status' => 'unavailable'];
        }

        // Validate and lock the target before consuming either limit. Forged,
        // deleted, inactive, and unverified targets must not exhaust a real
        // Administrator's reset allowance.
        if (!rate_limit_allow('admin_password_reset_actor', (string) $administratorId, ADMIN_PASSWORD_RESET_ACTOR_HOURLY_LIMIT, 60 * 60, false)) {
            log_audit($administratorId, 'admin_password_reset_rate_limited', 'Administrator reset request rate-limited for target user #' . $targetUserId . ' from IP ' . $ipAddress . '.');
            $database->commit();
            return ['status' => 'rate_limited'];
        }
        if (!rate_limit_allow('admin_password_reset_target', (string) $targetUserId, ADMIN_PASSWORD_RESET_TARGET_HOURLY_LIMIT, 60 * 60, false)) {
            log_audit($administratorId, 'admin_password_reset_rate_limited', 'Target account reset rate-limited for user #' . $targetUserId . ' from IP ' . $ipAddress . '.');
            $database->commit();
            return ['status' => 'rate_limited'];
        }

        $issuance = create_password_reset_record_for_user(
            $target,
            false,
            $customerReset ? 'admin_password_reset' : 'legacy_link'
        );
        $resetId = (int) $issuance['reset_id'];
        $resetLink = password_reset_link((string) $issuance['token']);
        $safeName = e((string) $target['full_name']);
        $safeBrand = e((string) app_settings()['brand_name']);
        $safeLink = e($resetLink);
        $subject = 'Set Your New Password';
        $message = 'An Administrator requested a password reset for your ' . app_settings()['brand_name'] . ' account.' . PHP_EOL
            . 'Set your new password using this secure link: ' . $resetLink . PHP_EOL
            . 'This link expires in ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes and can only be used once.' . PHP_EOL
            . 'If you did not expect this message, ignore it and contact support.';
        $htmlMessage = '<h2>Set a new password</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>An Administrator requested a password reset for your ' . $safeBrand . ' account.</p>'
            . '<p><a href="' . $safeLink . '">Set New Password</a></p>'
            . '<p>This secure link expires in ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes and can only be used once.</p>'
            . '<p>If you did not expect this message, ignore it and contact support. Your password has not been changed.</p>';
        $deliveryMethod = 'link';

        $queued = enqueue_email(
            $targetUserId,
            (string) $target['email'],
            (string) $target['full_name'],
            $subject,
            $message,
            $htmlMessage,
            'admin_password_reset',
            'admin-password-reset:' . $resetId,
            (string) $issuance['expires_at'],
            $resetId
        );
        if (empty($queued['queued'])) {
            throw new RuntimeException('Unable to queue the password-reset email.');
        }
        $queueId = (int) ($queued['id'] ?? 0);

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        log_audit($administratorId, 'admin_password_reset_rejected', 'Administrator reset request rejected for target user #' . $targetUserId . ' from IP ' . $ipAddress . '.');
        throw $exception;
    }

    $deliveryStatus = 'queued';
    if ($deliverImmediately) {
        try {
            $delivery = process_email_queue_batch(1, $queueId);
        } catch (Throwable $exception) {
            log_password_recovery_exception('Immediate Administrator reset delivery failed', $exception);
            $delivery = ['sent' => 0, 'deferred' => 0];
        }

        if ((int) ($delivery['sent'] ?? 0) === 1) {
            $deliveryStatus = 'sent';
        } elseif ((int) ($delivery['deferred'] ?? 0) === 1) {
            $deliveryStatus = 'deferred';
            if (function_exists('schedule_opportunistic_email_queue_processing')) {
                schedule_opportunistic_email_queue_processing(true);
            }
        } else {
            db_execute(
                'UPDATE password_resets
                 SET is_used = 1, used_at = COALESCE(used_at, NOW()), verification_token_hash = NULL
                 WHERE id = ?',
                'i',
                [$resetId]
            );
            db_execute(
                'UPDATE email_notifications
                 SET status = "expired", failed_at = COALESCE(failed_at, NOW()), message = "", html_message = NULL,
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ?',
                'i',
                [$queueId]
            );
            log_audit($administratorId, 'admin_password_reset_delivery_failed', 'Administrator password-reset link delivery failed for target user #' . $targetUserId . '.');

            return ['status' => 'delivery_failed', 'delivery_method' => $deliveryMethod];
        }
    } elseif (function_exists('schedule_opportunistic_email_queue_processing')) {
        schedule_opportunistic_email_queue_processing(true);
    }
    log_audit(
        $administratorId,
        'admin_password_reset_queued',
        'Administrator queued a password-reset ' . $deliveryMethod . ' for target user #' . $targetUserId . ' from IP ' . $ipAddress . '.'
    );

    return [
        'status' => $deliveryStatus,
        'delivery_method' => $deliveryMethod,
        'reset_id' => $resetId,
        'queue_id' => $queueId,
        'expires_at' => (string) $issuance['expires_at'],
    ];
}

function verify_password_reset_code(int $resetId, string $email, string $code, string $expectedPurpose = 'password_reset'): array
{
    ensure_password_reset_schema();
    $email = strtolower(trim($email));
    $code = trim($code);

    // Public Forgot Password codes are deliberately limited to this purpose.
    // Admin-issued credentials are links and must never pass through this path.
    if ($resetId < 1 || !preg_match('/^\d{6}$/', $code)
        || $expectedPurpose !== 'password_reset') {
        return ['status' => 'incorrect'];
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $reset = db_find(
            'SELECT pr.*, u.status AS user_status, u.role AS user_role,
                    u.email AS user_email, u.email_verified_at AS user_email_verified_at,
                    rv.verified_at AS user_email_proof_at
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
             WHERE pr.id = ? AND LOWER(pr.email) = ?
             LIMIT 1 FOR UPDATE',
            'is',
            [$resetId, $email]
        );

        if (!$reset) {
            $database->rollback();
            return ['status' => 'invalid'];
        }

        if ((int) $reset['attempt_count'] >= PASSWORD_RESET_MAX_ATTEMPTS) {
            $database->rollback();
            return ['status' => 'too_many_attempts'];
        }

        if ((int) $reset['is_used'] === 1 || !empty($reset['verified_at'])) {
            $database->rollback();
            return ['status' => 'invalid'];
        }

        if (!hash_equals('password_reset', (string) ($reset['purpose'] ?? ''))
            || !hash_equals(strtolower((string) $reset['email']), strtolower((string) $reset['user_email']))) {
            $database->rollback();
            return ['status' => 'invalid'];
        }

        if (strtotime((string) $reset['expires_at']) <= time()) {
            db_execute('UPDATE password_resets SET is_used = 1, used_at = NOW() WHERE id = ?', 'i', [$resetId]);
            $database->commit();
            return ['status' => 'expired'];
        }

        if (!email_code_verify($code, (string) $reset['code_hash'], (int) $reset['user_id'], $email, 'password_reset')) {
            $newAttemptCount = (int) $reset['attempt_count'] + 1;
            $locked = $newAttemptCount >= PASSWORD_RESET_MAX_ATTEMPTS ? 1 : 0;
            db_execute(
                'UPDATE password_resets
                 SET attempt_count = ?, is_used = ?, used_at = IF(? = 1, NOW(), used_at)
                 WHERE id = ?',
                'iiii',
                [$newAttemptCount, $locked, $locked, $resetId]
            );
            $database->commit();

            return [
                'status' => $locked === 1 ? 'too_many_attempts' : 'incorrect',
                'attempts_remaining' => max(0, PASSWORD_RESET_MAX_ATTEMPTS - $newAttemptCount),
            ];
        }

        if (!in_array((string) $reset['user_status'], ['active', 'pending_verification'], true)) {
            $database->rollback();
            return ['status' => 'inactive'];
        }

        $verificationToken = bin2hex(random_bytes(32));
        db_execute(
            'UPDATE password_resets
             SET verified_at = NOW(), verification_token_hash = ?, code_hash = NULL
             WHERE id = ?',
            'si',
            [hash('sha256', $verificationToken), $resetId]
        );
        $database->commit();

        return [
            'status' => 'verified',
            'reset_id' => $resetId,
            'verification_token' => $verificationToken,
            'purpose' => 'password_reset',
            'method' => 'code',
        ];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function password_reset_purpose_matches_method(string $purpose, string $method): bool
{
    return ($method === 'code' && $purpose === 'password_reset')
        || ($method === 'link' && in_array($purpose, ['admin_password_reset', 'legacy_link'], true));
}

function inspect_verified_password_reset(
    int $resetId,
    string $verificationToken,
    string $expectedPurpose,
    string $expectedMethod
): array
{
    ensure_password_reset_schema();

    if ($resetId < 1 || !preg_match('/^[a-f0-9]{64}$/', $verificationToken)
        || !password_reset_purpose_matches_method($expectedPurpose, $expectedMethod)) {
        return ['status' => 'invalid', 'reset' => null];
    }

    $reset = db_find(
        'SELECT pr.*, u.status AS user_status, u.role AS user_role,
                u.email AS user_email, u.email_verified_at AS user_email_verified_at,
                rv.verified_at AS user_email_proof_at
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
         WHERE pr.id = ? LIMIT 1',
        'i',
        [$resetId]
    );

    if (!$reset || empty($reset['verified_at']) || (int) $reset['is_used'] === 1
        || !hash_equals($expectedPurpose, (string) ($reset['purpose'] ?? ''))
        || !hash_equals(strtolower((string) $reset['email']), strtolower((string) $reset['user_email']))
        || ($expectedMethod === 'code' && !empty($reset['code_hash']))
        || ($expectedMethod === 'link' && !password_reset_record_supports_link($reset))) {
        return ['status' => 'invalid', 'reset' => $reset];
    }

    if (strtotime((string) $reset['expires_at']) <= time()) {
        return ['status' => 'expired', 'reset' => $reset];
    }

    $expectedHash = (string) ($reset['verification_token_hash'] ?? '');
    if ($expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $verificationToken))) {
        return ['status' => 'invalid', 'reset' => $reset];
    }

    if (!in_array((string) $reset['user_status'], ['active', 'pending_verification'], true)) {
        return ['status' => 'inactive', 'reset' => $reset];
    }

    return ['status' => 'valid', 'reset' => $reset];
}

function complete_password_reset_with_credential(
    int $resetId,
    string $verificationToken,
    string $newPassword,
    string $expectedPurpose,
    string $expectedMethod
): string
{
    if (preg_match('/^[a-f0-9]{64}$/', $verificationToken) !== 1
        || !password_reset_purpose_matches_method($expectedPurpose, $expectedMethod)) {
        return 'invalid';
    }

    if (strlen($newPassword) < 8 || strlen($newPassword) > 72
        || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        return 'weak_password';
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $lockedReset = db_find(
            'SELECT pr.id, pr.user_id, pr.email, pr.purpose, pr.code_hash, pr.expires_at, pr.is_used, pr.verified_at,
                    pr.token, pr.verification_token_hash, u.status AS user_status, u.role AS user_role,
                    u.email AS user_email, u.email_verified_at AS user_email_verified_at,
                    rv.verified_at AS user_email_proof_at,
                    u.deleted_at AS user_deleted_at
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             LEFT JOIN registration_verifications rv ON rv.user_id = u.id AND LOWER(rv.email) = LOWER(u.email)
             WHERE pr.id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$resetId]
        );

        if (!$lockedReset) {
            $database->rollback();
            return 'invalid';
        }

        $expectedHash = (string) ($lockedReset['verification_token_hash'] ?? '');
        if (
            (int) $lockedReset['is_used'] === 1
            || strtotime((string) $lockedReset['expires_at']) <= time()
            || $expectedHash === ''
            || !hash_equals($expectedHash, hash('sha256', $verificationToken))
            || empty($lockedReset['verified_at'])
            || !hash_equals($expectedPurpose, (string) ($lockedReset['purpose'] ?? ''))
            || ($expectedMethod === 'code' && !empty($lockedReset['code_hash']))
            || ($expectedMethod === 'link' && !password_reset_record_supports_link($lockedReset))
            || !hash_equals(strtolower((string) $lockedReset['email']), strtolower((string) $lockedReset['user_email']))
            || !empty($lockedReset['user_deleted_at'])
            || !in_array((string) $lockedReset['user_status'], ['active', 'pending_verification'], true)
        ) {
            $database->rollback();
            return strtotime((string) $lockedReset['expires_at']) <= time() ? 'expired' : 'invalid';
        }

        $userId = (int) $lockedReset['user_id'];
        db_execute(
            'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?',
            'si',
            [password_hash($newPassword, PASSWORD_DEFAULT), $userId]
        );
        db_execute(
            'UPDATE password_resets
             SET is_used = 1, used_at = COALESCE(used_at, NOW()), code_hash = NULL, verification_token_hash = NULL
             WHERE user_id = ? AND is_used = 0',
            'i',
            [$userId]
        );
        db_execute(
            'UPDATE email_notifications
             SET status = "expired", failed_at = NOW(), message = "", html_message = NULL,
                 claim_token = NULL, locked_until = NULL
             WHERE user_id = ? AND password_reset_id IS NOT NULL AND status IN ("queued", "deferred")',
            'i',
            [$userId]
        );
        db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);

        // Mark every server-recorded browser session as revoked inside the
        // same transaction as the password change. maintain_secure_session()
        // rejects those sessions on their next request.
        auth_history_revoke_user_sessions($userId, 'password_reset');

        create_notification($userId, 'Password Updated', 'Your password was reset successfully. If this was not you, contact support immediately.', 'success');
        log_audit($userId, 'password_reset_completed', 'Password reset completed with a verified single-use reset credential.');
        $database->commit();

        revoke_user_authentication_after_mutation($userId);

        return 'success';
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function reset_password_with_verified_code(int $resetId, string $verificationToken, string $newPassword): string
{
    return complete_password_reset_with_credential(
        $resetId,
        $verificationToken,
        $newPassword,
        'password_reset',
        'code'
    );
}

function reset_password_with_verified_link(
    int $resetId,
    string $verificationToken,
    string $newPassword,
    string $purpose = 'admin_password_reset'
): string {
    return complete_password_reset_with_credential(
        $resetId,
        $verificationToken,
        $newPassword,
        $purpose,
        'link'
    );
}
