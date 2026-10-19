<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function required_environment_value(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($name . ' is required.');
    }

    return trim($value);
}

try {
    $primaryAdminFullName = required_environment_value('ADMIN_FULL_NAME');
    $primaryAdminEmail = strtolower(required_environment_value('ADMIN_EMAIL'));
    if (strlen($primaryAdminFullName) > 150 || strlen($primaryAdminEmail) > 150
        || filter_var($primaryAdminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Provide a valid ADMIN_EMAIL and an ADMIN_FULL_NAME of at most 150 characters.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Administrator setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

function primary_admin_setup_completed(int $administratorId): bool
{
    return db_find(
        'SELECT id FROM audit_logs
         WHERE action = ? AND description = ?
         LIMIT 1',
        'ss',
        [
            'primary_administrator_configured',
            'Protected CLI setup configured primary Administrator account #' . $administratorId . '.',
        ]
    ) !== null;
}

function write_primary_admin_rollback_snapshot(array $administrator): string
{
    $backupDirectory = APP_ROOT . '/private/backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Unable to create the private rollback directory.');
    }

    $snapshot = [
        'version' => 1,
        'created_at' => gmdate('c'),
        'user' => array_intersect_key($administrator, array_flip([
            'id',
            'full_name',
            'email',
            'password',
            'role',
            'status',
            'deleted_at',
            'deleted_by',
            'deleted_previous_status',
            'updated_at',
        ])),
    ];
    $filename = 'primary-admin-' . (int) $administrator['id'] . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $path = $backupDirectory . '/' . $filename;
    $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded) || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the private Administrator rollback snapshot.');
    }

    @chmod($path, 0600);

    return $path;
}

$database = get_db();
$database->begin_transaction();

try {
    $administrators = db_select(
        'SELECT * FROM users WHERE role = ? ORDER BY id FOR UPDATE',
        's',
        ['administrator']
    );

    if (count($administrators) > 1) {
        $ids = implode(', ', array_map(static fn (array $row): string => (string) (int) $row['id'], $administrators));
        throw new RuntimeException('Multiple Administrator accounts require manual review. Affected user IDs: ' . $ids . '.');
    }

    $emailOwner = db_find(
        'SELECT id, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1 FOR UPDATE',
        's',
        [$primaryAdminEmail]
    );
    $administrator = $administrators[0] ?? null;

    if ($emailOwner && (!$administrator || (int) $emailOwner['id'] !== (int) $administrator['id'])) {
        throw new RuntimeException('The target Administrator email belongs to another user account. No changes were made.');
    }

    $forcePasswordReset = getenv('ADMIN_FORCE_PASSWORD_RESET') === '1';
    if (
        $administrator
        && !$forcePasswordReset
        && primary_admin_setup_completed((int) $administrator['id'])
        && hash_equals(strtolower((string) $administrator['email']), $primaryAdminEmail)
        && (string) $administrator['full_name'] === $primaryAdminFullName
        && (string) $administrator['status'] === 'active'
        && empty($administrator['deleted_at'])
    ) {
        $database->commit();
        fwrite(STDOUT, 'Primary Administrator is already configured; no credentials were changed.' . PHP_EOL);
        exit(0);
    }

    $temporaryPassword = required_environment_value('ADMIN_TEMP_PASSWORD');
    if (strlen($temporaryPassword) < 8 || strlen($temporaryPassword) > 72) {
        throw new RuntimeException('ADMIN_TEMP_PASSWORD must be between 8 and 72 characters long.');
    }

    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Unable to generate the Administrator password hash.');
    }

    $rollbackPath = null;
    if ($administrator) {
        $rollbackPath = write_primary_admin_rollback_snapshot($administrator);
        $administratorId = (int) $administrator['id'];
        db_execute(
            'UPDATE users
             SET full_name = ?, email = ?, password = ?, role = ?, status = ?,
                 deleted_at = NULL, deleted_by = NULL, deleted_previous_status = NULL,
                 updated_at = NOW()
             WHERE id = ?',
            'sssssi',
            [
                $primaryAdminFullName,
                $primaryAdminEmail,
                $passwordHash,
                'administrator',
                'active',
                $administratorId,
            ]
        );
    } else {
        $phone = ensure_unique_user_contacts(
            $primaryAdminEmail,
            required_environment_value('ADMIN_PHONE')
        );
        $address = validate_delivery_address(required_environment_value('ADMIN_ADDRESS'), 'administrator');
        db_execute(
            'INSERT INTO users
             (full_name, email, password, phone, address, role, status,
              can_edit_shop_location, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            'sssssss',
            [
                $primaryAdminFullName,
                $primaryAdminEmail,
                $passwordHash,
                $phone,
                $address,
                'administrator',
                'active',
            ]
        );
        $administratorId = db_last_id();
    }

    db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$administratorId]);
    db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [$administratorId]);

    $finalAdministrators = db_select(
        'SELECT id, full_name, email, role, status, deleted_at, password
         FROM users WHERE role = ? ORDER BY id FOR UPDATE',
        's',
        ['administrator']
    );

    if (count($finalAdministrators) !== 1) {
        throw new RuntimeException('The system must contain exactly one Administrator.');
    }

    $finalAdministrator = $finalAdministrators[0];
    if (
        (int) $finalAdministrator['id'] !== $administratorId
        || !hash_equals(strtolower((string) $finalAdministrator['email']), $primaryAdminEmail)
        || (string) $finalAdministrator['full_name'] !== $primaryAdminFullName
        || (string) $finalAdministrator['status'] !== 'active'
        || !empty($finalAdministrator['deleted_at'])
        || !password_verify($temporaryPassword, (string) $finalAdministrator['password'])
    ) {
        throw new RuntimeException('Administrator verification failed; no changes were committed.');
    }

    log_audit(
        null,
        $forcePasswordReset ? 'primary_administrator_password_rotated' : 'primary_administrator_configured',
        ($forcePasswordReset ? 'Protected CLI setup rotated' : 'Protected CLI setup configured')
            . ' primary Administrator account #' . $administratorId . '.'
    );

    $database->commit();
    fwrite(STDOUT, 'Primary Administrator account #' . $administratorId . ' configured successfully.' . PHP_EOL);
    if (is_string($rollbackPath)) {
        fwrite(STDOUT, 'Private rollback snapshot: ' . $rollbackPath . PHP_EOL);
    }
} catch (Throwable $exception) {
    $database->rollback();
    fwrite(STDERR, 'Administrator setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
