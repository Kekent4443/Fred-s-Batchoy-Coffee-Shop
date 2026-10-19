<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function http_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function http_test_request(
    string $url,
    ?string $cookie = null,
    int $receiveLimit = 0,
    int $abortAfter = 0,
    ?array $post = null,
    array $headers = []
): array
{
    $body = '';
    $location = '';
    $newCookie = '';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIE => $cookie ?? '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location, &$newCookie): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
            if (stripos($header, 'Set-Cookie:') === 0) {
                $pair = trim(explode(';', trim(substr($header, 11)), 2)[0]);
                if (str_starts_with($pair, APP_SESSION_NAME . '=')) {
                    $newCookie = $pair;
                }
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $abortAfter): int {
            if ($abortAfter > 0 && strlen($body) + strlen($chunk) > $abortAfter) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($receiveLimit > 0 && defined('CURLOPT_MAX_RECV_SPEED_LARGE')) {
        curl_setopt($handle, CURLOPT_MAX_RECV_SPEED_LARGE, $receiveLimit);
    }
    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $started = hrtime(true);
    curl_exec($handle);
    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'seconds' => (hrtime(true) - $started) / 1_000_000_000,
        'error' => curl_errno($handle),
        'body' => $body,
        'location' => $location,
        'cookie' => $newCookie,
        'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
    ];
    curl_close($handle);
    return $result;
}

function http_test_load(string $baseUrl, int $batches = 12, int $concurrency = 8): array
{
    $durations = [];
    $errors = 0;
    $started = hrtime(true);
    for ($batch = 0; $batch < $batches; $batch++) {
        $multi = curl_multi_init();
        $handles = [];
        for ($index = 0; $index < $concurrency; $index++) {
            $path = ($batch + $index) % 2 === 0 ? '/public/index.php' : '/assets/js/app.js';
            $handle = curl_init($baseUrl . $path);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            $result = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.5);
            }
        } while ($result === CURLM_OK && $running > 0);
        foreach ($handles as $handle) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $durations[] = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
            if (curl_errno($handle) !== 0 || $status !== 200) {
                $errors++;
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        http_test_assert((hrtime(true) - $started) / 1_000_000_000 < 45, 'Bounded HTTP load exceeded the safety time limit.');
    }
    sort($durations);
    $total = count($durations);
    $seconds = (hrtime(true) - $started) / 1_000_000_000;
    return [
        'requests' => $total,
        'concurrency' => $concurrency,
        'errors' => $errors,
        'seconds' => $seconds,
        'rps' => $total / max($seconds, 0.001),
        'p50_ms' => 1000 * $durations[(int) floor(($total - 1) * 0.50)],
        'p95_ms' => 1000 * $durations[(int) floor(($total - 1) * 0.95)],
    ];
}

