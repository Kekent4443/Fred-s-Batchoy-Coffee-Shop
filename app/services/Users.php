<?php

declare(strict_types=1);

function normalize_phone_number(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function validate_phone_number(string $phone): string
{
    $digits = normalize_phone_number($phone);

    if (strlen($digits) < 10 || strlen($digits) > 13) {
        throw new InvalidArgumentException('Please enter a valid phone number.');
    }

    return $digits;
}

function validate_user_phone_number(string $phone): string
{
    $phone = trim($phone);

    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
        throw new InvalidArgumentException('Please enter a valid phone number in 09XXXXXXXXX or +639XXXXXXXXX format.');
    }

    $normalized = str_starts_with($phone, '+639')
        ? '09' . substr($phone, 4)
        : $phone;
    $subscriberDigits = substr($normalized, 2);
    $dummyNumbers = [
        '09000000000',
        '09111111111',
        '09222222222',
        '09333333333',
        '09444444444',
        '09555555555',
        '09666666666',
        '09777777777',
        '09888888888',
        '09999999999',
        '09123456789',
        '09090909090',
        '09101010101',
        '09100000000',
        '09900000000',
    ];

    if (preg_match('/^(\d)\1{8}$/', $subscriberDigits) || in_array($normalized, $dummyNumbers, true)) {
        throw new InvalidArgumentException('Please enter a valid personal phone number.');
    }

    return $normalized;
}

function acquire_user_phone_lock(string $phone): string
{
    // Phone is checked by an expression rather than a unique index because
    // older accounts may use different stored formats. Serialize writers for
    // the normalized value so concurrent requests cannot both pass that check.
    $lockName = 'freds:user-phone:' . substr(hash('sha256', validate_user_phone_number($phone)), 0, 40);
    $result = db_find('SELECT GET_LOCK(?, 5) AS acquired', 's', [$lockName]);
    if ((int) ($result['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Account details are busy. Please try again shortly.');
    }

    return $lockName;
}

function release_user_phone_lock(string $lockName): void
{
    try {
        db_find('SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
    } catch (Throwable $exception) {
        // MySQL also releases the lock when this connection closes. Do not
        // replace the original validation/transaction error with cleanup noise.
        error_log('Could not release the user contact lock (' . get_class($exception) . ').');
    }
}

function user_phone_normalized_sql_expression(string $column = 'phone'): string
{
    $digits = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(' . $column . ', " ", ""), "-", ""), "+", ""), "(", ""), ")", "")';

    return 'CASE WHEN LENGTH(' . $digits . ') = 12 AND LEFT(' . $digits . ', 3) = "639" THEN CONCAT("0", SUBSTRING(' . $digits . ', 3)) ELSE ' . $digits . ' END';
}

function ensure_unique_user_contacts(string $email, string $phone, ?int $excludeUserId = null): string
{
    $emailSql = 'SELECT id FROM users WHERE LOWER(email) = LOWER(?)';
    $emailTypes = 's';
    $emailParams = [$email];

    if ($excludeUserId !== null) {
        $emailSql .= ' AND id <> ?';
        $emailTypes .= 'i';
        $emailParams[] = $excludeUserId;
    }

    $emailSql .= ' LIMIT 1';

    if (db_find($emailSql, $emailTypes, $emailParams)) {
        throw new InvalidArgumentException('Email address is already registered.');
    }

    $phoneLookup = validate_user_phone_number($phone);
    $phoneSql = 'SELECT id FROM users WHERE ' . user_phone_normalized_sql_expression('phone') . ' = ?';
    $phoneTypes = 's';
    $phoneParams = [$phoneLookup];

    if ($excludeUserId !== null) {
        $phoneSql .= ' AND id <> ?';
        $phoneTypes .= 'i';
        $phoneParams[] = $excludeUserId;
    }

    $phoneSql .= ' LIMIT 1';

    if (db_find($phoneSql, $phoneTypes, $phoneParams)) {
        throw new InvalidArgumentException('This phone number is already registered. Please use a different number');
    }

    return $phoneLookup;
}

