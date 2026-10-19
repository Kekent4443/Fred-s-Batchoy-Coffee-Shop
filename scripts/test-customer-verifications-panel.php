<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function verification_panel_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function verification_panel_import(mysqli $database, string $sql): void
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
        throw new RuntimeException('Disposable schema import failed: ' . $database->error);
    }
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
verification_panel_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_verification_panel_test_' . bin2hex(random_bytes(5));
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

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    verification_panel_import(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");

    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, "administrator", "active")',
        'sssss',
        ['Panel Test Admin', 'panel-admin@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '0000000000', 'Test address']
    );
    $administratorId = db_last_id();
    $_SESSION['user_id'] = $administratorId;

    $customerIds = [];
    foreach ([
        ['Pending With ID', 'pending-id@example.test', 'ids/test-id.png'],
        ['Pending Without ID', 'pending-no-id@example.test', null],
    ] as [$name, $email, $validIdPath]) {
        db_execute(
            'INSERT INTO users (full_name, email, password, phone, address, role, status, valid_id_path, verification_status)
             VALUES (?, ?, ?, ?, ?, "customer", "pending_verification", ?, "pending")',
            'ssssss',
            [$name, $email, password_hash('TestPassword123', PASSWORD_DEFAULT), '0000000001', 'Test address', $validIdPath]
        );
        $customerIds[$email] = db_last_id();
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['status' => 'pending_verification'];
    clear_current_user_cache();
    ob_start();
    include APP_ROOT . '/public/customer-verifications.php';
    $page = (string) ob_get_clean();

    foreach (['Customer', 'Contact', 'Address', 'Valid ID', 'Status', 'Verifier', 'Actions'] as $label) {
        verification_panel_assert(str_contains($page, 'data-label="' . $label . '"'), 'Mobile card is missing the ' . $label . ' label.');
    }
    verification_panel_assert(str_contains($page, 'Search customers') && str_contains($page, '<span>Status</span>'), 'Filter fields are not labeled.');
    verification_panel_assert(str_contains($page, '2 results'), 'Pending records did not render.');
    verification_panel_assert(substr_count($page, 'data-verification-details-open') === 2, 'Details buttons did not render for both records.');
    verification_panel_assert(substr_count($page, 'name="decision" value="approve"') === 2, 'Only the eligible row and modal should have approval forms.');
    verification_panel_assert(substr_count($page, 'name="decision" value="reject"') === 2, 'Only the eligible row and modal should have rejection forms.');
    verification_panel_assert(str_contains($page, 'Valid ID required'), 'Missing-ID review guard was lost.');
    verification_panel_assert(str_contains($page, 'data-verification-details-dialog'), 'Details dialog did not render.');
    verification_panel_assert(str_contains($page, 'name="csrf_token"'), 'Review forms lost CSRF protection.');

    $missingIdRejected = false;
    try {
        review_customer_registration($customerIds['pending-no-id@example.test'], 'approve', $administratorId);
    } catch (InvalidArgumentException $exception) {
        $missingIdRejected = str_contains($exception->getMessage(), 'valid ID');
    }
    verification_panel_assert($missingIdRejected, 'Server accepted approval without a valid ID.');

    review_customer_registration($customerIds['pending-id@example.test'], 'approve', $administratorId);
    $approved = db_find('SELECT status, verification_status FROM users WHERE id = ?', 'i', [$customerIds['pending-id@example.test']]);
    verification_panel_assert(($approved['status'] ?? '') === 'active' && ($approved['verification_status'] ?? '') === 'approved', 'Approval did not update the customer.');

    echo "Customer verification panel labels, filters, guarded actions, details dialog, CSRF rendering, and approval passed in a disposable database.\n";
} finally {
    $adminDatabase->query('DROP DATABASE `' . $databaseName . '`');
    $adminDatabase->close();
}
