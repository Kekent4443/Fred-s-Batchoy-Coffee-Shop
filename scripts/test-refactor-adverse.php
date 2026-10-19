<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function adverse_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adverse_import_schema(mysqli $database, string $sql): void
{
    adverse_assert($database->multi_query($sql), 'Disposable schema import failed.');
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    adverse_assert($database->errno === 0, 'Disposable schema import failed.');
}

function adverse_workers(string $operation, int $count, string $barrier): array
{
    putenv('ADVERSE_BARRIER=' . $barrier);
    $processes = [];
    for ($index = 0; $index < $count; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--worker', $operation, (string) $index],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        adverse_assert(is_resource($process), 'Could not start a local test worker.');
        $processes[] = [$process, $pipes];
    }

    file_put_contents($barrier, 'start');
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        adverse_assert($exitCode === 0 && $error === '', 'A local test worker failed.');
        $result = json_decode($output, true);
        adverse_assert(is_array($result), 'A local test worker returned invalid output.');
        $results[] = $result;
    }
    unlink($barrier);
    return $results;
}

if (($argv[1] ?? '') === '--worker') {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $barrier = (string) getenv('ADVERSE_BARRIER');
    $deadline = microtime(true) + 10.0;
    while (!is_file($barrier) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    adverse_assert(is_file($barrier), 'Local test barrier timed out.');

    $operation = (string) ($argv[2] ?? '');
    $index = (int) ($argv[3] ?? 0);
    try {
        if ($operation === 'notification') {
            create_notification((int) getenv('ADVERSE_USER_ID'), 'Test event', 'Disposable test only.', 'info', null, 'adverse-shared-event');
        } elseif ($operation === 'user') {
            create_user([
                'role' => 'customer',
                'status' => 'active',
                'full_name' => 'Concurrent Test User',
                'email' => 'adverse-' . $index . '@example.test',
                'password' => 'Disposable123',
                'phone' => (string) getenv('ADVERSE_PHONE'),
                'address' => 'Disposable address for local testing only',
            ]);
        } else {
            throw new RuntimeException('Unknown test operation.');
        }
        echo json_encode(['ok' => true]);
    } catch (Throwable $exception) {
        echo json_encode(['ok' => false, 'class' => get_class($exception), 'code' => (int) $exception->getCode()]);
    }
    exit;
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
adverse_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_adverse_test_' . bin2hex(random_bytes(5));
adverse_assert(preg_match('/^freds_adverse_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe disposable database name.');
$barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freds-adverse-' . bin2hex(random_bytes(8)) . '.barrier';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$failure = null;

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    putenv('APP_DEBUG=0');
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    adverse_import_schema(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");

    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, "administrator", "active")',
        'sssss',
        ['Adverse Test Admin', 'adverse-admin@example.test', password_hash('Disposable123', PASSWORD_DEFAULT), '09175550001', 'Disposable test address']
    );
    $administratorId = db_last_id();
    $_SESSION['user_id'] = $administratorId;
    clear_current_user_cache();

    $valid = [
        'role' => 'staff',
        'full_name' => 'Adverse Test Staff',
        'email' => 'adverse-staff@example.test',
        'password' => 'Disposable123',
        'phone' => '09175550002',
        'address' => 'Disposable test address',
    ];
    $invalidCases = [
        ['role' => 'administrator'],
        ['full_name' => str_repeat('A', 151)],
        ['email' => 'not-an-email'],
        ['email' => str_repeat('a', 151) . '@example.test'],
        ['password' => 'weak'],
        ['phone' => '123'],
        ['address' => str_repeat('A', 1001)],
    ];
    foreach ($invalidCases as $changes) {
        try {
            create_managed_user(array_replace($valid, $changes));
            throw new RuntimeException('A malformed managed-user request was accepted.');
        } catch (InvalidArgumentException) {
            // Expected: the handler rejects the request before a database write.
        }
    }
    adverse_assert((int) db_find('SELECT COUNT(*) AS total FROM users')['total'] === 1, 'An invalid user request wrote an account.');

    $createdId = create_managed_user(array_replace($valid, ['status' => 'inactive']));
    adverse_assert((string) db_find('SELECT status FROM users WHERE id = ?', 'i', [$createdId])['status'] === 'active', 'Forged create status was accepted.');
    adverse_assert(!isset($_SESSION['promo_code']), 'Promo state was unexpectedly set.');
    try {
        promo_code_preview('bad promo code!', 100.0);
        throw new RuntimeException('Malformed promo code was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }

    putenv('ADVERSE_USER_ID=' . $createdId);
    $notificationResults = adverse_workers('notification', 8, $barrier);
    adverse_assert(count(array_filter($notificationResults, static fn (array $result): bool => $result['ok'] === true)) === 8, 'Concurrent notification workers failed.');
    $notificationCount = (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE event_key = ?', 's', ['adverse-shared-event'])['total'];
    adverse_assert($notificationCount === 1, 'Concurrent event delivery created duplicate notifications.');

    putenv('ADVERSE_PHONE=09175550003');
    $userResults = adverse_workers('user', 8, $barrier);
    $phoneCount = (int) db_find('SELECT COUNT(*) AS total FROM users WHERE phone = ?', 's', ['09175550003'])['total'];
    echo 'Invalid managed-user inputs rejected: ' . count($invalidCases) . "; forged status ignored.\n";
    echo "Concurrent notifications: 8 requests, {$notificationCount} row.\n";
    echo 'Concurrent same-phone accounts: ' . count(array_filter($userResults, static fn (array $result): bool => $result['ok'] === true)) . ' succeeded, ' . $phoneCount . " rows.\n";
    adverse_assert($phoneCount === 1, 'Concurrent account creation bypassed unique phone validation.');
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    if (is_file($barrier)) {
        unlink($barrier);
    }
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    exit(1);
}