function validate_delivery_address(string $address, string $role = 'customer'): string
{
    $address = require_non_empty_string($address, 'Address is required.');

    if ($role === 'customer' && strlen($address) < 20) {
        throw new InvalidArgumentException('Please enter the exact and complete delivery address.');
    }

    return $address;
}

function create_user(array $data): int
{
    ensure_customer_verification_schema();

    $role = require_allowed_value(
        (string) ($data['role'] ?? 'customer'),
        assignable_account_roles(),
        'Administrator accounts can only be configured through the protected deployment setup.'
    );
    $allowedStatuses = $role === 'customer'
        ? ['active', 'inactive', 'pending_verification', 'rejected']
        : ['active', 'inactive'];
    $status = require_allowed_value((string) ($data['status'] ?? 'active'), $allowedStatuses, 'Please select a valid account status.');
    $fullName = require_non_empty_string((string) ($data['full_name'] ?? ''), 'Full name is required.');
    $email = strtolower(require_non_empty_string((string) ($data['email'] ?? ''), 'Email address is required.'));
    $password = (string) ($data['password'] ?? '');
    $phone = trim((string) ($data['phone'] ?? ''));
    $address = validate_delivery_address((string) ($data['address'] ?? ''), $role);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    if (strlen($password) < 8 || strlen($password) > 72) {
        throw new InvalidArgumentException('Password must be between 8 and 72 characters long.');
    }

    $phone = validate_user_phone_number($phone);
    $phoneLock = acquire_user_phone_lock($phone);

    try {
        $phone = ensure_unique_user_contacts($email, $phone);

    $verificationStatus = $data['verification_status'] ?? null;

    if ($role === 'customer' && $verificationStatus === null) {
        $verificationStatus = match ($status) {
            'pending_verification' => 'pending',
            'rejected' => 'rejected',
            'active' => 'approved',
            default => null,
        };
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        if ($role === 'administrator' && lock_administrator_accounts() !== []) {
            throw new InvalidArgumentException('Only one Administrator account is allowed in the system.');
        }

        // Recheck inside the transaction. The unique database keys remain the
        // final protection against simultaneous registration requests.
        $phone = ensure_unique_user_contacts($email, $phone);
        db_execute(
            'INSERT INTO users (full_name, email, password, phone, address, role, status, profile_image, valid_id_path, verification_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            'ssssssssss',
            [
                $fullName,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $phone,
                $address,
                $role,
                $status,
                $data['profile_image'] ?? null,
                $data['valid_id_path'] ?? null,
                $verificationStatus,
            ]
        );

        $userId = db_last_id();
        log_audit($userId, 'account_created', 'Account created for role: ' . $role);

        create_notification(
            $userId,
            $role === 'customer' && $status === 'pending_verification'
                ? 'Registration Submitted'
                : 'Welcome to ' . app_settings()['brand_name'],
            $role === 'customer' && $status === 'pending_verification'
                ? 'Your registration is waiting for identity verification by authorized staff.'
                : 'Your account has been created successfully.',
            $role === 'customer' && $status === 'pending_verification' ? 'info' : 'success',
            null,
            'account-created:' . $userId
        );

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        if ($role === 'administrator') {
            log_administrator_policy_rejection(
                'administrator_creation_blocked',
                'Rejected an attempt to create an additional Administrator account for ' . $email . '.'
            );
        }
        delete_private_upload((string) ($data['valid_id_path'] ?? ''));
        throw $exception;
    }

        return $userId;
    } finally {
        release_user_phone_lock($phoneLock);
    }
}

