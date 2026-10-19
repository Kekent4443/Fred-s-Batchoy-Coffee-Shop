<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/migrations/runner.php';

function runner_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$host = strtolower(database_setting('DB_HOST', 'host', '127.0.0.1'));
runner_test_assert(in_array($host, ['localhost', '127.0.0.1', '::1'], true), 'Tests require local MySQL.');
$databaseName = 'freds_runner_test_' . bin2hex(random_bytes(5));
runner_test_assert((bool) preg_match('/^freds_runner_test_[a-f0-9]{10}$/', $databaseName), 'Unsafe disposable database name.');
$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freds_runner_test_' . bin2hex(random_bytes(5));
mkdir($temporaryDirectory, 0700);
$admin = new mysqli($host, database_setting('DB_USER', 'user', 'root'), database_setting('DB_PASS', 'password'), '', (int) database_setting('DB_PORT', 'port', '3306'));
$canonicalFiles = migration_files(dirname(__DIR__) . '/database/migrations');
migration_assert_canonical_inventory($canonicalFiles);
$canonicalNames = array_keys($canonicalFiles);
runner_test_assert(array_key_first($canonicalFiles) === '2026_07_05_shop_location_management.sql', 'Unexpected canonical migration order.');
runner_test_assert(array_search('2026_07_05_shop_location_management.sql', $canonicalNames, true)
    < array_search('2026_07_05_contact_us_inquiry_management.sql', $canonicalNames, true), 'Contact migration ran before its shop dependency.');
runner_test_assert(!is_dir(dirname(__DIR__) . '/database/migration'), 'The obsolete singular migration tree still exists.');

