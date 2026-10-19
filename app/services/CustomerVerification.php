<?php

declare(strict_types=1);

function customer_verification_status_label(string $status): string
{
    return match ($status) {
        'pending_verification' => 'Pending Verification',
        'active' => 'Approved',
        'rejected' => 'Rejected',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function fetch_customer_verifications(string $status = 'pending_verification', string $search = '', array $priorityContext = []): array
{
    ensure_customer_verification_schema();

    $allowedStatuses = ['all', 'pending_verification', 'active', 'rejected'];
    $status = in_array($status, $allowedStatuses, true) ? $status : 'pending_verification';
    $search = trim($search);
    $sql = 'SELECT users.id, users.full_name, users.email, users.phone, users.address, users.role, users.status,
                   users.valid_id_path,
                   users.verification_status, users.verification_reviewed_by, users.verification_reviewed_name,
                   users.verification_reviewed_role, users.verification_reviewed_at, users.verification_notes,
                   users.created_at, users.updated_at' . priority_select_sql('customer_verification', [], $priorityContext) . '
            FROM users
            WHERE users.role = "customer"';
    $types = '';
    $params = [];

    if ($status !== 'all') {
        $sql .= ' AND users.status = ?';
        $types .= 's';
        $params[] = $status;
    } else {
        $sql .= ' AND users.status IN ("pending_verification", "active", "rejected")';
    }

    if ($search !== '') {
        $sql .= ' AND (users.full_name LIKE ? OR users.email LIKE ? OR users.phone LIKE ? OR users.address LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY ' . priority_order_sql('customer_verification', 'COALESCE(users.updated_at, users.created_at)', [], $priorityContext);

    return db_select($sql, $types, $params);
}

function send_email_notification(?int $userId, string $email, string $subject, string $message, ?string $htmlMessage = null): bool
{
    $toName = '';

    if ($userId !== null) {
        $recipient = db_find('SELECT full_name FROM users WHERE id = ? LIMIT 1', 'i', [$userId]);
        $toName = trim((string) ($recipient['full_name'] ?? ''));
    }

    if ($toName === '') {
        $toName = $email;
    }

    $result = enqueue_email($userId, $email, $toName, $subject, $message, $htmlMessage);

    return !empty($result['queued']);
}

function mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return 'registered email';
    }

    [$name, $domain] = explode('@', $email, 2);
    $prefix = substr($name, 0, 1);

    return $prefix . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain;
}

function registration_verification_expiry_minutes(): int
{
    return max(5, min(30, (int) (getenv('REGISTRATION_VERIFICATION_EXPIRY_MINUTES') ?: 5)));
}

function registration_verification_cooldown_seconds(): int
{
    return max(60, min(300, (int) (getenv('REGISTRATION_VERIFICATION_COOLDOWN_SECONDS') ?: 60)));
}

function registration_verification_max_attempts(): int
{
    return max(3, min(10, (int) (getenv('REGISTRATION_VERIFICATION_MAX_ATTEMPTS') ?: 5)));
}

function registration_email_verification_status(int $customerId): array
{
    ensure_registration_verification_schema();
    ensure_email_queue_schema();

    $row = db_find(
        'SELECT u.id, u.email, u.email_verified_at, rv.email AS verification_email,
                rv.code_hash, rv.expires_at, rv.last_sent_at, rv.verified_at, rv.attempt_count,
                (SELECT en.status FROM email_notifications en
                 WHERE en.user_id = u.id AND en.message_type = "registration_verification"
                   AND LOWER(en.email) = LOWER(u.email)
                 ORDER BY en.id DESC LIMIT 1) AS latest_delivery_status
         FROM users u
         LEFT JOIN registration_verifications rv ON rv.user_id = u.id
         WHERE u.id = ? AND u.role = "customer" LIMIT 1',
        'i',
        [$customerId]
    );

    if (!$row) {
        return ['status' => 'invalid', 'verified' => false];
    }

    // A legacy migration populated email_verified_at for some active accounts
    // without proving inbox ownership. Require matching code-verification evidence.
    $verified = !empty($row['email_verified_at'])
        && !empty($row['verified_at'])
        && strtolower((string) $row['email']) === strtolower((string) ($row['verification_email'] ?? ''));
    $expired = !$verified && !empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time();
    $hasCode = !$verified && !empty($row['code_hash'])
        && strtolower((string) $row['email']) === strtolower((string) ($row['verification_email'] ?? ''));
    $deliveryStatus = (string) ($row['latest_delivery_status'] ?? '');
    $codeSent = $hasCode && in_array($deliveryStatus, ['sent', 'delivered'], true);
    $deliveryPending = $hasCode && in_array($deliveryStatus, ['queued', 'deferred'], true);
    $retryAfter = ($codeSent || $deliveryPending) && !empty($row['last_sent_at'])
        ? max(0, registration_verification_cooldown_seconds() - (time() - strtotime((string) $row['last_sent_at'])))
        : 0;

    return [
        'status' => $verified ? 'verified' : ($expired ? 'expired' : 'pending'),
        'verified' => $verified,
        'email' => (string) $row['email'],
        'expires_at' => $row['expires_at'] ?? null,
        'last_sent_at' => $row['last_sent_at'] ?? null,
        'code_sent' => $codeSent,
        'delivery_pending' => $deliveryPending,
        'retry_after' => $retryAfter,
        'attempts_remaining' => max(0, registration_verification_max_attempts() - (int) ($row['attempt_count'] ?? 0)),
    ];
}

function customer_registration_email_is_verified(int $customerId): bool
{
    return !empty(registration_email_verification_status($customerId)['verified']);
}

function email_code_hmac_secret(): ?string
{
    require_once APP_ROOT . '/app/config/mail.php';
    $secret = mail_setting('EMAIL_CODE_HMAC_KEY', 'email_code_hmac_key');
    if ($secret === '') {
        return null;
    }
    if (strlen($secret) < 32) {
        throw new RuntimeException('Email code secret configuration is invalid.');
    }

    return $secret;
}

function email_code_hash(string $code, int $userId, string $email, string $purpose): string
{
    $secret = email_code_hmac_secret();
    if ($secret === null) {
        // Legacy-compatible fallback until a private deployment secret is configured.
        return password_hash($code, PASSWORD_DEFAULT);
    }

    $message = $purpose . "\0" . $userId . "\0" . strtolower(trim($email)) . "\0" . $code;
    return 'hmac-sha256:' . hash_hmac('sha256', $message, $secret);
}

function email_code_verify(string $code, string $storedHash, int $userId, string $email, string $purpose): bool
{
    if (str_starts_with($storedHash, 'hmac-sha256:')) {
        $secret = email_code_hmac_secret();
        if ($secret === null) {
            return false;
        }

        $message = $purpose . "\0" . $userId . "\0" . strtolower(trim($email)) . "\0" . $code;
        return hash_equals(substr($storedHash, strlen('hmac-sha256:')), hash_hmac('sha256', $message, $secret));
    }

    // Outstanding bcrypt/Argon hashes remain valid until their normal expiry.
    return password_verify($code, $storedHash);
}

function request_registration_email_verification(int $customerId, string $email, bool $deliverImmediately = false): array
{
    ensure_registration_verification_schema();
    ensure_email_queue_schema();
    require_once APP_ROOT . '/app/config/mail.php';
    $email = strtolower(trim($email));
    if ($customerId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'invalid', 'accepted' => false];
    }

    if (!transactional_mail_is_configured()) {
        return ['status' => 'unavailable', 'accepted' => false];
    }

    if (!rate_limit_allow('registration_verification_email', $email, 5, 60 * 60)) {
        return ['status' => 'rate_limited', 'accepted' => true];
    }

    $database = get_db();
    $database->begin_transaction();
    $queueId = 0;

    try {
        $customer = db_find(
            'SELECT id, full_name, email, role, status, email_verified_at
             FROM users WHERE id = ? AND LOWER(email) = ? LIMIT 1 FOR UPDATE',
            'is',
            [$customerId, $email]
        );
        if (!$customer || ($customer['role'] ?? '') !== 'customer' || !in_array((string) $customer['status'], ['pending_verification', 'active'], true)) {
            $database->rollback();
            return ['status' => 'invalid', 'accepted' => false];
        }

        $verification = db_find(
            'SELECT * FROM registration_verifications WHERE user_id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$customerId]
        );
        if (!empty($customer['email_verified_at']) && !empty($verification['verified_at'])
            && strtolower((string) $verification['email']) === $email) {
            $database->commit();
            return ['status' => 'verified', 'accepted' => true];
        }

        $cooldown = registration_verification_cooldown_seconds();
        if ($verification && !empty($verification['last_sent_at'])) {
            $latestDelivery = db_find(
                'SELECT status FROM email_notifications
                 WHERE user_id = ? AND message_type = "registration_verification" AND LOWER(email) = ?
                 ORDER BY id DESC LIMIT 1',
                'is',
                [$customerId, $email]
            );
            if (in_array((string) ($latestDelivery['status'] ?? ''), ['queued', 'deferred', 'sent', 'delivered'], true)) {
                $retryAfter = $cooldown - (time() - strtotime((string) $verification['last_sent_at']));
                if ($retryAfter > 0) {
                    $database->commit();
                    return ['status' => 'cooldown', 'accepted' => true, 'retry_after' => $retryAfter];
                }
            }
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + (registration_verification_expiry_minutes() * 60));
        db_execute(
            'UPDATE email_notifications
             SET status = "expired", failed_at = NOW(), message = "", html_message = NULL,
                 claim_token = NULL, locked_until = NULL
             WHERE user_id = ? AND message_type = "registration_verification"
               AND status IN ("queued", "deferred")',
            'i',
            [$customerId]
        );
        db_execute(
            'INSERT INTO registration_verifications
                (user_id, email, code_hash, attempt_count, expires_at, last_sent_at, verified_at, created_at, updated_at)
             VALUES (?, ?, ?, 0, ?, NOW(), NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE email = VALUES(email), code_hash = VALUES(code_hash), attempt_count = 0,
                 expires_at = VALUES(expires_at), last_sent_at = NOW(), verified_at = NULL, updated_at = NOW()',
            'isss',
            [$customerId, $email, email_code_hash($code, $customerId, $email, 'email_verification'), $expiresAt]
        );

        $message = 'Your ' . app_settings()['brand_name'] . ' email verification code is ' . $code
            . '. Enter it in the application to verify your registered email. It expires in '
            . registration_verification_expiry_minutes() . ' minutes. If you did not request this code, ignore this email.';
        $html = '<h2>Verify your email address</h2><p>Your email verification code is:</p>'
            . '<p style="font-size:28px;letter-spacing:6px;font-weight:bold">' . e($code) . '</p>'
            . '<p>Enter this code in the application. It expires in ' . registration_verification_expiry_minutes() . ' minutes.</p>'
            . '<p>If you did not request this code, you can ignore this email.</p>';
        $queued = enqueue_email(
            $customerId,
            $email,
            (string) $customer['full_name'],
            'Registration Email Verification',
            $message,
            $html,
            'registration_verification',
            'registration-verification:' . $customerId . ':' . bin2hex(random_bytes(8)),
            $expiresAt
        );
        if (empty($queued['queued'])) {
            throw new RuntimeException('Unable to queue the registration verification email.');
        }
        $queueId = (int) ($queued['id'] ?? 0);
        log_audit($customerId, 'registration_verification_queued', 'Queued an email ownership verification code.');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    if ($queueId > 0 && $deliverImmediately) {
        try {
            $delivery = process_email_queue_batch(1, $queueId);
        } catch (Throwable $exception) {
            error_log('Registration verification delivery failed (' . $exception::class . ', code ' . (int) $exception->getCode() . ').');
            $delivery = ['sent' => 0];
        }

        if ((int) ($delivery['sent'] ?? 0) !== 1) {
            db_execute(
                'UPDATE registration_verifications
                 SET code_hash = "", last_sent_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
                     expires_at = NOW(), attempt_count = 0, updated_at = NOW()
                 WHERE user_id = ? AND LOWER(email) = ?',
                'is',
                [$customerId, $email]
            );
            db_execute(
                'UPDATE email_notifications
                 SET status = "expired", failed_at = COALESCE(failed_at, NOW()), message = "", html_message = NULL,
                     claim_token = NULL, locked_until = NULL
                 WHERE id = ? AND status <> "sent" AND status <> "delivered"',
                'i',
                [$queueId]
            );

            return ['status' => 'delivery_failed', 'accepted' => false];
        }

        return ['status' => 'sent', 'accepted' => true, 'queue_id' => $queueId];
    }

    if ($queueId > 0 && function_exists('schedule_opportunistic_email_queue_processing')) {
        schedule_opportunistic_email_queue_processing(true);
    }

    return ['status' => 'queued', 'accepted' => true, 'queue_id' => $queueId];
}

function verify_registration_email_code(int $customerId, string $email, string $code): array
{
    ensure_registration_verification_schema();
    $email = strtolower(trim($email));
    $code = trim($code);
    if ($customerId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
        return ['status' => 'invalid'];
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $verification = db_find(
            'SELECT rv.*, u.full_name, u.role, u.status, u.email AS user_email, u.email_verified_at
             FROM registration_verifications rv
             INNER JOIN users u ON u.id = rv.user_id
             WHERE rv.user_id = ? AND LOWER(rv.email) = ? AND LOWER(u.email) = ?
             LIMIT 1 FOR UPDATE',
            'iss',
            [$customerId, $email, $email]
        );
        if (!$verification || ($verification['role'] ?? '') !== 'customer'
            || !in_array((string) ($verification['status'] ?? ''), ['active', 'pending_verification'], true)) {
            $database->rollback();
            return ['status' => 'invalid'];
        }
        if (!empty($verification['email_verified_at']) && !empty($verification['verified_at'])) {
            $database->commit();
            return ['status' => 'already_verified'];
        }
        if (strtotime((string) $verification['expires_at']) <= time()) {
            $database->commit();
            return ['status' => 'expired'];
        }

        $attempts = (int) $verification['attempt_count'];
        if ($attempts >= registration_verification_max_attempts()) {
            $database->commit();
            return ['status' => 'too_many_attempts'];
        }
        if (!email_code_verify($code, (string) $verification['code_hash'], $customerId, $email, 'email_verification')) {
            $attempts++;
            db_execute('UPDATE registration_verifications SET attempt_count = ?, updated_at = NOW() WHERE id = ?', 'ii', [$attempts, (int) $verification['id']]);
            $database->commit();
            return [
                'status' => $attempts >= registration_verification_max_attempts() ? 'too_many_attempts' : 'incorrect',
                'attempts_remaining' => max(0, registration_verification_max_attempts() - $attempts),
            ];
        }

        db_execute('UPDATE registration_verifications SET verified_at = NOW(), code_hash = "", updated_at = NOW() WHERE id = ?', 'i', [(int) $verification['id']]);
        db_execute('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?', 'i', [$customerId]);
        db_execute(
            'UPDATE email_notifications
             SET status = "expired", failed_at = NOW(), message = "", html_message = NULL,
                 claim_token = NULL, locked_until = NULL
             WHERE user_id = ? AND message_type = "registration_verification"
               AND status IN ("queued", "deferred")',
            'i',
            [$customerId]
        );
        create_notification(
            $customerId,
            'Email Verified',
            'Your email address is verified. You can now use email-based password recovery.',
            'success',
            null,
            'registration-email-verified:' . $customerId
        );
        log_audit($customerId, 'registration_email_verified', 'Verified ownership of the registration email address.');
        $database->commit();

        return ['status' => 'verified'];
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function notify_customer_verification_request(array $customer, string $title = 'Customer Registration Request'): void
{
    $customerName = (string) ($customer['full_name'] ?? 'customer account');
    $message = 'Customer account request submitted for verification. Customer: ' . $customerName . '.';

    notify_active_roles(['administrator', 'manager', 'staff'], $title, $message, 'info');
}

function notify_account_verification_approval(array $customer, array $verifier): void
{
    $verifierName = (string) ($verifier['full_name'] ?? 'Unknown User');
    $verifierRole = role_label((string) ($verifier['role'] ?? ''));
    $customerName = (string) ($customer['full_name'] ?? 'customer account');
    $message = 'Customer account approved by ' . $verifierName . ' - ' . $verifierRole . '. Customer: ' . $customerName . '.';
    notify_active_roles(['administrator', 'manager', 'staff'], 'Customer Account Approved', $message, 'success', [(int) ($verifier['id'] ?? 0)]);
}

function review_customer_registration(int $customerId, string $decision, int $verifierId, string $notes = ''): void
{
    ensure_customer_verification_schema();

    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Please select a valid verification action.');
    }

    $notes = trim($notes);
    if ($decision === 'reject' && $notes === '') {
        throw new InvalidArgumentException('Please enter a rejection reason.');
    }

    $status = $decision === 'approve' ? 'active' : 'rejected';
    $verificationStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $database = get_db();
    $database->begin_transaction();

    try {
        $verifier = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$verifierId]);
        $customer = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]);

        if (!$customer || ($customer['role'] ?? '') !== 'customer' || !empty($customer['deleted_at'])) {
            throw new InvalidArgumentException('Customer account does not exist.');
        }

        if (!can_manage_customer_verifications($verifier)) {
            throw new InvalidArgumentException('Only active authorized users can review customer registrations.');
        }

        if (($customer['status'] ?? '') !== 'pending_verification' || ($customer['verification_status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Only pending customer registrations can be reviewed.');
        }

        if (empty($customer['valid_id_path'])) {
            throw new InvalidArgumentException('This customer has no valid ID/photo on file.');
        }

        $verifierName = (string) $verifier['full_name'];
        $verifierRole = (string) $verifier['role'];
        $statement = $database->prepare(
            'UPDATE users
             SET status = ?, verification_status = ?, verification_reviewed_by = ?, verification_reviewed_name = ?,
                 verification_reviewed_role = ?, verification_reviewed_at = NOW(), verification_notes = ?, updated_at = NOW()
             WHERE id = ? AND role = "customer" AND status = "pending_verification" AND verification_status = "pending"'
        );
        $statement->bind_param('ssisssi', $status, $verificationStatus, $verifierId, $verifierName, $verifierRole, $notes, $customerId);
        $statement->execute();

        if ($statement->affected_rows !== 1) {
            throw new RuntimeException('The registration was already reviewed by another user.');
        }

        if ($decision === 'approve') {
            $approvalMessage = 'Your ' . app_settings()['brand_name'] . ' customer account has been accepted. You can now log in using your registered email address and password.';
            create_notification($customerId, 'Account Approved', $approvalMessage, 'success', null, 'registration-approved:' . $customerId);
            notify_account_verification_approval($customer, $verifier);

            if (trim((string) $customer['email']) !== '') {
                send_email_notification($customerId, (string) $customer['email'], 'Customer Account Approved', $approvalMessage);
            }
        } else {
            create_notification($customerId, 'Registration Rejected', 'Your customer registration was rejected. Reason: ' . $notes, 'danger');
            notify_active_roles(
                ['administrator', 'manager', 'staff'],
                'Customer Account Rejected',
                'Customer account rejected by ' . $verifierName . '. Customer: ' . (string) $customer['full_name'] . '.',
                'warning',
                [$verifierId]
            );
        }

        log_audit(
            $verifierId,
            'customer_registration_' . $verificationStatus,
            ucfirst($verificationStatus) . ' customer registration #' . $customerId . ' (' . $customer['email'] . ')'
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    revoke_user_authentication_after_mutation($customerId);
}

function resubmit_customer_registration(int $customerId, array $data): void
{
    ensure_customer_verification_schema();

    if (empty($data['valid_id_path'])) {
        throw new InvalidArgumentException('Please upload a valid ID or photo before submitting your registration.');
    }

    $fullName = require_non_empty_string((string) ($data['full_name'] ?? ''), 'Full name is required.');
    $phone = trim((string) ($data['phone'] ?? ''));
    $address = validate_delivery_address((string) ($data['address'] ?? ''), 'customer');
    $password = (string) ($data['password'] ?? '');

    if (strlen($password) < 8 || strlen($password) > 72) {
        throw new InvalidArgumentException('Password must be between 8 and 72 characters long.');
    }

    $phone = validate_user_phone_number($phone);
    $phoneLock = acquire_user_phone_lock($phone);
    try {
    $newIdPath = (string) $data['valid_id_path'];
    $oldIdPath = null;
    $database = get_db();
    $database->begin_transaction();

    try {
        $customer = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]);

        if (!$customer || ($customer['role'] ?? '') !== 'customer' || !empty($customer['deleted_at'])) {
            throw new InvalidArgumentException('Customer account does not exist.');
        }

        if (($customer['status'] ?? '') !== 'rejected') {
            throw new InvalidArgumentException('Only rejected customer registrations can be resubmitted.');
        }

        $phone = ensure_unique_user_contacts((string) $customer['email'], $phone, $customerId);
        $oldIdPath = (string) ($customer['valid_id_path'] ?? '');
        $statement = $database->prepare(
            'UPDATE users
             SET full_name = ?, phone = ?, address = ?, password = ?, status = "pending_verification",
                 valid_id_path = ?, verification_status = "pending", verification_reviewed_by = NULL,
                 verification_reviewed_name = NULL, verification_reviewed_role = NULL, verification_reviewed_at = NULL,
                 verification_notes = NULL, updated_at = NOW()
             WHERE id = ? AND role = "customer" AND status = "rejected"'
        );
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $statement->bind_param('sssssi', $fullName, $phone, $address, $passwordHash, $newIdPath, $customerId);
        $statement->execute();

        if ($statement->affected_rows !== 1) {
            throw new RuntimeException('The registration changed before it could be resubmitted.');
        }

        db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$customerId]);
        db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [$customerId]);
        create_notification($customerId, 'Registration Resubmitted', 'Your updated registration was submitted and is waiting for verification.', 'info');
        notify_customer_verification_request([
            'id' => $customerId,
            'full_name' => $fullName,
            'email' => $customer['email'] ?? '',
        ], 'Customer Registration Resubmitted');
        log_audit($customerId, 'customer_registration_resubmitted', 'Customer registration resubmitted for verification.');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        delete_private_upload($newIdPath);
        throw $exception;
    }
    } finally {
        release_user_phone_lock($phoneLock);
    }

    revoke_user_authentication_after_mutation($customerId);

    if ($oldIdPath !== null && $oldIdPath !== '' && $oldIdPath !== $newIdPath) {
        delete_private_upload($oldIdPath);
    }
}