function create_managed_user(array $data): int
{
    $actorRole = (string) (current_user()['role'] ?? '');
    $requestedRole = (string) ($data['role'] ?? '');
    $managerAssignableRoles = ['staff', 'rider'];
    if ($actorRole !== 'administrator'
        && !($actorRole === 'manager' && in_array($requestedRole, $managerAssignableRoles, true))) {
        throw new InvalidArgumentException('You do not have permission to create this account role.');
    }

    $fullName = trim((string) ($data['full_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $address = trim((string) ($data['address'] ?? ''));
    if (strlen($fullName) > 150) {
        throw new InvalidArgumentException('Full name must be 150 characters or fewer.');
    }
    if (strlen($email) > 150) {
        throw new InvalidArgumentException('Email address must be 150 characters or fewer.');
    }
    if (strlen($address) > 1000) {
        throw new InvalidArgumentException('Address must be 1000 characters or fewer.');
    }

    $password = (string) ($data['password'] ?? '');
    if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new InvalidArgumentException('Password must be 8 to 72 characters and include at least one letter and one number.');
    }

    // Administratively created accounts start active. Customer self-registration uses
    // create_user() directly because identity review is a separate workflow.
    $data['status'] = 'active';

    return create_user($data);
}

function revoke_user_authentication_after_mutation(int $userId): void
{
    try {
        if (function_exists('revoke_user_authentication')) {
            revoke_user_authentication($userId, true, true);
        } else {
            db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);
        }
    } catch (Throwable $exception) {
        error_log('Unable to finish authentication revocation for user #' . $userId . ': ' . $exception->getMessage());
    }
}

function invalidate_email_credentials_after_address_change(int $userId): void
{
    db_execute('UPDATE users SET email_verified_at = NULL, updated_at = NOW() WHERE id = ?', 'i', [$userId]);
    db_execute(
        'UPDATE registration_verifications
         SET code_hash = "", verified_at = NULL, expires_at = NOW(), updated_at = NOW()
         WHERE user_id = ?',
        'i',
        [$userId]
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
         WHERE user_id = ? AND status IN ("queued", "deferred")
           AND (password_reset_id IS NOT NULL OR message_type = "registration_verification")',
        'i',
        [$userId]
    );
}

function update_user_profile(int $userId, array $data): void
{
    $fullName = require_non_empty_string((string) ($data['full_name'] ?? ''), 'Full name is required.');
    $email = strtolower(require_non_empty_string((string) ($data['email'] ?? ''), 'Email address is required.'));
    $phone = trim((string) ($data['phone'] ?? ''));
    $currentPassword = (string) ($data['current_password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    $phone = validate_user_phone_number($phone);
    $phoneLock = acquire_user_phone_lock($phone);
    try {
    $database = get_db();
    $database->begin_transaction();

    try {
        $existingUser = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$userId]);

        if (!$existingUser) {
            throw new InvalidArgumentException('User account does not exist.');
        }

        if (!empty($existingUser['deleted_at'])) {
            throw new InvalidArgumentException('Restore this account before editing it.');
        }

        $role = (string) ($existingUser['role'] ?? 'customer');
        $address = validate_delivery_address((string) ($data['address'] ?? ''), $role);
        $emailChanged = !hash_equals(strtolower((string) $existingUser['email']), $email);
        $addressChanged = !hash_equals(
            normalize_customer_delivery_address_value((string) ($existingUser['address'] ?? '')),
            normalize_customer_delivery_address_value($address)
        );

        if ($emailChanged && ($currentPassword === '' || !password_verify($currentPassword, (string) $existingUser['password']))) {
            throw new InvalidArgumentException('Enter your current password to change the email address.');
        }

        $phone = ensure_unique_user_contacts($email, $phone, $userId);
        db_execute(
            'UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?',
            'ssssi',
            [$fullName, $email, $phone, $address, $userId]
        );

        // Profile editing remains a free-form legacy address editor. If a
        // customer changes it there, require a fresh structured confirmation
        // at checkout rather than retaining stale delivery components.
        if ($role === 'customer' && $addressChanged) {
            db_execute(
                'UPDATE users
                 SET delivery_street = NULL, delivery_barangay = NULL, delivery_city = NULL,
                     delivery_province = NULL, delivery_landmark = NULL, updated_at = NOW()
                 WHERE id = ?',
                'i',
                [$userId]
            );
        }

        if ($emailChanged) {
            db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$userId]);
            invalidate_email_credentials_after_address_change($userId);
        }

        log_audit($userId, 'profile_updated', 'Profile information updated' . ($emailChanged ? '; email address changed and existing sign-in/reset grants revoked.' : '.'));
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
    } finally {
        release_user_phone_lock($phoneLock);
    }

    if (isset($emailChanged) && $emailChanged) {
        revoke_user_authentication_after_mutation($userId);
    }

}

