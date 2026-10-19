<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/migrations/runner.php';

function migration_gate_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migration_gate_import(mysqli $database, string $sql): void
{
    if (!$database->multi_query($sql)) {
        throw new RuntimeException('Disposable migration import failed.');
    }
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    if ($database->errno !== 0) {
        throw new RuntimeException('Disposable migration import failed: ' . $database->error);
    }
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
migration_gate_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_migration_gate_test_' . bin2hex(random_bytes(5));
migration_gate_assert((bool) preg_match('/^freds_migration_gate_test_[a-f0-9]{10}$/', $databaseName), 'Unsafe test database name.');
putenv('APP_ENV=production');
putenv('APP_AUTO_MIGRATE=1');
putenv('DB_NAME=' . $databaseName);
require_once dirname(__DIR__) . '/app/config/app.php';
migration_gate_assert(APP_AUTO_MIGRATE === false && !schema_maintenance_enabled(), 'Production accepted auto-migration.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($host, database_setting('DB_USER', 'user', 'root'), database_setting('DB_PASS', 'password'), '', (int) database_setting('DB_PORT', 'port', '3306'));
$baselineManifestPath = null;
try {
    $missingDatabaseRejected = false;
    try {
        get_db();
    } catch (mysqli_sql_exception $exception) {
        $missingDatabaseRejected = (int) $exception->getCode() === 1049;
    }
    migration_gate_assert($missingDatabaseRejected, 'A production request created a missing database.');
    $exists = $admin->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $databaseName . "'");
    migration_gate_assert($exists->num_rows === 0, 'A production request changed the database schema.');

    $admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $database = get_db();
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    migration_execute_sql($database, $schema);
    $database->query("INSERT INTO users (full_name, email, password, phone, address, role, status)
        VALUES ('Migration Sentinel', 'migration-sentinel@example.test', 'unused-test-hash', '0000000000', 'Preserve this row', 'customer', 'active')");
    $sentinelId = $database->insert_id;
    $database->query("UPDATE receipt_settings SET paper_width = '80mm', updated_by = NULL, updated_at = created_at WHERE id = 1");

    // Emulate schema gaps previously repaired only by ensure_* helpers.
    $database->query('DROP TABLE record_states');
    $database->query('ALTER TABLE transactions DROP COLUMN proof_of_payment_path');
    $database->query('ALTER TABLE deliveries DROP COLUMN delivered_at');
    $database->query('ALTER TABLE users DROP COLUMN verification_notes');

    foreach (['2026_09_28_authentication_activity.sql', '2026_09_28_customer_email_verification_codes.sql'] as $migration) {
        $sql = (string) file_get_contents(APP_ROOT . '/database/migrations/' . $migration);
        migration_gate_import($database, $sql);
        migration_gate_import($database, $sql);
    }

    $files = migration_files(APP_ROOT . '/database/migrations');
    migration_assert_canonical_inventory($files);
    migration_assert_supported_base_schema($database);
    migration_install_tracker($database, $files);
    $baselineLines = [];
    foreach ($files as $name => $path) {
        if (!in_array($name, ['2026_09_29_runtime_schema_alignment.sql', '2026_10_07_default_58mm_thermal_print.sql'], true)) {
            $baselineLines[] = $name . ' ' . migration_checksum($path) . ' APPLIED';
        }
    }
    $baselineManifestPath = tempnam(sys_get_temp_dir(), 'freds_baseline_');
    migration_gate_assert($baselineManifestPath !== false, 'Unable to create disposable baseline manifest.');
    file_put_contents($baselineManifestPath, implode("\n", $baselineLines) . "\n");
    migration_baseline($database, $files, $baselineManifestPath);
    migration_gate_assert(migration_run_pending($database, $files, static function (): void {}) === 2,
        'Exactly two genuinely pending migrations should have run.');
    migration_gate_assert(migration_run_pending($database, $files, static function (): void {}) === 0,
        'Already-applied migrations were rerun.');

    foreach ([
        ['users', 'verification_notes'],
        ['transactions', 'proof_of_payment_path'],
        ['deliveries', 'delivered_at'],
    ] as [$table, $column]) {
        $statement = $database->prepare('SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->bind_param('ss', $table, $column);
        $statement->execute();
        migration_gate_assert((int) $statement->get_result()->fetch_assoc()['n'] === 1, 'Migration did not restore ' . $table . '.' . $column);
    }
    migration_gate_assert((int) $database->query("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'record_states'")->fetch_assoc()['n'] === 1,
        'Migration did not restore record_states.');
    migration_gate_assert((string) $database->query('SELECT paper_width FROM receipt_settings WHERE id = 1')->fetch_assoc()['paper_width'] === '58mm',
        'Receipt migration did not normalize the untouched 80mm seed row.');
    $sentinel = $database->query('SELECT full_name, address FROM users WHERE id = ' . (int) $sentinelId)->fetch_assoc();
    migration_gate_assert(($sentinel['full_name'] ?? '') === 'Migration Sentinel' && ($sentinel['address'] ?? '') === 'Preserve this row',
        'Migration changed existing user data.');

    require_once APP_ROOT . '/app/bootstrap.php';
    migration_gate_assert(!schema_maintenance_enabled(), 'Normal production bootstrap enabled schema maintenance.');
    echo "Production auto-migration stayed off; reviewed baseline skipped historical files and ran one pending upgrade without changing existing user data.\n";
} finally {
    if (is_string($baselineManifestPath) && is_file($baselineManifestPath)) {
        unlink($baselineManifestPath);
    }
    $admin->query('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    $admin->close();
}