try {
    $admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->select_db($databaseName);
    $admin->query('CREATE TABLE runner_sentinel (id INT PRIMARY KEY, value_text VARCHAR(30) NOT NULL)');
    $admin->query("INSERT INTO runner_sentinel VALUES (1, 'preserved')");
    $unsupportedBaseRejected = false;
    try {
        migration_assert_supported_base_schema($admin);
    } catch (RuntimeException $exception) {
        $unsupportedBaseRejected = str_contains($exception->getMessage(), 'Core application schema is missing');
    }
    runner_test_assert($unsupportedBaseRejected, 'An unsupported old schema was not rejected.');

    $trackerPath = dirname(__DIR__) . '/database/migrations/' . MIGRATION_TRACKER_FILE;
    $trackerCopy = $temporaryDirectory . DIRECTORY_SEPARATOR . MIGRATION_TRACKER_FILE;
    copy($trackerPath, $trackerCopy);
    $historicalPath = $temporaryDirectory . '/2026_01_01_historical.sql';
    file_put_contents($historicalPath, 'CREATE TABLE runner_sentinel (id INT PRIMARY KEY);');
    $files = migration_files($temporaryDirectory);
    runner_test_assert(array_key_first($files) === '2026_01_01_historical.sql', 'Migration order is not deterministic.');

    $refused = false;
    try {
        migration_run_pending($admin, $files, static function (): void {});
    } catch (RuntimeException $exception) {
        $refused = str_contains($exception->getMessage(), 'tracker missing');
    }
    runner_test_assert($refused, 'Untracked existing database was not blocked.');
    migration_install_tracker($admin, $files);
    $refused = false;
    try {
        migration_run_pending($admin, $files, static function (): void {});
    } catch (RuntimeException $exception) {
        $refused = str_contains($exception->getMessage(), 'not been reviewed');
    }
    runner_test_assert($refused, 'Unreviewed historical migration was not blocked.');

    $manifest = $temporaryDirectory . '/reviewed.txt';
    file_put_contents($manifest, '2026_01_01_historical.sql ' . migration_checksum($historicalPath) . " APPLIED\n");
    runner_test_assert(migration_baseline($admin, $files, $manifest) === 1, 'Baseline not recorded.');
    $goodPath = $temporaryDirectory . '/2026_01_02_good.sql';
    file_put_contents($goodPath, "CREATE TABLE runner_good (id INT PRIMARY KEY);\nDELIMITER $$\nCREATE PROCEDURE runner_put_good()\nBEGIN\n INSERT INTO runner_good VALUES (1);\nEND$$\nCALL runner_put_good()$$\nDROP PROCEDURE runner_put_good$$\nDELIMITER ;\n");
    $badPath = $temporaryDirectory . '/2026_01_03_bad.sql';
    file_put_contents($badPath, 'CREATE TABLE runner_partial (id INT PRIMARY KEY); THIS IS NOT SQL;');
    $files = migration_files($temporaryDirectory);
    $failed = false;
    try {
        migration_run_pending($admin, $files, static function (): void {});
    } catch (mysqli_sql_exception $exception) {
        $failed = true;
    }
    runner_test_assert($failed, 'Controlled failing migration did not stop the runner.');
    $applied = migration_applied($admin);
    runner_test_assert(isset($applied['2026_01_02_good.sql']), 'Successful migration not recorded.');
    runner_test_assert(!isset($applied['2026_01_03_bad.sql']), 'Failed migration was recorded.');
    runner_test_assert((int) $admin->query('SELECT COUNT(*) AS n FROM runner_good')->fetch_assoc()['n'] === 1, 'Compound SQL was not executed.');
    runner_test_assert((string) $admin->query('SELECT value_text FROM runner_sentinel WHERE id = 1')->fetch_assoc()['value_text'] === 'preserved', 'Existing data changed.');
    unlink($badPath);
    $messages = [];
    runner_test_assert(migration_run_pending($admin, migration_files($temporaryDirectory), static function (string $message) use (&$messages): void { $messages[] = $message; }) === 0,
        'Second run did not skip already-applied migrations.');
    runner_test_assert(in_array('skip 2026_01_02_good.sql', $messages, true), 'Applied migration was not skipped.');
    $admin->query("CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, role VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, deleted_at DATETIME NULL)");
    $admin->query("INSERT INTO users (role, status) VALUES ('administrator', 'active')");
    $historicalRoutine = file_get_contents(dirname(__DIR__) . '/database/migrations/2026_08_07_single_administrator_policy.sql');
    runner_test_assert($historicalRoutine !== false, 'Historical routine migration missing.');
    migration_execute_sql($admin, $historicalRoutine);
    $secondAdminBlocked = false;
    try {
        $admin->query("INSERT INTO users (role, status) VALUES ('administrator', 'active')");
    } catch (mysqli_sql_exception $exception) {
        $secondAdminBlocked = true;
    }
    runner_test_assert($secondAdminBlocked, 'Historical trigger SQL was not installed correctly.');
    $admin->query('CREATE TABLE receipt_settings (
        id INT PRIMARY KEY,
        business_address VARCHAR(255) NOT NULL,
        contact_number VARCHAR(60) NOT NULL,
        contact_email VARCHAR(150) NULL,
        paper_width ENUM("58mm", "80mm") NOT NULL DEFAULT "80mm",
        updated_by INT NULL
    )');
    $admin->query('CREATE TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(120) NOT NULL)');
    $admin->query("INSERT INTO receipt_settings VALUES
        (1, 'Business address not configured', 'Contact number not configured', NULL, '80mm', NULL),
        (2, 'Configured shop', '09170000000', NULL, '80mm', 1)");
    $receiptMigration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/2026_10_07_default_58mm_thermal_print.sql');
    migration_execute_sql($admin, $receiptMigration);
    runner_test_assert((string) $admin->query('SELECT paper_width FROM receipt_settings WHERE id = 1')->fetch_assoc()['paper_width'] === '58mm'
        && (string) $admin->query('SELECT paper_width FROM receipt_settings WHERE id = 2')->fetch_assoc()['paper_width'] === '80mm',
        'Receipt migration did not distinguish untouched and configured widths.');
    $receiptDefault = $admin->query("SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_settings' AND COLUMN_NAME = 'paper_width'")->fetch_assoc();
    runner_test_assert(($receiptDefault['COLUMN_DEFAULT'] ?? null) === '58mm', 'Receipt database default is not 58mm.');
    $admin->query("UPDATE receipt_settings SET paper_width = '80mm', updated_by = 1 WHERE id = 1");
    $admin->query("INSERT INTO audit_logs (action) VALUES ('receipt_settings_updated')");
    migration_execute_sql($admin, $receiptMigration);
    runner_test_assert((string) $admin->query('SELECT paper_width FROM receipt_settings WHERE id = 1')->fetch_assoc()['paper_width'] === '80mm',
        'Receipt migration overwrote an audited 80mm choice.');
    echo "Migration runner ordering, explicit baseline, one-time execution, SQL routines, failure tracking, and data preservation passed.\n";
} finally {
    $admin->query('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    $admin->close();
    foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($temporaryDirectory);
}