function update_user_account(int $id, array $data): void
{
    if ((string) (current_user()['role'] ?? '') !== 'administrator') {
        throw new InvalidArgumentException('You do not have permission to update user accounts.');
    }

    ensure_customer_verification_schema();

    $existingUser = fetch_user_by_id($id);

    if (!$existingUser) {
        throw new InvalidArgumentException('User account does not exist.');
    }

    if (!empty($existingUser['deleted_at'])) {
        throw new InvalidArgumentException('Restore this account before editing it.');
    }

    $role = require_allowed_value((string) ($data['role'] ?? ''), all_roles(), 'Please select a valid account role.');
    $allowedStatuses = $role === 'customer'
        ? ['active', 'inactive', 'pending_verification', 'rejected']
        : ['active', 'inactive'];
    $status = require_allowed_value((string) ($data['status'] ?? ''), $allowedStatuses, 'Please select a valid account status.');
    $fullName = require_non_empty_string((string) ($data['full_name'] ?? ''), 'Full name is required.');
    $email = strtolower(require_non_empty_string((string) ($data['email'] ?? ''), 'Email address is required.'));
    $phone = trim((string) ($data['phone'] ?? ''));
    $address = validate_delivery_address((string) ($data['address'] ?? ''), $role);
    $password = (string) ($data['password'] ?? '');

    if (strlen($fullName) > 150) {
        throw new InvalidArgumentException('Full name must be 150 characters or fewer.');
    }
    if (strlen($email) > 150) {
        throw new InvalidArgumentException('Email address must be 150 characters or fewer.');
    }
    if (strlen($address) > 1000) {
        throw new InvalidArgumentException('Address must be 1000 characters or fewer.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    if ($password !== '') {
        throw new InvalidArgumentException('Use the Reset Password action so the account owner can choose a new password securely.');
    }

    $phone = validate_user_phone_number($phone);
    $phoneLock = acquire_user_phone_lock($phone);
    try {
    $phone = ensure_unique_user_contacts($email, $phone, $id);
    $database = get_db();
    $database->begin_transaction();
    $authenticationChanged = false;

    try {
        $lockedUser = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
        if (!$lockedUser || !empty($lockedUser['deleted_at'])) {
            throw new InvalidArgumentException('User account is not available for editing.');
        }

        if (($lockedUser['role'] ?? '') === 'customer' && $role === 'customer'
            && in_array((string) ($lockedUser['status'] ?? ''), ['pending_verification', 'rejected'], true)
            && $status === 'active') {
            throw new InvalidArgumentException('Please use the Customer Verification panel to approve this customer account.');
        }

        assert_active_administrator_invariant($lockedUser, $role, $status);

        if (($lockedUser['role'] ?? '') === 'customer' && $role !== 'customer') {
            $customerHistory = db_find('SELECT id FROM orders WHERE customer_id = ? LIMIT 1', 'i', [$id]);
            if ($customerHistory) {
                throw new InvalidArgumentException('A customer with transaction history cannot be changed to another role.');
            }
        }

        if (($lockedUser['role'] ?? '') === 'rider' && ($role !== 'rider' || $status !== 'active')) {
            $activeDelivery = db_find(
                'SELECT id FROM deliveries
                 WHERE assigned_staff_id = ? AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported")
                 LIMIT 1 FOR UPDATE',
                'i',
                [$id]
            );
            if ($activeDelivery) {
                throw new InvalidArgumentException('Reassign this rider\'s active deliveries before changing the account role or status.');
            }
        }

        if (($lockedUser['role'] ?? '') === 'staff' && ($role !== 'staff' || $status !== 'active')) {
            $activePreparedOrder = db_find(
                'SELECT id FROM orders
                 WHERE prepared_by_staff_id = ? AND order_status IN ("confirmed", "processing")
                 LIMIT 1 FOR UPDATE',
                'i',
                [$id]
            );
            if ($activePreparedOrder) {
                throw new InvalidArgumentException('Complete or transfer this staff account\'s active prepared orders first.');
            }
        }

        $phone = ensure_unique_user_contacts($email, $phone, $id);
        $emailChanged = !hash_equals(strtolower((string) $lockedUser['email']), $email);
        $addressChanged = $role === 'customer' && !hash_equals(
            normalize_customer_delivery_address_value((string) ($lockedUser['address'] ?? '')),
            normalize_customer_delivery_address_value($address)
        );
        $authenticationChanged = $emailChanged
            || (string) $lockedUser['role'] !== $role
            || (string) $lockedUser['status'] !== $status;
        $sql = 'UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?, role = ?, status = ?, updated_at = NOW()';
        $types = 'ssssss';
        $params = [$fullName, $email, $phone, $address, $role, $status];

        $sql .= ' WHERE id = ?';
        $types .= 'i';
        $params[] = $id;
        db_execute($sql, $types, $params);

        if ($addressChanged) {
            // The structured checkout address must not remain attached to a different edited address.
            db_execute(
                'UPDATE users SET delivery_street = NULL, delivery_barangay = NULL, delivery_city = NULL,
                    delivery_province = NULL, delivery_landmark = NULL, updated_at = NOW() WHERE id = ?',
                'i',
                [$id]
            );
        }

        if ($emailChanged) {
            invalidate_email_credentials_after_address_change($id);
        }

        if ($role === 'customer' && in_array($status, ['active', 'pending_verification', 'rejected'], true)) {
            $verificationStatus = match ($status) {
                'active' => 'approved',
                'pending_verification' => 'pending',
                'rejected' => 'rejected',
                default => null,
            };
            db_execute('UPDATE users SET verification_status = ?, updated_at = NOW() WHERE id = ?', 'si', [$verificationStatus, $id]);
        }

        if ($authenticationChanged) {
            db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$id]);
        }
        if (!hash_equals(strtolower((string) $lockedUser['email']), $email)) {
            db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [$id]);
        }

        log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'user_updated', 'Updated user account #' . $id);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        if (($existingUser['role'] ?? '') !== 'administrator' && $role === 'administrator') {
            log_administrator_policy_rejection(
                'administrator_promotion_blocked',
                'Rejected an attempt to promote user #' . $id . ' from ' . (string) ($existingUser['role'] ?? 'unknown') . ' to Administrator.'
            );
        }
        throw $exception;
    }
    } finally {
        release_user_phone_lock($phoneLock);
    }

    if ($authenticationChanged) {
        revoke_user_authentication_after_mutation($id);
    }
}

