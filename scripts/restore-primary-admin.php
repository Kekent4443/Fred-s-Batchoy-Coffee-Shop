<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$requestedPath = getenv('ADMIN_ROLLBACK_FILE');
if (!is_string($requestedPath) || trim($requestedPath) === '') {
    fwrite(STDERR, 'ADMIN_ROLLBACK_FILE is required.' . PHP_EOL);
    exit(1);
}

$backupDirectory = realpath(APP_ROOT . '/private/backups');
$snapshotPath = realpath(trim($requestedPath));
$directoryPrefix = is_string($backupDirectory) ? rtrim($backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

if (
    !is_string($backupDirectory)
    || !is_string($snapshotPath)
    || !str_starts_with($snapshotPath, $directoryPrefix)
    || strtolower(pathinfo($snapshotPath, PATHINFO_EXTENSION)) !== 'json'
) {
    fwrite(STDERR, 'The rollback file must be a valid JSON snapshot inside private/backups.' . PHP_EOL);
    exit(1);
}

$decoded = json_decode((string) file_get_contents($snapshotPath), true);
$snapshot = is_array($decoded) && is_array($decoded['user'] ?? null) ? $decoded['user'] : null;

if (
    !$snapshot
    || (int) ($snapshot['id'] ?? 0) < 1
    || (string) ($snapshot['role'] ?? '') !== 'administrator'
    || (string) ($snapshot['status'] ?? '') !== 'active'
    || !empty($snapshot['deleted_at'])
    || !filter_var((string) ($snapshot['email'] ?? ''), FILTER_VALIDATE_EMAIL)
    || empty(password_get_info((string) ($snapshot['password'] ?? ''))['algo'])
) {
    fwrite(STDERR, 'The rollback snapshot is not a valid active Administrator record.' . PHP_EOL);
    exit(1);
}

$administratorId = (int) $snapshot['id'];
$database = get_db();
$database->begin_transaction();

try {
    $administrators = db_select(
        'SELECT id FROM users WHERE role = ? ORDER BY id FOR UPDATE',
        's',
        ['administrator']
    );
    if (count($administrators) !== 1 || (int) $administrators[0]['id'] !== $administratorId) {
        throw new RuntimeException('Rollback requires the same sole Administrator user ID.');
    }

    $emailOwner = db_find(
        'SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1 FOR UPDATE',
        'si',
        [(string) $snapshot['email'], $administratorId]
    );
    if ($emailOwner) {
        throw new RuntimeException('The snapshot email now belongs to another user account.');
    }

    db_execute(
        'UPDATE users
         SET full_name = ?, email = ?, password = ?, role = ?, status = ?,
             deleted_at = NULL, deleted_by = NULL, deleted_previous_status = NULL,
             updated_at = NOW()
         WHERE id = ?',
        'sssssi',
        [
            (string) $snapshot['full_name'],
            strtolower((string) $snapshot['email']),
            (string) $snapshot['password'],
            'administrator',
            'active',
            $administratorId,
        ]
    );
    db_execute('DELETE FROM remember_tokens WHERE user_id = ?', 'i', [$administratorId]);
    db_execute('DELETE FROM password_resets WHERE user_id = ?', 'i', [$administratorId]);

    $final = db_select(
        'SELECT id, role, status, deleted_at FROM users WHERE role = ? ORDER BY id FOR UPDATE',
        's',
        ['administrator']
    );
    if (
        count($final) !== 1
        || (int) $final[0]['id'] !== $administratorId
        || (string) $final[0]['status'] !== 'active'
        || !empty($final[0]['deleted_at'])
    ) {
        throw new RuntimeException('Rollback validation failed; no changes were committed.');
    }

    log_audit(null, 'primary_administrator_rollback', 'Protected CLI rollback restored primary Administrator account #' . $administratorId . '.');
    $database->commit();
    fwrite(STDOUT, 'Primary Administrator account #' . $administratorId . ' restored from the private snapshot.' . PHP_EOL);
} catch (Throwable $exception) {
    $database->rollback();
    fwrite(STDERR, 'Administrator rollback failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