function http_test_import_schema(mysqli $database, string $sql): void
{
    http_test_assert($database->multi_query($sql), 'Disposable schema import failed.');
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    http_test_assert($database->errno === 0, 'Disposable schema import failed.');
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
http_test_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_http_test_' . bin2hex(random_bytes(5));
http_test_assert(preg_match('/^freds_http_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe disposable database name.');
$sessionDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR
    . '.freds-http-sessions-' . bin2hex(random_bytes(8));
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freds-http-server-' . bin2hex(random_bytes(8)) . '.log';
http_test_assert(mkdir($sessionDirectory, 0700), 'Could not create a disposable session directory.');

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
http_test_assert(is_resource($socket), 'Could not reserve a local test port.');
$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);
$baseUrl = 'http://127.0.0.1:' . $port;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server = null;
$failure = null;

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    putenv('APP_DEBUG=0');
    putenv('APP_FORCE_HTTPS=0');
    putenv('APP_URL=' . $baseUrl . '/public');
    putenv('APP_SESSION_NAME=FREDSADVERSEHTTP');
    // Prevent the isolated HTTP process from using developer SMTP credentials.
    putenv('SMTP_FROM_EMAIL=disabled-test-address');
    session_save_path($sessionDirectory);
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    http_test_import_schema(get_db(), explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    $roles = ['administrator', 'manager', 'staff', 'rider', 'customer'];
    $cookies = [];
    session_write_close();
    foreach ($roles as $index => $role) {
        db_execute(
            'INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, ?, "active")',
            'ssssss',
            ['HTTP Test ' . $role, 'http-' . $role . '@example.test', password_hash('Disposable123', PASSWORD_DEFAULT), '0917666000' . $index, 'Disposable test address', $role]
        );
    }
    $httpCustomerId = (int) db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['http-customer@example.test'])['id'];
    db_execute('UPDATE users SET email_verified_at = NOW() WHERE id = ?', 'i', [$httpCustomerId]);
    db_execute(
        'INSERT INTO registration_verifications
            (user_id, email, code_hash, attempt_count, expires_at, last_sent_at, verified_at, created_at, updated_at)
         VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW(), NOW(), NOW(), NOW())',
        'iss',
        [$httpCustomerId, 'http-customer@example.test', password_hash('123456', PASSWORD_DEFAULT)]
    );
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status)
         VALUES (?, ?, ?, ?, ?, "customer", "active")',
        'sssss',
        ['HTTP Unverified Customer', 'http-unverified-customer@example.test', password_hash('Disposable123', PASSWORD_DEFAULT), '09176660999', 'Disposable test address']
    );
    $httpUnverifiedCustomerId = db_last_id();
    db_execute(
        'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, contact_number, status)
         VALUES (?, ?, ?, ?, "active")',
        'ssss',
        ['HTTP Manager Shop', 'HTTP manager address', 'https://maps.google.com/', '09175550001']
    );
    $managerShopId = db_last_id();
    db_execute(
        'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, contact_number, status)
         VALUES (?, ?, ?, ?, "active")',
        'ssss',
        ['HTTP Other Shop', 'HTTP other address', 'https://maps.google.com/', '09175550002']
    );
    $otherShopId = db_last_id();
    $httpManagerId = (int) db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['http-manager@example.test'])['id'];
    db_execute('UPDATE users SET assigned_shop_id = ? WHERE id = ?', 'ii', [$managerShopId, $httpManagerId]);
    db_execute(
        'INSERT INTO categories (name, description, status) VALUES (?, ?, "active")',
        'ss',
        ['HTTP Product Category', 'Disposable HTTP product category']
    );
    $httpCategoryId = db_last_id();
    db_execute(
        'INSERT INTO products (category_id, shop_id, name, description, price, is_available, status, stock_quantity, image_url)
         VALUES (?, ?, ?, "", 40, 1, "active", 15, ?)',
        'iiss',
        [$httpCategoryId, $managerShopId, 'HTTP Manager Product', 'uploads/products/http-manager-product.png']
    );
    $httpManagerProductId = db_last_id();
    db_execute(
        'INSERT INTO products (category_id, shop_id, name, description, price, is_available, status, stock_quantity, image_url)
         VALUES (?, ?, ?, "", 30, 1, "active", 12, ?)',
        'iiss',
        [$httpCategoryId, $managerShopId, 'HTTP Manager Delete Product', 'uploads/products/http-manager-delete.png']
    );
    $httpManagerDeleteProductId = db_last_id();
    db_execute(
        'INSERT INTO products (category_id, shop_id, name, description, price, is_available, status, stock_quantity, image_url)
         VALUES (?, ?, ?, "", 50, 1, "active", 20, ?)',
        'iiss',
        [$httpCategoryId, $otherShopId, 'HTTP Other Shop Product', 'uploads/products/http-other-product.png']
    );
    $httpOtherProductId = db_last_id();

    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessionDirectory, '-S', '127.0.0.1:' . $port, '-t', APP_ROOT],
        [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        APP_ROOT,
        getenv()
    );
    http_test_assert(is_resource($server), 'Could not start a disposable local HTTP server.');
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = http_test_request($baseUrl . '/public/index.php');
        if ($probe['status'] === 200) {
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    http_test_assert($ready, 'Disposable HTTP server did not become ready.');

    foreach ($roles as $role) {
        $loginPage = http_test_request($baseUrl . '/public/auth/login.php');
        http_test_assert($loginPage['status'] === 200 && $loginPage['cookie'] !== '', 'Could not establish a local test login session.');
        http_test_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $loginPage['body'], $matches) === 1, 'Test login form omitted its CSRF token.');
        $sessionId = explode('=', $loginPage['cookie'], 2)[1] ?? '';
        $sessionPersisted = $sessionId !== '' && is_file($sessionDirectory . DIRECTORY_SEPARATOR . 'sess_' . $sessionId);
        $login = http_test_request($baseUrl . '/public/auth/login.php', $loginPage['cookie'], 0, 0, [
            'csrf_token' => $matches[1],
            'email' => 'http-' . $role . '@example.test',
            'password' => 'Disposable123',
        ]);
        http_test_assert(
            $login['status'] === 302,
            'A disposable ' . $role . ' login failed (HTTP ' . $login['status'] . '; response: '
                . substr(trim(strip_tags($login['body'])), 0, 120) . '; session persisted: '
                . ($sessionPersisted ? 'yes' : 'no') . ').'
        );
        $cookies[$role] = $login['cookie'] !== '' ? $login['cookie'] : $loginPage['cookie'];
    }

    $ownRoutes = [
        'administrator' => '/public/admin/reports.php',
        'manager' => '/public/manager/reports.php',
        'staff' => '/public/staff/products.php',
        'rider' => '/public/rider/dashboard.php',
        'customer' => '/public/customer/catalog.php',
    ];
    foreach ($ownRoutes as $role => $path) {
        $response = http_test_request($baseUrl . $path, $cookies[$role]);
        http_test_assert(
            $response['status'] === 200 && !str_contains($response['body'], 'Temporarily unavailable'),
            'An authorized ' . $role . ' route failed (HTTP ' . $response['status'] . ', service error ' . (str_contains($response['body'], 'Temporarily unavailable') ? 'yes' : 'no') . ', redirect ' . parse_url($response['location'], PHP_URL_PATH) . ').'
        );
        $denied = http_test_request($baseUrl . '/public/admin/reports.php?role=administrator', $cookies[$role]);
        http_test_assert($role === 'administrator' ? $denied['status'] === 200 : $denied['status'] === 302, 'Role parameter bypassed the Admin route guard.');
    }
    $adminUsersPage = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator']);
    $customerResetPattern = '/<form(?:(?!<\/form>).)*data-confirm-message="Send a password reset link to this customer&#0*39;s verified email address\?"(?:(?!<\/form>).)*name="action" value="reset_password"(?:(?!<\/form>).)*name="user_id" value="'
        . $httpCustomerId . '"/s';
    $unverifiedResetPattern = '/<form(?:(?!<\/form>).)*user-action-item--reset-password(?:(?!<\/form>).)*name="user_id" value="'
        . $httpUnverifiedCustomerId . '"/s';
    $unverifiedCustomerRowPattern = '/<tr[^>]*>(?:(?!<\/tr>).)*http-unverified-customer@example\.test(?:(?!<\/tr>).)*<\/tr>/s';
    preg_match($unverifiedCustomerRowPattern, $adminUsersPage['body'], $unverifiedCustomerRowMatch);
    http_test_assert(
        $adminUsersPage['status'] === 200
            && preg_match($customerResetPattern, $adminUsersPage['body']) === 1
            && preg_match($unverifiedResetPattern, $adminUsersPage['body']) === 0
            && !empty($unverifiedCustomerRowMatch[0])
            && !str_contains($unverifiedCustomerRowMatch[0], 'btn-reset-password-icon'),
        'Administrator User Management did not hide the reset-link icon from the unverified customer.'
    );
    $productRoleRoutes = [
        'administrator' => '/public/admin/products.php',
        'manager' => '/public/manager/products.php',
    ];
    foreach ($productRoleRoutes as $allowedRole => $path) {
        $allowed = http_test_request($baseUrl . $path, $cookies[$allowedRole]);
        http_test_assert(
            $allowed['status'] === 200 && str_contains($allowed['body'], 'product-management'),
            ucfirst($allowedRole) . ' Product Management route did not render the shared view.'
        );
        foreach ($roles as $otherRole) {
            if ($otherRole === $allowedRole) {
                continue;
            }
            $denied = http_test_request($baseUrl . $path . '?role=' . rawurlencode($allowedRole), $cookies[$otherRole]);
            http_test_assert(
                $denied['status'] === 302,
                ucfirst($otherRole) . ' accessed the ' . $allowedRole . ' Product Management route directly.'
            );
        }
    }

    $managerProductsPage = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager']);
    http_test_assert(
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $managerProductsPage['body'], $managerCsrfMatch) === 1,
        'Manager Product Management page omitted its CSRF token.'
    );
    $managerCsrf = $managerCsrfMatch[1];

    $forgedCsrf = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => str_repeat('0', 64),
        'action' => 'toggle_product_status',
        'product_id' => (string) $httpManagerProductId,
        'status' => 'inactive',
    ]);
    http_test_assert($forgedCsrf['status'] === 403, 'Forged Product Management CSRF token was accepted.');
    http_test_assert(
        (string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$httpManagerProductId])['status'] === 'active',
        'Forged Product Management CSRF request changed product status.'
    );

    $invalidManagerEdit = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'save_product',
        'product_id' => (string) $httpManagerProductId,
        'category_id' => (string) $httpCategoryId,
        'name' => 'HTTP Invalid Product',
        'description' => '',
        'price' => 'not-a-price',
        'is_available' => '1',
        'stock_quantity' => '9',
        'status' => 'inactive',
    ]);
    http_test_assert(
        $invalidManagerEdit['status'] === 200 && str_contains($invalidManagerEdit['body'], 'Product price must be a valid non-negative amount.'),
        'Invalid Manager product edit did not return inline validation feedback.'
    );
    http_test_assert(
        (string) db_find('SELECT name FROM products WHERE id = ?', 'i', [$httpManagerProductId])['name'] === 'HTTP Manager Product',
        'Invalid Manager product edit changed the database.'
    );

    $validManagerEdit = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'save_product',
        'product_id' => (string) $httpManagerProductId,
        'category_id' => (string) $httpCategoryId,
        'name' => 'HTTP Manager Product Updated',
        'description' => 'Updated through the HTTP controller',
        'price' => '42.75',
        'is_available' => '0',
        'stock_quantity' => '9',
        'status' => 'inactive',
    ]);
    $managerEditedProduct = db_find('SELECT * FROM products WHERE id = ?', 'i', [$httpManagerProductId]);
    http_test_assert($validManagerEdit['status'] === 302, 'Valid Manager product edit did not redirect.');
    http_test_assert(
        ($managerEditedProduct['name'] ?? '') === 'HTTP Manager Product Updated'
        && (float) ($managerEditedProduct['price'] ?? 0) === 42.75
        && (int) ($managerEditedProduct['stock_quantity'] ?? -1) === 9
        && (int) ($managerEditedProduct['is_available'] ?? -1) === 0
        && ($managerEditedProduct['status'] ?? '') === 'active',
        'Valid Manager product edit did not persist correctly or accepted a forged edit-form status.'
    );

    $managerDeactivate = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'toggle_product_status',
        'product_id' => (string) $httpManagerProductId,
        'status' => 'inactive',
    ]);
    http_test_assert(
        $managerDeactivate['status'] === 302
        && str_contains($managerDeactivate['location'], 'manager/products.php#products-list')
        && (string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$httpManagerProductId])['status'] === 'inactive',
        'Manager deactivate action failed or redirected incorrectly.'
    );

    $managerCrossShopStatus = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'toggle_product_status',
        'product_id' => (string) $httpOtherProductId,
        'status' => 'inactive',
    ]);
    http_test_assert($managerCrossShopStatus['status'] === 302, 'Denied Manager cross-shop status action did not return safely.');
    http_test_assert(
        (string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$httpOtherProductId])['status'] === 'active',
        'Manager changed another shop product through the HTTP action.'
    );

    $managerCrossShopDelete = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'delete_product',
        'product_id' => (string) $httpOtherProductId,
    ]);
    http_test_assert($managerCrossShopDelete['status'] === 302, 'Denied Manager cross-shop delete did not return safely.');
    http_test_assert(db_find('SELECT id FROM products WHERE id = ?', 'i', [$httpOtherProductId]) !== null, 'Manager deleted another shop product through the HTTP action.');

    $managerDelete = http_test_request($baseUrl . '/public/manager/products.php', $cookies['manager'], 0, 0, [
        'csrf_token' => $managerCsrf,
        'action' => 'delete_product',
        'product_id' => (string) $httpManagerDeleteProductId,
    ]);
    http_test_assert(
        $managerDelete['status'] === 302
        && str_contains($managerDelete['location'], 'manager/products.php#products-list')
        && db_find('SELECT id FROM products WHERE id = ?', 'i', [$httpManagerDeleteProductId]) === null,
        'Manager delete action failed or redirected incorrectly.'
    );

    $adminProductsPage = http_test_request($baseUrl . '/public/admin/products.php', $cookies['administrator']);
    http_test_assert(
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $adminProductsPage['body'], $adminCsrfMatch) === 1,
        'Administrator Product Management page omitted its CSRF token.'
    );
    $adminCsrf = $adminCsrfMatch[1];
    $adminAvailability = http_test_request($baseUrl . '/public/admin/products.php', $cookies['administrator'], 0, 0, [
        'csrf_token' => $adminCsrf,
        'action' => 'update_availability',
        'product_id' => (string) $httpOtherProductId,
        'is_available' => '0',
    ]);
    http_test_assert(
        $adminAvailability['status'] === 302
        && str_contains($adminAvailability['location'], 'admin/products.php#products-list')
        && (int) db_find('SELECT is_available FROM products WHERE id = ?', 'i', [$httpOtherProductId])['is_available'] === 0,
        'Administrator availability backend action failed or redirected incorrectly.'
    );

    $adminReactivate = http_test_request($baseUrl . '/public/admin/products.php', $cookies['administrator'], 0, 0, [
        'csrf_token' => $adminCsrf,
        'action' => 'toggle_product_status',
        'product_id' => (string) $httpManagerProductId,
        'status' => 'active',
    ]);
    http_test_assert(
        $adminReactivate['status'] === 302
        && (string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$httpManagerProductId])['status'] === 'active',
        'Administrator product activation failed through the HTTP action.'
    );
    $unauthorized = http_test_request($baseUrl . '/public/notification-count.php');
    http_test_assert($unauthorized['status'] === 401, 'Anonymous notification API access was not denied.');
    $malformed = http_test_request($baseUrl . '/public/admin/reports.php?date_from=99%2F99%2F26&report_type=transactions', $cookies['administrator']);
    http_test_assert($malformed['status'] === 200 && str_contains($malformed['body'], 'Enter a valid start date'), 'Malformed report date did not yield safe feedback.');
    http_test_assert(!str_contains($malformed['body'], 'mysqli_') && !str_contains($malformed['body'], 'SQLSTATE'), 'A malformed request exposed database details.');

    $asset = http_test_request($baseUrl . '/assets/js/theme-init.js');
    http_test_assert($asset['status'] === 200 && strlen($asset['body']) > 100, 'Canonical script asset failed to load.');
    $slow = http_test_request($baseUrl . '/assets/js/theme-init.js', null, 1024);
    http_test_assert($slow['status'] === 200 && hash('sha256', $slow['body']) === hash('sha256', $asset['body']), 'Slow script transfer was incomplete.');
    $aborted = http_test_request($baseUrl . '/assets/js/app.js', null, 0, 2048);
    http_test_assert($aborted['error'] !== 0, 'The simulated interrupted transfer did not abort.');
    $retry = http_test_request($baseUrl . '/assets/js/app.js');
    http_test_assert($retry['status'] === 200 && strlen($retry['body']) > 2048, 'Asset retry failed after an interrupted transfer.');

    $load = http_test_load($baseUrl);
    http_test_assert($load['errors'] === 0, 'Bounded HTTP load produced request errors.');

    $httpAdminId = (int) db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['http-administrator@example.test'])['id'];
    $resetRowsBeforeDenials = (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'];
    foreach (['manager', 'staff', 'rider', 'customer'] as $deniedRole) {
        $rolePage = http_test_request($baseUrl . $ownRoutes[$deniedRole], $cookies[$deniedRole]);
        http_test_assert(
            preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $rolePage['body'], $roleCsrfMatch) === 1,
            ucfirst($deniedRole) . ' page omitted its CSRF token.'
        );
        $deniedReset = http_test_request($baseUrl . '/public/admin/users.php', $cookies[$deniedRole], 0, 0, [
            'csrf_token' => $roleCsrfMatch[1],
            'action' => 'reset_password',
            'user_id' => (string) $httpAdminId,
            'role' => 'administrator',
        ]);
        http_test_assert($deniedReset['status'] === 302, ucfirst($deniedRole) . ' was not denied by the Administrator user endpoint.');
    }
    http_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetRowsBeforeDenials,
        'A non-Administrator created an Administrator reset credential through the restricted endpoint.'
    );
    $missingAdminResetCsrf = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator'], 0, 0, [
        'action' => 'reset_password',
        'user_id' => (string) $httpCustomerId,
    ]);
    http_test_assert(
        $missingAdminResetCsrf['status'] === 403
            && (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetRowsBeforeDenials,
        'Administrator customer-reset endpoint accepted a request without CSRF protection.'
    );
    $blockedUnverifiedReset = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator'], 0, 0, [
        'csrf_token' => $adminCsrf,
        'action' => 'reset_password',
        'user_id' => (string) $httpUnverifiedCustomerId,
    ]);
    http_test_assert($blockedUnverifiedReset['status'] === 302, 'Unverified-customer reset did not return safely.');
    $blockedUnverifiedResult = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator']);
    http_test_assert(
        str_contains($blockedUnverifiedResult['body'], 'Password reset cannot be sent because this customer&#039;s email address is not verified.')
            && (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetRowsBeforeDenials
            && (int) db_find(
                'SELECT COUNT(*) AS total FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")'
            )['total'] === 0,
        'Unverified-customer reset was not blocked clearly before token creation and rate limiting.'
    );
    $unavailableAdminReset = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator'], 0, 0, [
        'csrf_token' => $adminCsrf,
        'action' => 'reset_password',
        'user_id' => (string) $httpManagerId,
    ]);
    http_test_assert($unavailableAdminReset['status'] === 302, 'Administrator reset handler did not return safely while email was unavailable.');
    $unavailableAdminResult = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator']);
    http_test_assert(
        str_contains($unavailableAdminResult['body'], 'transactional email is not configured')
        && (int) db_find('SELECT COUNT(*) AS total FROM password_resets')['total'] === $resetRowsBeforeDenials
        && (int) db_find(
            'SELECT COUNT(*) AS total FROM rate_limits WHERE scope IN ("admin_password_reset_actor", "admin_password_reset_target")'
        )['total'] === 0,
        'Unavailable Administrator reset created a credential, consumed its rate limit, or omitted actionable feedback.'
    );

    $missingCsrfReset = http_test_request($baseUrl . '/public/forgot-password.php', null, 0, 0, [
        'action' => 'request_code',
        'email' => 'http-administrator@example.test',
    ]);
    http_test_assert($missingCsrfReset['status'] === 403, 'Forgot Password accepted a request without CSRF protection.');

    $forgotBodies = [];
    foreach (['http-administrator@example.test', 'missing-http-account@example.test', "bad-address' OR 1=1 --"] as $identifier) {
        $forgotPage = http_test_request($baseUrl . '/public/forgot-password.php');
        http_test_assert(
            $forgotPage['status'] === 200
            && str_contains($forgotPage['body'], 'Codes expire after 5 minutes')
            && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $forgotPage['body'], $forgotCsrfMatch) === 1,
            'Forgot Password request form failed to render with the five-minute expiry.'
        );
        $forgotSubmit = http_test_request($baseUrl . '/public/forgot-password.php', $forgotPage['cookie'], 0, 0, [
            'csrf_token' => $forgotCsrfMatch[1],
            'action' => 'request_code',
            'email' => $identifier,
        ]);
        http_test_assert(
            $forgotSubmit['status'] === 302 && str_contains($forgotSubmit['location'], 'forgot-password.php'),
            'Forgot Password did not return a safe redirect.'
        );
        $forgotResult = http_test_request($baseUrl . '/public/forgot-password.php', $forgotPage['cookie']);
        http_test_assert(
            $forgotResult['status'] === 200
            && str_contains($forgotResult['body'], 'could not send a verification code')
            && !str_contains($forgotResult['body'], $identifier),
            'Forgot Password response disclosed whether the submitted account exists.'
        );
        $forgotBodies[] = preg_replace('/[a-f0-9]{64}|data-resend-ready-at="\d+"|Resend Code in \d+s/', '[dynamic]', $forgotResult['body']);
    }
    http_test_assert(
        count(array_unique($forgotBodies)) === 1,
        'Registered, unknown, and malformed Forgot Password responses were distinguishable.'
    );
    http_test_assert(
        (int) db_find(
            'SELECT COUNT(*) AS total FROM rate_limits WHERE scope = "password_reset_ip_hour"'
        )['total'] === 0,
        'Unavailable Forgot Password requests consumed the public recovery rate limit.'
    );

    $adminUser = db_find('SELECT id, email, full_name FROM users WHERE id = ?', 'i', [$httpAdminId]);
    $httpLinkReset = create_password_reset_record_for_user($adminUser, false, 'legacy_link');
    $rawHttpToken = (string) $httpLinkReset['token'];
    $linkEntry = http_test_request($baseUrl . '/public/reset-password.php?token=' . rawurlencode($rawHttpToken));
    http_test_assert(
        $linkEntry['status'] === 302
        && str_contains($linkEntry['location'], 'reset-password.php')
        && !str_contains($linkEntry['location'], 'token=')
        && $linkEntry['cookie'] !== '',
        'Valid reset link was not exchanged for a server-side session grant.'
    );
    $resetForm = http_test_request($baseUrl . '/public/reset-password.php', $linkEntry['cookie']);
    http_test_assert(
        $resetForm['status'] === 200
        && !str_contains($resetForm['body'], $rawHttpToken)
        && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $resetForm['body'], $resetCsrfMatch) === 1,
        'Verified reset form was missing, exposed its token, or omitted CSRF protection.'
    );
    $jsonHeaders = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
    $mismatchReset = http_test_request($baseUrl . '/public/reset-password.php', $linkEntry['cookie'], 0, 0, [
        'csrf_token' => $resetCsrfMatch[1],
        'password' => 'AdministratorNew123',
        'confirm_password' => 'AdministratorDifferent123',
    ], $jsonHeaders);
    $mismatchPayload = json_decode($mismatchReset['body'], true);
    http_test_assert(
        $mismatchReset['status'] === 422
        && str_starts_with($mismatchReset['content_type'], 'application/json')
        && is_array($mismatchPayload)
        && ($mismatchPayload['success'] ?? true) === false
        && str_contains((string) ($mismatchPayload['message'] ?? ''), 'Passwords do not match'),
        'AJAX password confirmation mismatch did not return a usable JSON error.'
    );
    $weakReset = http_test_request($baseUrl . '/public/reset-password.php', $linkEntry['cookie'], 0, 0, [
        'csrf_token' => $resetCsrfMatch[1],
        'password' => 'allletters',
        'confirm_password' => 'allletters',
    ], $jsonHeaders);
    $weakPayload = json_decode($weakReset['body'], true);
    http_test_assert(
        $weakReset['status'] === 422
        && is_array($weakPayload)
        && ($weakPayload['success'] ?? true) === false
        && str_contains((string) ($weakPayload['message'] ?? ''), 'at least one letter and one number'),
        'Weak Administrator password did not return a safe AJAX validation error.'
    );
    $validReset = http_test_request($baseUrl . '/public/reset-password.php', $linkEntry['cookie'], 0, 0, [
        'csrf_token' => $resetCsrfMatch[1],
        'password' => 'AdministratorNew123',
        'confirm_password' => 'AdministratorNew123',
    ], $jsonHeaders);
    $validResetPayload = json_decode($validReset['body'], true);
    http_test_assert(
        $validReset['status'] === 200
        && str_starts_with($validReset['content_type'], 'application/json')
        && is_array($validResetPayload)
        && ($validResetPayload['success'] ?? false) === true
        && str_contains((string) ($validResetPayload['redirect'] ?? ''), 'auth/login.php')
        && password_verify('AdministratorNew123', (string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$httpAdminId])['password']),
        'Valid Administrator AJAX reset did not persist a secure hash or return the login redirect.'
    );
    $staleAdminSession = http_test_request($baseUrl . '/public/admin/users.php', $cookies['administrator']);
    http_test_assert(
        $staleAdminSession['status'] === 302 && str_contains($staleAdminSession['location'], 'auth/login.php'),
        'Existing Administrator session survived the password reset.'
    );
    $replayedLink = http_test_request($baseUrl . '/public/reset-password.php?token=' . rawurlencode($rawHttpToken));
    http_test_assert(
        $replayedLink['status'] === 302 && str_contains($replayedLink['location'], 'forgot-password.php'),
        'Used Administrator reset link was accepted again.'
    );
    $modifiedLink = http_test_request($baseUrl . '/public/reset-password.php?token=' . str_repeat('0', 64));
    http_test_assert(
        $modifiedLink['status'] === 302 && str_contains($modifiedLink['location'], 'forgot-password.php'),
        'Modified Administrator reset link was accepted.'
    );
    $expiredHttpReset = create_password_reset_record_for_user($adminUser, false, 'legacy_link');
    db_execute(
        'UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?',
        'i',
        [(int) $expiredHttpReset['reset_id']]
    );
    $expiredLinkResponse = http_test_request(
        $baseUrl . '/public/reset-password.php?token=' . rawurlencode((string) $expiredHttpReset['token'])
    );
    http_test_assert(
        $expiredLinkResponse['status'] === 302 && str_contains($expiredLinkResponse['location'], 'forgot-password.php'),
        'Expired Administrator reset link was accepted.'
    );

    $oldLoginPage = http_test_request($baseUrl . '/public/auth/login.php');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $oldLoginPage['body'], $newLoginCsrfMatch);
    $oldLogin = http_test_request($baseUrl . '/public/auth/login.php', $oldLoginPage['cookie'], 0, 0, [
        'csrf_token' => $newLoginCsrfMatch[1],
        'email' => 'http-administrator@example.test',
        'password' => 'Disposable123',
    ]);
    http_test_assert($oldLogin['status'] === 200, 'Old Administrator password remained valid after reset.');
    $newLogin = http_test_request($baseUrl . '/public/auth/login.php', $oldLoginPage['cookie'], 0, 0, [
        'csrf_token' => $newLoginCsrfMatch[1],
        'email' => 'http-administrator@example.test',
        'password' => 'AdministratorNew123',
    ]);
    http_test_assert($newLogin['status'] === 302, 'Administrator could not log in using the new password.');

    echo 'Authorized role pages: 5/5; Product Management direct-role denials: 8/8; Product Management HTTP mutations: 9/9; forged Admin role denied: 4/4; anonymous notification API denied. ';
    echo "Malformed report input returned safe feedback.\n";
    echo "Administrator recovery HTTP flow, enumeration resistance, CSRF, role denials, reset validation, session revocation, replay, expiry, and new-password login passed.\n";
    echo 'Slow asset: ' . round($slow['seconds'], 2) . "s; aborted transfer recovered on retry.\n";
    echo 'HTTP load: ' . $load['requests'] . ' GETs, concurrency ' . $load['concurrency'] . ', errors ' . $load['errors']
        . ', ' . round($load['rps'], 1) . ' req/s, p50 ' . round($load['p50_ms'], 1)
        . ' ms, p95 ' . round($load['p95_ms'], 1) . " ms.\n";
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
    foreach (scandir($sessionDirectory) ?: [] as $name) {
        if ($name !== '.' && $name !== '..' && preg_match('/^sess_[a-zA-Z0-9,-]+$/', $name)) {
            unlink($sessionDirectory . DIRECTORY_SEPARATOR . $name);
        }
    }
    rmdir($sessionDirectory);
    if (is_file($serverLog)) {
        unlink($serverLog);
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    exit(1);
}