function user_relation_columns(): array
{
    // Only relationships representing protected operational history force a
    // soft deletion. Ephemeral rows (sessions, reset codes and notifications)
    // have explicit cascade/SET NULL policies in the schema.
    $protectedRelations = [
        ['table' => 'orders', 'column' => 'customer_id'],
        ['table' => 'orders', 'column' => 'prepared_by_staff_id'],
        ['table' => 'orders', 'column' => 'assigned_staff_id'],
        ['table' => 'transactions', 'column' => 'recorded_by'],
        ['table' => 'deliveries', 'column' => 'assigned_staff_id'],
        ['table' => 'delivery_history', 'column' => 'changed_by'],
        ['table' => 'payment_review_history', 'column' => 'reviewed_by'],
        ['table' => 'contact_messages', 'column' => 'user_id'],
        ['table' => 'contact_messages', 'column' => 'responded_by'],
        ['table' => 'support_conversations', 'column' => 'customer_id'],
        ['table' => 'support_conversations', 'column' => 'assigned_user_id'],
        ['table' => 'support_internal_notes', 'column' => 'user_id'],
        ['table' => 'gcash_payment_accounts', 'column' => 'updated_by'],
        ['table' => 'users', 'column' => 'verification_reviewed_by'],
    ];

    return $protectedRelations;
}

