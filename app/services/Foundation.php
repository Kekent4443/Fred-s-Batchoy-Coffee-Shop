<?php

declare(strict_types=1);

function app_settings(): array
{
    return [
        'brand_name' => 'Fred\'s Batchoy',
        'tagline' => 'Fresh batch meals, fast ordering, and guided transaction tracking.',
        'support_email' => 'georgetabanao123@gmail.com',
    ];
}

function all_roles(): array
{
    return ['administrator', 'manager', 'staff', 'rider', 'customer'];
}

function assignable_account_roles(): array
{
    return ['manager', 'staff', 'rider', 'customer'];
}

function role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function require_allowed_value(string $value, array $allowed, string $message): string
{
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException($message);
    }

    return $value;
}

function require_non_empty_string(string $value, string $message): string
{
    $value = trim($value);

    if ($value === '') {
        throw new InvalidArgumentException($message);
    }

    return $value;
}

function administrator_exists(?int $excludeUserId = null): bool
{
    $sql = 'SELECT id FROM users
            WHERE role = "administrator"';
    $types = '';
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= ' AND id <> ?';
        $types = 'i';
        $params[] = $excludeUserId;
    }

    $sql .= ' LIMIT 1';

    return db_find($sql, $types, $params) !== null;
}

function lock_administrator_accounts(?int $excludeUserId = null): array
{
    $sql = 'SELECT id FROM users WHERE role = "administrator"';
    $types = '';
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= ' AND id <> ?';
        $types = 'i';
        $params[] = $excludeUserId;
    }

    $sql .= ' ORDER BY id FOR UPDATE';

    return db_select($sql, $types, $params);
}

function lock_active_administrators(?int $excludeUserId = null): array
{
    $sql = 'SELECT id FROM users
            WHERE role = "administrator" AND status = "active" AND deleted_at IS NULL';
    $types = '';
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= ' AND id <> ?';
        $types = 'i';
        $params[] = $excludeUserId;
    }

    $sql .= ' ORDER BY id FOR UPDATE';

    return db_select($sql, $types, $params);
}

function assert_active_administrator_invariant(array $existingUser, string $newRole, string $newStatus): void
{
    $userId = (int) ($existingUser['id'] ?? 0);
    $wasActiveAdministrator = ($existingUser['role'] ?? '') === 'administrator'
        && ($existingUser['status'] ?? '') === 'active'
        && empty($existingUser['deleted_at']);
    $willBeActiveAdministrator = $newRole === 'administrator' && $newStatus === 'active';
    $otherActiveAdministrators = lock_active_administrators($userId);

    if ($wasActiveAdministrator && !$willBeActiveAdministrator && $otherActiveAdministrators === []) {
        throw new InvalidArgumentException('The last active Administrator cannot be demoted or deactivated.');
    }

    if (($existingUser['role'] ?? '') !== 'administrator' && $newRole === 'administrator') {
        throw new InvalidArgumentException('Existing accounts cannot be promoted to Administrator.');
    }
}

function log_administrator_policy_rejection(string $action, string $description): void
{
    try {
        log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, $action, $description);
    } catch (Throwable $exception) {
        error_log('Unable to write Administrator policy audit entry: ' . $exception->getMessage());
    }
}

function log_audit(?int $userId, string $action, string $description): void
{
    if ($userId === null) {
        db_execute(
            'INSERT INTO audit_logs (user_id, action, description, created_at) VALUES (NULL, ?, ?, NOW())',
            'ss',
            [$action, $description]
        );
        return;
    }

    db_execute(
        'INSERT INTO audit_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())',
        'iss',
        [$userId, $action, $description]
    );
}

