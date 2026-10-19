<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function shop_delete_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shop_delete_rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException $exception) {
        shop_delete_assert($exception->getMessage() === $message, 'Unexpected shop deletion rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Unsafe shop location deletion was accepted.');
}

function shop_delete_import_schema(mysqli $database, string $sql): void
{
    if (!$database->multi_query($sql)) {
        throw new RuntimeException('Disposable schema import failed.');
    }
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    if ($database->errno !== 0) {
        throw new RuntimeException('Disposable schema import failed.');
    }
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
shop_delete_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_shop_delete_test_' . bin2hex(random_bytes(5));
shop_delete_assert(preg_match('/^freds_shop_delete_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe test database name.');
$adminDatabase = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$adminDatabase->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    set_exception_handler(static function (Throwable $exception): void {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    });

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    shop_delete_import_schema(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    db_execute('INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'sssssss', ['Shop Test Admin', 'shop-admin@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '0000000000', 'Test address', 'administrator', 'active']);
    $administratorId = db_last_id();
    $viewer = ['id' => $administratorId, 'role' => 'administrator', 'full_name' => 'Shop Test Admin'];
    $_SESSION['user_id'] = $administratorId;

    $payload = [
        'shop_name' => 'Primary Test Shop',
        'complete_address' => 'Test street',
        'google_maps_url' => 'https://maps.google.com/?q=Test',
        'google_maps_embed_url' => '',
        'latitude' => 10.1,
        'longitude' => 123.2,
        'contact_number' => '0000000000',
        'landmark' => '',
        'status' => 'active',
    ];
    $initialId = add_shop_location($viewer, $payload);
    $initial = fetch_shop_locations(true);
    shop_delete_assert(count($initial) === 1, 'Expected one initial shop location.');
    shop_delete_rejects(static fn () => delete_shop_location($initialId, $viewer), 'At least one shop location must remain.');

    $payload['shop_name'] = 'Disposable Test Shop';
    $secondId = add_shop_location($viewer, $payload);
    shop_delete_rejects(static fn () => delete_shop_location($secondId, ['role' => 'manager']), 'Only administrators can delete shop locations.');

    db_execute('INSERT INTO users (full_name, email, password, phone, address, role, status, assigned_shop_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'sssssssi', ['Shop Test Manager', 'shop-manager@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '0000000001', 'Test address', 'manager', 'active', $secondId]);
    $managerId = db_last_id();
    shop_delete_rejects(static fn () => delete_shop_location($secondId, $viewer),
        'Reassign or unassign users linked to this shop before deleting it.');
    db_execute('UPDATE users SET assigned_shop_id = NULL WHERE id = ?', 'i', [$managerId]);

    db_execute('UPDATE shop_locations SET status = "inactive" WHERE id = ?', 'i', [$secondId]);
    shop_delete_rejects(static fn () => delete_shop_location($initialId, $viewer),
        'Activate another shop location before deleting the only active location.');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    clear_current_user_cache();
    ob_start();
    include APP_ROOT . '/public/admin/shop-location.php';
    $page = (string) ob_get_clean();
    shop_delete_assert(str_contains($page, 'name="action" value="delete_location"')
        && str_contains($page, 'btn-delete-icon')
        && str_contains($page, 'data-confirm-title="Delete Shop Location"')
        && str_contains($page, 'name="csrf_token"'), 'Shop list lacks the protected delete action.');

    delete_shop_location($secondId, $viewer);
    shop_delete_assert(fetch_shop_location_by_id($secondId) === null, 'Shop location was not deleted.');
    shop_delete_assert((int) (db_find('SELECT COUNT(*) AS total FROM audit_logs WHERE action = ?', 's', ['shop_location_deleted'])['total'] ?? 0) === 1,
        'Shop deletion was not audited exactly once.');
    shop_delete_assert(count(fetch_shop_locations(true)) === 1, 'Deletion affected another shop location.');
    echo "Shop location delete controls, authorization, safety guards, audit log, and successful deletion passed in a disposable database.\n";
} finally {
    $adminDatabase->query('DROP DATABASE `' . $databaseName . '`');
    $adminDatabase->close();
}