function user_deletion_assessment(int $userId): array
{
    $related = [];

    try {
        foreach (user_relation_columns() as $relation) {
            $table = $relation['table'];
            $column = $relation['column'];
            $row = db_find(
                'SELECT COUNT(*) AS total FROM `' . $table . '` WHERE `' . $column . '` = ?',
                'i',
                [$userId]
            );
            $count = (int) ($row['total'] ?? 0);
            if ($count > 0) {
                $related[] = ['table' => $table, 'column' => $column, 'count' => $count];
            }
        }
    } catch (Throwable $exception) {
        error_log('User deletion dependency check failed for user #' . $userId . ': ' . $exception->getMessage());

        return ['has_related' => true, 'uncertain' => true, 'relations' => $related];
    }

    return ['has_related' => $related !== [], 'uncertain' => false, 'relations' => $related];
}

function conditional_delete_user_account(int $id, int $deletedById, bool $permanentConfirmed = false): array
{
    ensure_conditional_user_deletion_schema();
    $db = get_db();
    $db->begin_transaction();
    $actorAuthorized = false;

    try {
        $deletionActor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$deletedById]);
        if (!$deletionActor || ($deletionActor['role'] ?? '') !== 'administrator'
            || ($deletionActor['status'] ?? '') !== 'active' || !empty($deletionActor['deleted_at'])) {
            throw new InvalidArgumentException('Only an active Administrator can delete user accounts.');
        }
        $actorAuthorized = true;
        $user = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);

        if (!$user) {
            throw new InvalidArgumentException('User account does not exist.');
        }

        if ($id === $deletedById) {
            throw new InvalidArgumentException('You cannot delete the account you are currently using.');
        }

        if (
            $user['role'] === 'administrator'
            && $user['status'] === 'active'
            && empty($user['deleted_at'])
            && lock_active_administrators($id) === []
        ) {
            throw new InvalidArgumentException('The last active Administrator account cannot be deleted.');
        }

        $assessment = user_deletion_assessment($id);
        if ($assessment['has_related'] || $assessment['uncertain']) {
            db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$id]);
            db_execute(
                'UPDATE users SET deleted_previous_status = COALESCE(deleted_previous_status, status), status = "inactive", deleted_at = COALESCE(deleted_at, NOW()), deleted_by = ?, updated_at = NOW() WHERE id = ?',
                'ii',
                [$deletedById, $id]
            );
            log_audit(
                $deletedById,
                'user_soft_deleted',
                'Soft deleted user account #' . $id . ' (' . $user['role'] . ') because linked history exists' . ($assessment['uncertain'] ? ' or could not be fully verified' : '') . '.'
            );
            $db->commit();

            revoke_user_authentication_after_mutation($id);

            return ['mode' => 'soft', 'relations' => $assessment['relations'], 'uncertain' => $assessment['uncertain']];
        }

        if (!$permanentConfirmed) {
            throw new InvalidArgumentException('Permanent deletion requires Administrator confirmation.');
        }

        db_execute('DELETE FROM users WHERE id = ?', 'i', [$id]);
        log_audit($deletedById, 'user_permanently_deleted', 'Permanently deleted user account #' . $id . ' (' . $user['role'] . ') after confirming that no linked records exist.');
        $db->commit();

        revoke_user_authentication_after_mutation($id);
        delete_private_upload((string) ($user['valid_id_path'] ?? ''));

        return ['mode' => 'permanent', 'relations' => [], 'uncertain' => false];
    } catch (Throwable $exception) {
        $db->rollback();

        if ($exception instanceof mysqli_sql_exception && $actorAuthorized) {
            error_log('Permanent user deletion failed for user #' . $id . '; defaulting to soft deletion: ' . $exception->getMessage());
            $db->begin_transaction();
            try {
                $user = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
                if (!$user) {
                    throw new InvalidArgumentException('User account does not exist.');
                }
                db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$id]);
                db_execute(
                    'UPDATE users SET deleted_previous_status = COALESCE(deleted_previous_status, status), status = "inactive", deleted_at = COALESCE(deleted_at, NOW()), deleted_by = ?, updated_at = NOW() WHERE id = ?',
                    'ii',
                    [$deletedById, $id]
                );
                log_audit($deletedById, 'user_soft_deleted', 'Soft deleted user account #' . $id . ' after a database relationship prevented permanent deletion.');
                $db->commit();

                revoke_user_authentication_after_mutation($id);

                return ['mode' => 'soft', 'relations' => [], 'uncertain' => true];
            } catch (Throwable $fallbackException) {
                $db->rollback();
                throw $fallbackException;
            }
        }

        throw $exception;
    }
}

