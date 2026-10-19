<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function manager_products_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function manager_products_test_rejects(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        manager_products_test_assert(
            $exception->getMessage() === $expected,
            'Unexpected rejection: ' . $exception->getMessage()
        );
        return;
    }

    throw new RuntimeException('Expected operation was accepted: ' . $expected);
}

function manager_products_test_import(mysqli $database, string $sql): void
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
}

$host = strtolower(database_setting('DB_HOST', 'host', '127.0.0.1'));
manager_products_test_assert(in_array($host, ['localhost', '127.0.0.1', '::1'], true), 'Tests require local MySQL.');
$databaseName = 'freds_manager_products_' . bin2hex(random_bytes(5));
manager_products_test_assert((bool) preg_match('/^freds_manager_products_[a-f0-9]{10}$/', $databaseName), 'Unsafe test database name.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    require_once dirname(__DIR__) . '/app/migrations/runner.php';

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $schema = explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    manager_products_test_import(get_db(), $schema);

    db_execute(
        'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, contact_number, status)
         VALUES (?, ?, ?, ?, "active")',
        'ssss',
        ['Assigned Shop', 'Assigned address', 'https://maps.google.com/', '09170000001']
    );
    $assignedShopId = db_last_id();
    db_execute(
        'INSERT INTO categories (name, description, status) VALUES (?, ?, "active")',
        'ss',
        ['Manager Product Category', 'Disposable category']
    );
    $categoryId = db_last_id();

    // Simulate an existing installation, then apply the incremental migration.
    db_execute('ALTER TABLE products DROP FOREIGN KEY fk_products_shop');
    db_execute('DROP INDEX idx_products_shop_catalog ON products');
    db_execute('ALTER TABLE products DROP COLUMN shop_id');
    db_execute(
        'INSERT INTO products (category_id, name, description, price, is_available, status, stock_quantity, image_url)
         VALUES (?, ?, "", 60.00, 1, "active", 8, ?)',
        'iss',
        [$categoryId, 'Legacy Product', 'uploads/products/legacy.png']
    );
    $legacyProductId = db_last_id();
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_10_05_manager_product_shop_scope.sql');
    migration_execute_sql(get_db(), $migration);
    manager_products_test_assert(
        (int) db_find('SELECT shop_id FROM products WHERE id = ?', 'i', [$legacyProductId])['shop_id'] === $assignedShopId,
        'Migration did not preserve and assign the legacy product.'
    );
    migration_execute_sql(get_db(), $migration);
    manager_products_test_assert(database_column_exists('products', 'shop_id'), 'Idempotent migration removed the shop column.');

    db_execute(
        'INSERT INTO shop_locations (shop_name, complete_address, google_maps_url, contact_number, status)
         VALUES (?, ?, ?, ?, "active")',
        'ssss',
        ['Other Shop', 'Other address', 'https://maps.google.com/', '09170000002']
    );
    $otherShopId = db_last_id();
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, assigned_shop_id)
         VALUES (?, ?, ?, ?, ?, "manager", "active", ?)',
        'sssssi',
        ['Assigned Manager', 'assigned-manager@example.test', password_hash('ManagerPassword123', PASSWORD_DEFAULT), '09170000003', 'Manager address', $assignedShopId]
    );
    $managerId = db_last_id();
    $viewer = ['id' => $managerId, 'role' => 'manager'];
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status)
         VALUES (?, ?, ?, ?, ?, "customer", "active")',
        'sssss',
        ['Product Test Customer', 'product-customer@example.test', password_hash('CustomerPassword123', PASSWORD_DEFAULT), '09170000004', 'Customer address']
    );
    $customerId = db_last_id();
    manager_products_test_rejects(
        static fn () => manager_product_shop(['id' => $customerId, 'role' => 'manager']),
        'Your Manager account is not authorized to manage products.'
    );
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status)
         VALUES (?, ?, ?, ?, ?, "administrator", "active")',
        'sssss',
        ['Product Test Admin', 'product-admin@example.test', password_hash('AdministratorPassword123', PASSWORD_DEFAULT), '09170000005', 'Admin address']
    );
    $administratorId = db_last_id();
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, assigned_shop_id)
         VALUES (?, ?, ?, ?, ?, "manager", "active", ?)',
        'sssssi',
        ['Other Shop Manager', 'other-shop-manager@example.test', password_hash('ManagerPassword123', PASSWORD_DEFAULT), '09170000006', 'Other manager address', $otherShopId]
    );
    $otherManagerId = db_last_id();
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status)
         VALUES (?, ?, ?, ?, ?, "manager", "active")',
        'sssss',
        ['Unassigned Manager', 'unassigned-manager@example.test', password_hash('ManagerPassword123', PASSWORD_DEFAULT), '09170000007', 'Unassigned manager address']
    );
    $unassignedManagerId = db_last_id();

    save_manager_product($viewer, [
        'category_id' => $categoryId,
        'name' => 'Manager Product',
        'description' => 'Initial description',
        'price' => '75.50',
        'is_available' => '1',
        'stock_quantity' => '6',
        'image_url' => 'uploads/products/manager-product.png',
        'status' => 'inactive',
    ]);
    $managerProduct = db_find('SELECT * FROM products WHERE name = ? LIMIT 1', 's', ['Manager Product']);
    $managerProductId = (int) ($managerProduct['id'] ?? 0);
    manager_products_test_assert($managerProductId > 0, 'Manager product was not created.');
    manager_products_test_assert((int) $managerProduct['shop_id'] === $assignedShopId, 'New product was not assigned to the Manager shop.');
    manager_products_test_assert(($managerProduct['status'] ?? '') === 'active', 'Forged create status bypassed the Active default.');
    manager_products_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = "Low Stock Alert"', 'i', [$managerId])['total'] === 1,
        'Assigned Manager did not receive the shared-threshold low-stock notification.'
    );
    manager_products_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = "Low Stock Alert"', 'i', [$otherManagerId])['total'] === 0,
        'A Manager received a low-stock notification for another shop.'
    );
    manager_products_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = "Low Stock Alert"', 'i', [$administratorId])['total'] === 1,
        'Administrator did not receive the low-stock notification.'
    );

    save_product([
        'shop_id' => $otherShopId,
        'category_id' => $categoryId,
        'name' => 'Other Shop Product',
        'description' => '',
        'price' => '90',
        'is_available' => '1',
        'stock_quantity' => '20',
        'image_url' => 'uploads/products/other-product.png',
        'status' => 'active',
    ]);
    $otherProductId = (int) db_find('SELECT id FROM products WHERE name = ? LIMIT 1', 's', ['Other Shop Product'])['id'];

    $scoped = fetch_products('', false, $assignedShopId);
    manager_products_test_assert(count(array_filter($scoped, static fn (array $row): bool => (int) $row['shop_id'] !== $assignedShopId)) === 0, 'Scoped product list leaked another shop.');
    manager_products_test_assert(!in_array($otherProductId, array_map(static fn (array $row): int => (int) $row['id'], $scoped), true), 'Other-shop product appeared in the Manager list.');

    save_manager_product($viewer, [
        'category_id' => $categoryId,
        'name' => 'Manager Product Updated',
        'description' => 'Updated',
        'price' => '80',
        'is_available' => '1',
        'stock_quantity' => '4',
        'image_url' => 'uploads/products/manager-product.png',
        'status' => 'inactive',
    ], $managerProductId);
    $updated = db_find('SELECT * FROM products WHERE id = ?', 'i', [$managerProductId]);
    manager_products_test_assert(($updated['name'] ?? '') === 'Manager Product Updated', 'Manager could not update own product information.');
    manager_products_test_assert(($updated['status'] ?? '') === 'active', 'Manager forged an Administrator-controlled status change.');
    manager_products_test_assert((int) $updated['stock_quantity'] === 4, 'Manager stock update was not saved.');

    update_manager_product_availability($viewer, $managerProductId, 0);
    manager_products_test_assert((int) db_find('SELECT is_available FROM products WHERE id = ?', 'i', [$managerProductId])['is_available'] === 0, 'Manager availability update was not saved.');
    manager_products_test_rejects(
        static fn () => update_manager_product_availability($viewer, $otherProductId, 0),
        'Product does not belong to your assigned shop.'
    );
    manager_products_test_rejects(
        static fn () => save_manager_product($viewer, [
            'category_id' => $categoryId, 'name' => 'Forged Cross-Shop Edit', 'description' => '',
            'price' => '1', 'is_available' => '1', 'stock_quantity' => '1',
            'image_url' => 'uploads/products/other-product.png', 'status' => 'active',
        ], $otherProductId),
        'Product does not belong to your assigned shop.'
    );
    manager_products_test_assert((string) db_find('SELECT name FROM products WHERE id = ?', 'i', [$otherProductId])['name'] === 'Other Shop Product', 'Denied cross-shop edit changed data.');
    manager_products_test_rejects(
        static fn () => update_product_status_as_admin($viewer, $managerProductId, 'inactive'),
        'Only the Administrator can perform this product action.'
    );
    manager_products_test_rejects(
        static fn () => delete_product_as_admin($viewer, $managerProductId),
        'Only the Administrator can perform this product action.'
    );
    update_manager_product_status($viewer, $managerProductId, 'inactive');
    manager_products_test_assert((string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$managerProductId])['status'] === 'inactive', 'Manager could not deactivate an assigned-shop product.');
    update_manager_product_status($viewer, $managerProductId, 'active');
    manager_products_test_assert((string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$managerProductId])['status'] === 'active', 'Manager could not reactivate an assigned-shop product.');
    update_category($categoryId, 'Manager Product Category', 'Temporarily inactive for activation validation', 'inactive');
    update_manager_product_status($viewer, $managerProductId, 'inactive');
    manager_products_test_rejects(
        static fn () => update_manager_product_status($viewer, $managerProductId, 'active'),
        'Activate the selected category before activating this product.'
    );
    manager_products_test_assert((string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$managerProductId])['status'] === 'inactive', 'Rejected Manager activation changed product status.');
    update_category($categoryId, 'Manager Product Category', 'Restored after activation validation', 'active');
    update_manager_product_status($viewer, $managerProductId, 'active');
    manager_products_test_rejects(
        static fn () => update_manager_product_status($viewer, $otherProductId, 'inactive'),
        'Product does not belong to your assigned shop.'
    );
    manager_products_test_rejects(
        static fn () => delete_manager_product($viewer, 0),
        'Please select a valid product.'
    );
    manager_products_test_rejects(
        static fn () => delete_manager_product($viewer, $otherProductId),
        'Product does not belong to your assigned shop.'
    );
    save_manager_product($viewer, [
        'category_id' => $categoryId,
        'name' => 'Manager Product To Delete',
        'description' => '',
        'price' => '25',
        'is_available' => '1',
        'stock_quantity' => '2',
        'image_url' => 'uploads/products/manager-delete-test.png',
    ]);
    $managerDeleteProductId = (int) db_find('SELECT id FROM products WHERE name = ? LIMIT 1', 's', ['Manager Product To Delete'])['id'];
    delete_manager_product($viewer, $managerDeleteProductId);
    manager_products_test_assert(db_find('SELECT id FROM products WHERE id = ?', 'i', [$managerDeleteProductId]) === null, 'Manager could not delete an assigned-shop product.');
    $administratorViewer = ['id' => $administratorId, 'role' => 'administrator'];
    update_product_availability($administratorViewer, $otherProductId, 0);
    manager_products_test_assert((int) db_find('SELECT is_available FROM products WHERE id = ?', 'i', [$otherProductId])['is_available'] === 0, 'Administrator availability action was lost.');
    update_product_status_as_admin($administratorViewer, $otherProductId, 'inactive');
    manager_products_test_assert((string) db_find('SELECT status FROM products WHERE id = ?', 'i', [$otherProductId])['status'] === 'inactive', 'Administrator status action was lost.');
    update_product_status_as_admin($administratorViewer, $otherProductId, 'active');
    save_product([
        'shop_id' => $otherShopId,
        'category_id' => $categoryId,
        'name' => 'Other Shop Product',
        'description' => 'Administrator update',
        'price' => '95.25',
        'is_available' => '1',
        'stock_quantity' => '19',
        'image_url' => 'uploads/products/other-product.png',
        'status' => 'active',
    ], $otherProductId);
    $adminUpdatedProduct = db_find('SELECT * FROM products WHERE id = ?', 'i', [$otherProductId]);
    manager_products_test_assert(
        (float) $adminUpdatedProduct['price'] === 95.25 && (int) $adminUpdatedProduct['stock_quantity'] === 19,
        'Administrator product update did not persist price and stock changes.'
    );

    manager_products_test_rejects(
        static fn () => update_manager_product_status($viewer, $managerProductId, 'archived'),
        'Please select a valid product status.'
    );
    manager_products_test_rejects(
        static fn () => update_manager_product_availability($viewer, $managerProductId, 2),
        'Please select a valid product availability.'
    );

    $invalidProductCases = [
        [['name' => ''], 'Product name is required.'],
        [['name' => str_repeat('N', 151)], 'Product name must be 150 characters or fewer.'],
        [['description' => str_repeat('D', 5001)], 'Product description must be 5,000 characters or fewer.'],
        [['price' => '-0.01'], 'Product price must be a valid non-negative amount.'],
        [['price' => '100000000'], 'Product price must be a valid non-negative amount.'],
        [['is_available' => '2'], 'Please select a valid product availability.'],
        [['stock_quantity' => '-1'], 'Stock quantity must be a whole number of zero or greater.'],
        [['stock_quantity' => '1.5'], 'Stock quantity must be a whole number of zero or greater.'],
        [['category_id' => '999999999'], 'Selected category does not exist.'],
    ];
    $validManagerPayload = [
        'category_id' => $categoryId,
        'name' => 'Validation Candidate',
        'description' => '',
        'price' => '25',
        'is_available' => '1',
        'stock_quantity' => '12',
        'image_url' => 'uploads/products/validation-candidate.png',
    ];
    foreach ($invalidProductCases as [$changes, $message]) {
        manager_products_test_rejects(
            static fn () => save_manager_product($viewer, array_replace($validManagerPayload, $changes)),
            $message
        );
    }
    manager_products_test_assert(
        db_find('SELECT id FROM products WHERE name = ? LIMIT 1', 's', ['Validation Candidate']) === null,
        'An invalid Manager product payload created a database row.'
    );
    manager_products_test_rejects(
        static fn () => save_uploaded_product_image([
            'name' => ['forged.png'],
            'tmp_name' => ['forged.tmp'],
            'error' => UPLOAD_ERR_OK,
            'size' => 10,
        ]),
        'Unable to upload the selected image. Please choose a valid image file.'
    );
    $_GET = ['search' => ['forged']];
    manager_products_test_assert(table_search_value() === '', 'Malformed product search input was not normalized safely.');
    $_GET = [];

    db_execute('INSERT INTO categories (name, description, status) VALUES (?, "", "inactive")', 's', ['Inactive Product Category']);
    $inactiveCategoryId = db_last_id();
    manager_products_test_rejects(
        static fn () => save_manager_product($viewer, array_replace($validManagerPayload, ['category_id' => $inactiveCategoryId])),
        'Activate the selected category before activating this product.'
    );

    save_product(array_replace($validManagerPayload, [
        'shop_id' => $assignedShopId,
        'name' => 'Administrator Delete Product',
        'status' => 'active',
    ]));
    $administratorDeleteProductId = (int) db_find('SELECT id FROM products WHERE name = ? LIMIT 1', 's', ['Administrator Delete Product'])['id'];
    delete_product_as_admin($administratorViewer, $administratorDeleteProductId);
    manager_products_test_assert(
        db_find('SELECT id FROM products WHERE id = ?', 'i', [$administratorDeleteProductId]) === null,
        'Administrator could not delete an unreferenced product.'
    );

    db_execute('INSERT INTO orders (reference_no, customer_id) VALUES (?, ?)', 'si', ['PRODUCT-LINK-TEST', $customerId]);
    $linkedOrderId = db_last_id();
    db_execute(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, 1, 80, 80)',
        'ii',
        [$linkedOrderId, $managerProductId]
    );
    manager_products_test_rejects(
        static fn () => delete_manager_product($viewer, $managerProductId),
        'This product is already linked to order records. Deactivate it instead to preserve transaction history.'
    );
    manager_products_test_rejects(
        static fn () => delete_product_as_admin($administratorViewer, $managerProductId),
        'This product is already linked to order records. Deactivate it instead to preserve transaction history.'
    );

    manager_products_test_rejects(
        static fn () => save_manager_product($viewer, [
            'category_id' => $categoryId, 'name' => 'Bad Price', 'description' => '',
            'price' => 'not-a-number', 'is_available' => '1', 'stock_quantity' => '1',
            'image_url' => 'uploads/products/bad.png',
        ]),
        'Product price must be a valid non-negative amount.'
    );
    manager_products_test_assert(db_find('SELECT id FROM products WHERE name = ? LIMIT 1', 's', ['Bad Price']) === null, 'Invalid product input created a row.');

    $_SESSION['user_id'] = $managerId;
    clear_current_user_cache();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    ob_start();
    include APP_ROOT . '/public/manager/products.php';
    $html = (string) ob_get_clean();
    manager_products_test_assert(str_contains($html, 'Showing products assigned to Assigned Shop.'), 'Manager product page did not render assigned-shop scope.');
    manager_products_test_assert(str_contains($html, 'Manager Product Updated'), 'Manager product page omitted an assigned product.');
    manager_products_test_assert(!str_contains($html, 'Other Shop Product'), 'Manager product page disclosed another shop product.');
    manager_products_test_assert(str_contains($html, 'Update Product Information'), 'Manager edit action is missing.');
    manager_products_test_assert(str_contains($html, 'Low Stock'), 'Stock monitoring UI is missing.');
    manager_products_test_assert(str_contains($html, '>Available</span>') && str_contains($html, '>Unavailable</span>'), 'Manager availability labels do not match the stored availability values.');

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[input[@name="action" and @value="add_product"]]');
    manager_products_test_assert($forms !== false && $forms->length === 1, 'Manager Add Product form is missing.');
    manager_products_test_assert($xpath->query('.//*[@name="status"]', $forms->item(0))->length === 0, 'Manager Add Product exposes a status selector.');

    $_GET = ['edit' => (string) $managerProductId];
    ob_start();
    include APP_ROOT . '/public/manager/products.php';
    $managerEditHtml = (string) ob_get_clean();
    $managerEditDocument = new DOMDocument();
    @$managerEditDocument->loadHTML($managerEditHtml);
    $managerEditXPath = new DOMXPath($managerEditDocument);
    $managerEditForms = $managerEditXPath->query('//form[input[@name="action" and @value="save_product"] and input[@name="product_id" and @value="' . $managerProductId . '"]]');
    manager_products_test_assert($managerEditForms !== false && $managerEditForms->length === 1, 'Manager Edit Product form is missing.');
    manager_products_test_assert($managerEditXPath->query('.//select[@name="status"]', $managerEditForms->item(0))->length === 0, 'Manager Edit Product exposes the Administrator status field.');

    $_SESSION['user_id'] = $administratorId;
    clear_current_user_cache();
    $_GET = [];
    ob_start();
    include APP_ROOT . '/public/admin/products.php';
    $adminHtml = (string) ob_get_clean();
    $adminDocument = new DOMDocument();
    @$adminDocument->loadHTML($adminHtml);
    $adminXPath = new DOMXPath($adminDocument);
    $managerHeaders = [];
    foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " product-management__table ")]/thead/tr/th') as $header) {
        $managerHeaders[] = trim((string) $header->textContent);
    }
    $adminHeaders = [];
    foreach ($adminXPath->query('//table[contains(concat(" ", normalize-space(@class), " "), " product-management__table ")]/thead/tr/th') as $header) {
        $adminHeaders[] = trim((string) $header->textContent);
    }
    manager_products_test_assert($managerHeaders === $adminHeaders && count($adminHeaders) === 8, 'Admin and Manager product tables do not share the same structure.');
    manager_products_test_assert($xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " product-management ")]')->length === 1, 'Manager shared Product Management view is missing.');
    manager_products_test_assert($adminXPath->query('//section[contains(concat(" ", normalize-space(@class), " "), " product-management ")]')->length === 1, 'Administrator shared Product Management view is missing.');
    manager_products_test_assert($xpath->query('//form[input[@name="action" and @value="update_availability"]]')->length === 0, 'Manager Actions still expose the availability update controls.');
    manager_products_test_assert($adminXPath->query('//form[input[@name="action" and @value="update_availability"]]')->length === 0, 'Administrator Actions still expose the availability update controls.');
    manager_products_test_assert($xpath->query('//input[@name="action" and @value="toggle_product_status"]')->length > 0, 'Manager deactivate action is missing.');
    manager_products_test_assert($xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " btn-deactivate-icon ")]')->length > 0, 'Manager deactivate action does not use the Administrator button design.');
    manager_products_test_assert($xpath->query('//input[@name="action" and @value="delete_product"]')->length > 0, 'Manager delete action is missing.');
    manager_products_test_assert($xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " btn-delete-icon ")]')->length > 0, 'Manager delete action does not use the Administrator button design.');
    manager_products_test_assert($adminXPath->query('//input[@name="action" and @value="delete_product"]')->length > 0, 'Administrator delete action was lost.');
    manager_products_test_assert($adminXPath->query('//input[@name="action" and @value="toggle_product_status"]')->length > 0, 'Administrator status action was lost.');
    manager_products_test_assert(str_contains($adminHtml, 'Other Shop Product'), 'Administrator global product visibility was narrowed.');
    manager_products_test_assert(str_contains($adminHtml, 'data-export-table="products"'), 'Administrator export action was lost.');
    manager_products_test_assert(!str_contains($html, 'data-export-table="products"'), 'Manager received the Administrator export action.');
    foreach ($xpath->query('//form[input[@name="action"]]') as $actionForm) {
        manager_products_test_assert($xpath->query('.//input[@name="csrf_token"]', $actionForm)->length === 1, 'Manager product action form is missing CSRF protection.');
    }
    foreach ($adminXPath->query('//form[input[@name="action"]]') as $actionForm) {
        manager_products_test_assert($adminXPath->query('.//input[@name="csrf_token"]', $actionForm)->length === 1, 'Administrator product action form is missing CSRF protection.');
    }

    $adminWrapper = (string) file_get_contents(APP_ROOT . '/public/admin/products.php');
    $managerWrapper = (string) file_get_contents(APP_ROOT . '/public/manager/products.php');
    foreach ([$adminWrapper, $managerWrapper] as $wrapper) {
        manager_products_test_assert(str_contains($wrapper, '/app/controllers/products.php'), 'Product route does not load the shared controller.');
        manager_products_test_assert(str_contains($wrapper, '/app/views/products/page.php'), 'Product route does not load the shared view.');
    }
    manager_products_test_assert(str_contains($adminWrapper, "require_login('administrator')"), 'Administrator route lost its server-side role guard.');
    manager_products_test_assert(str_contains($managerWrapper, "require_login('manager')"), 'Manager route lost its server-side role guard.');

    $_SESSION['user_id'] = $unassignedManagerId;
    clear_current_user_cache();
    $_GET = ['edit' => (string) $otherProductId];
    ob_start();
    include APP_ROOT . '/public/manager/products.php';
    $unassignedManagerHtml = (string) ob_get_clean();
    $unassignedDocument = new DOMDocument();
    @$unassignedDocument->loadHTML($unassignedManagerHtml);
    $unassignedXPath = new DOMXPath($unassignedDocument);
    manager_products_test_assert(str_contains($unassignedManagerHtml, 'must be assigned to a shop'), 'Unassigned Manager did not receive the shop-assignment error.');
    manager_products_test_assert(!str_contains($unassignedManagerHtml, 'Other Shop Product'), 'Unassigned Manager disclosed product data through the edit route.');
    manager_products_test_assert($unassignedXPath->query('//a[contains(@href, "edit=new")]')->length === 0, 'Unassigned Manager received a broken Add Product link.');
    manager_products_test_assert($unassignedXPath->query('//form[contains(concat(" ", normalize-space(@class), " "), " product-management__form ")]//button[@type="submit" and @disabled]')->length === 1, 'Unassigned Manager product form was not disabled.');

    $artifactDirectory = trim((string) getenv('PRODUCT_UI_ARTIFACT_DIR'));
    if ($artifactDirectory !== '') {
        if (!is_dir($artifactDirectory) && !mkdir($artifactDirectory, 0700, true) && !is_dir($artifactDirectory)) {
            throw new RuntimeException('Unable to create Product Management UI artifact directory.');
        }
        $assetBase = 'file:///' . str_replace('\\', '/', APP_ROOT) . '/assets/';
        $artifactManagerHtml = preg_replace('~https?://localhostscripts/\.\./assets/~', $assetBase, $html) ?? $html;
        $artifactAdminHtml = preg_replace('~https?://localhostscripts/\.\./assets/~', $assetBase, $adminHtml) ?? $adminHtml;
        if (!str_contains($artifactAdminHtml, '<link rel="stylesheet"')) {
            $artifactAdminHtml = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<link rel="stylesheet" href="' . $assetBase . 'css/style.css">'
                . '<link rel="stylesheet" href="' . $assetBase . 'css/notifications.css">'
                . '<link rel="stylesheet" href="' . $assetBase . 'css/system-modal.css"></head><body>'
                . $artifactAdminHtml . '</body></html>';
        }
        file_put_contents($artifactDirectory . '/manager-products.html', $artifactManagerHtml);
        file_put_contents($artifactDirectory . '/admin-products.html', $artifactAdminHtml);
    }

    echo "Shared Admin/Manager Product Management layout, permissions, migration, assigned-shop isolation, add/edit, availability, stock UI, forged status, and invalid-input tests passed.\n";
} finally {
    $admin->query('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    $admin->close();
}