function restore_soft_deleted_user(int $id, int $restoredById): void
{
    ensure_conditional_user_deletion_schema();
    $db = get_db();
    $db->begin_transaction();

    try {
        $restorationActor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$restoredById]);
        if (!$restorationActor || ($restorationActor['role'] ?? '') !== 'administrator'
            || ($restorationActor['status'] ?? '') !== 'active' || !empty($restorationActor['deleted_at'])) {
            throw new InvalidArgumentException('Only an active Administrator can restore user accounts.');
        }
        $user = db_find('SELECT id, role, deleted_at, deleted_previous_status FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
        if (!$user || empty($user['deleted_at'])) {
            throw new InvalidArgumentException('This account is not available for restoration.');
        }

        $restoreStatus = in_array((string) ($user['deleted_previous_status'] ?? ''), ['active', 'inactive', 'pending_verification', 'rejected'], true)
            ? (string) $user['deleted_previous_status']
            : 'inactive';

        if ($user['role'] === 'administrator' && lock_administrator_accounts($id) !== []) {
            throw new InvalidArgumentException('Only one Administrator account is allowed in the system.');
        }
        db_execute('UPDATE users SET status = ?, deleted_at = NULL, deleted_by = NULL, deleted_previous_status = NULL, updated_at = NOW() WHERE id = ?', 'si', [$restoreStatus, $id]);
        log_audit($restoredById, 'user_restored', 'Restored soft-deleted user account #' . $id . ' (' . $user['role'] . ').');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function fetch_users(?string $role = null, string $search = ''): array
{
    $sql = 'SELECT id, full_name, email, email_verified_at, phone, address, role, status, deleted_at, deleted_by, created_at,
                   CASE
                       WHEN role <> "customer" THEN 1
                       WHEN email_verified_at IS NOT NULL AND EXISTS (
                           SELECT 1 FROM registration_verifications rv
                           WHERE rv.user_id = users.id
                             AND LOWER(rv.email) = LOWER(users.email)
                             AND rv.verified_at IS NOT NULL
                       ) THEN 1
                       ELSE 0
                   END AS password_reset_eligible' . priority_select_sql('user') . ' FROM users WHERE 1=1';
    $types = '';
    $params = [];

    if ($role) {
        $sql .= ' AND role = ?';
        $types .= 's';
        $params[] = $role;
    }

    if ($search !== '') {
        $sql .= ' AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $sql .= ' ORDER BY ' . priority_order_sql('user', 'COALESCE(updated_at, created_at)');

    return db_select($sql, $types, $params);
}

function fetch_user_by_id(int $id): ?array
{
    return db_find('SELECT * FROM users WHERE id = ? LIMIT 1', 'i', [$id]);
}

