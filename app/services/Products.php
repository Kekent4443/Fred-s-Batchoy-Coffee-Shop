<?php

declare(strict_types=1);

function fetch_categories(string $search = '', ?string $status = null): array
{
    $sql = 'SELECT categories.*' . priority_select_sql('category') . ' FROM categories WHERE 1=1';
    $types = '';
    $params = [];

    if ($status !== null) {
        $sql .= ' AND status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($search !== '') {
        $sql .= ' AND (name LIKE ? OR description LIKE ?)';
        $types .= 'ss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like);
    }

    $sql .= ' ORDER BY ' . priority_order_sql('category', 'COALESCE(updated_at, created_at)') . ', name ASC';

    return db_select($sql, $types, $params);
}

function create_category(string $name, string $description): void
{
    $name = require_non_empty_string($name, 'Category name is required.');

    db_execute(
        'INSERT INTO categories (name, description, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
        'sss',
        [$name, trim($description), 'active']
    );

    log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'category_created', 'Added category: ' . $name);
}

function update_category(int $id, string $name, string $description, string $status): void
{
    $name = require_non_empty_string($name, 'Category name is required.');
    $status = require_allowed_value($status, ['active', 'inactive'], 'Please select a valid category status.');

    db_execute(
        'UPDATE categories SET name = ?, description = ?, status = ?, updated_at = NOW() WHERE id = ?',
        'sssi',
        [$name, trim($description), $status, $id]
    );

    log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'category_updated', 'Updated category #' . $id);
}

function delete_category(int $id, int $deletedById): void
{
    if ($id < 1) {
        throw new InvalidArgumentException('Please select a valid category.');
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $category = db_find('SELECT id, name FROM categories WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id]);
        if (!$category) {
            throw new InvalidArgumentException('Category does not exist.');
        }

        if (db_find('SELECT id FROM products WHERE category_id = ? LIMIT 1 FOR UPDATE', 'i', [$id])) {
            throw new InvalidArgumentException('This category cannot be deleted because it is currently in use by one or more products. Reassign or remove those products first.');
        }

        db_execute('DELETE FROM categories WHERE id = ?', 'i', [$id]);
        log_audit($deletedById, 'category_deleted', 'Deleted category #' . $id . ' (' . $category['name'] . ')');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function fetch_products(string $search = '', bool $onlyActive = false, ?int $shopId = null): array
{
    $sql = 'SELECT p.*, c.name AS category_name' . priority_select_sql('product') . '
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE 1=1';
    $types = '';
    $params = [];

    if ($onlyActive) {
        $sql .= ' AND p.status = "active" AND p.is_available = 1 AND c.status = "active" AND p.stock_quantity > 0';
    }

    if ($shopId !== null) {
        if ($shopId < 1) {
            return [];
        }
        $sql .= ' AND p.shop_id = ?';
        $types .= 'i';
        $params[] = $shopId;
    }

    if ($search !== '') {
        $sql .= ' AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $sql .= ' ORDER BY ' . priority_order_sql('product', 'COALESCE(p.updated_at, p.created_at)') . ', p.name ASC';

    return db_select($sql, $types, $params);
}

function inventory_low_stock_threshold(): int
{
    return 10;
}

function fetch_low_stock_products(?int $threshold = null): array
{
    return db_select(
        'SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.stock_quantity <= ? AND p.status = "active"
         ORDER BY p.stock_quantity ASC, p.name ASC',
        'i',
        [$threshold ?? inventory_low_stock_threshold()]
    );
}

function fetch_product_by_id(int $id, ?int $shopId = null): ?array
{
    $sql = 'SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ?';
    $types = 'i';
    $params = [$id];

    if ($shopId !== null) {
        if ($shopId < 1) {
            return null;
        }
        $sql .= ' AND p.shop_id = ?';
        $types .= 'i';
        $params[] = $shopId;
    }

    return db_find($sql . ' LIMIT 1', $types, $params);
}

/**
 * Resolve the shop a Manager may manage from the authenticated database row.
 * A browser-supplied role or shop identifier is never accepted as authority.
 */
function manager_product_shop(array $viewer): array
{
    $viewerId = filter_var($viewer['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($viewerId < 1 || (string) ($viewer['role'] ?? '') !== 'manager') {
        throw new RuntimeException('Only a Manager can manage assigned-shop products.');
    }

    $account = db_find(
        'SELECT id, role, status, deleted_at, assigned_shop_id
         FROM users
         WHERE id = ? LIMIT 1',
        'i',
        [$viewerId]
    );
    if (!$account || ($account['role'] ?? '') !== 'manager' || ($account['status'] ?? '') !== 'active' || !empty($account['deleted_at'])) {
        throw new RuntimeException('Your Manager account is not authorized to manage products.');
    }

    $assignedShopId = (int) ($account['assigned_shop_id'] ?? 0);
    if ($assignedShopId > 0) {
        $shop = db_find('SELECT * FROM shop_locations WHERE id = ? LIMIT 1', 'i', [$assignedShopId]);
        if (!$shop) {
            throw new RuntimeException('Your assigned shop no longer exists. Ask an Administrator to update your account.');
        }

        return $shop;
    }

    // Preserve the application's existing single-shop compatibility behavior.
    // With multiple shops, an explicit assignment is required to prevent cross-shop access.
    $shops = db_select('SELECT * FROM shop_locations ORDER BY id ASC LIMIT 2');
    if (count($shops) === 1) {
        return $shops[0];
    }

    throw new RuntimeException('This Manager account must be assigned to a shop before managing products.');
}

function assert_product_shop_schema(): void
{
    if (!database_column_exists('products', 'shop_id')) {
        throw new RuntimeException('The product shop-assignment migration must be applied before Managers can manage products.');
    }
}

function default_product_shop_id(): ?int
{
    $shop = db_find('SELECT id FROM shop_locations ORDER BY (status = "active") DESC, id ASC LIMIT 1');
    $shopId = (int) ($shop['id'] ?? 0);

    return $shopId > 0 ? $shopId : null;
}

function save_manager_product(array $viewer, array $data, ?int $id = null, bool $deleteIncomingImageOnFailure = false): void
{
    assert_product_shop_schema();
    $shop = manager_product_shop($viewer);
    $shopId = (int) $shop['id'];

    if ($id !== null) {
        $existing = fetch_product_by_id($id, $shopId);
        if (!$existing) {
            throw new InvalidArgumentException('Product does not belong to your assigned shop.');
        }
    } else {
        $data['status'] = 'active';
    }

    $data['shop_id'] = $shopId;
    // Recheck the required shop while holding the product row lock. Manager
    // edits also preserve the locked status so forged or stale form data cannot
    // overwrite a simultaneous status action.
    save_product($data, $id, $shopId, $deleteIncomingImageOnFailure);
}

function update_manager_product_availability(array $viewer, int $productId, int $availability): void
{
    assert_product_shop_schema();
    if ($productId < 1) {
        throw new InvalidArgumentException('Please select a valid product.');
    }
    if (!in_array($availability, [0, 1], true)) {
        throw new InvalidArgumentException('Please select a valid product availability.');
    }

    $shopId = (int) manager_product_shop($viewer)['id'];
    $database = get_db();
    $database->begin_transaction();
    try {
        $product = db_find(
            'SELECT id, name FROM products WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$productId, $shopId]
        );
        if (!$product) {
            throw new InvalidArgumentException('Product does not belong to your assigned shop.');
        }
        db_execute(
            'UPDATE products SET is_available = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?',
            'iii',
            [$availability, $productId, $shopId]
        );
        log_audit(
            (int) $viewer['id'],
            'product_availability_updated',
            'Updated availability for product #' . $productId . ' in shop #' . $shopId
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function update_manager_product_status(array $viewer, int $productId, string $status): void
{
    assert_product_shop_schema();
    if ($productId < 1) {
        throw new InvalidArgumentException('Please select a valid product.');
    }
    $status = require_allowed_value($status, ['active', 'inactive'], 'Please select a valid product status.');

    $shopId = (int) manager_product_shop($viewer)['id'];
    $database = get_db();
    $database->begin_transaction();
    try {
        $product = db_find(
            'SELECT p.id, p.category_id, c.status AS category_status
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ? AND p.shop_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$productId, $shopId]
        );
        if (!$product) {
            throw new InvalidArgumentException('Product does not belong to your assigned shop.');
        }
        if ($status === 'active' && ($product['category_status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Activate the selected category before activating this product.');
        }
        db_execute(
            'UPDATE products SET status = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?',
            'sii',
            [$status, $productId, $shopId]
        );
        log_audit(
            (int) $viewer['id'],
            'product_status_updated',
            'Updated status for product #' . $productId . ' to ' . $status . ' in shop #' . $shopId
        );
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    try {
        notify_inventory_threshold($productId);
    } catch (Throwable $notificationException) {
        error_log('Unable to create inventory threshold notification for product #'
            . $productId . ': ' . $notificationException->getMessage());
    }
}

function delete_manager_product(array $viewer, int $productId): void
{
    assert_product_shop_schema();
    if ($productId < 1) {
        throw new InvalidArgumentException('Please select a valid product.');
    }

    $shopId = (int) manager_product_shop($viewer)['id'];
    delete_product($productId, (int) $viewer['id'], $shopId);
}

function assert_active_product_administrator(array $viewer): array
{
    $viewerId = filter_var($viewer['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($viewerId < 1 || (string) ($viewer['role'] ?? '') !== 'administrator') {
        throw new RuntimeException('Only the Administrator can perform this product action.');
    }

    $account = db_find(
        'SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1',
        'i',
        [$viewerId]
    );
    if (!$account || ($account['role'] ?? '') !== 'administrator' || ($account['status'] ?? '') !== 'active' || !empty($account['deleted_at'])) {
        throw new RuntimeException('Only the Administrator can perform this product action.');
    }

    return $account;
}

function update_product_availability(array $viewer, int $productId, int $availability): void
{
    if ((string) ($viewer['role'] ?? '') === 'manager') {
        update_manager_product_availability($viewer, $productId, $availability);
        return;
    }

    $administrator = assert_active_product_administrator($viewer);
    if ($productId < 1) {
        throw new InvalidArgumentException('Please select a valid product.');
    }
    if (!in_array($availability, [0, 1], true)) {
        throw new InvalidArgumentException('Please select a valid product availability.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $product = db_find('SELECT id FROM products WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$productId]);
        if (!$product) {
            throw new InvalidArgumentException('Product does not exist.');
        }
        db_execute(
            'UPDATE products SET is_available = ?, updated_at = NOW() WHERE id = ?',
            'ii',
            [$availability, $productId]
        );
        log_audit((int) $administrator['id'], 'product_availability_updated', 'Updated availability for product #' . $productId);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function update_product_status_as_admin(array $viewer, int $productId, string $status): void
{
    $administrator = assert_active_product_administrator($viewer);
    if ($productId < 1) {
        throw new InvalidArgumentException('Please select a valid product.');
    }
    $status = require_allowed_value($status, ['active', 'inactive'], 'Please select a valid product status.');

    $database = get_db();
    $database->begin_transaction();
    try {
        $product = db_find(
            'SELECT p.id, p.category_id, c.status AS category_status
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$productId]
        );
        if (!$product) {
            throw new InvalidArgumentException('Product does not exist.');
        }
        if ($status === 'active' && ($product['category_status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Activate the selected category before activating this product.');
        }
        db_execute('UPDATE products SET status = ?, updated_at = NOW() WHERE id = ?', 'si', [$status, $productId]);
        log_audit((int) $administrator['id'], 'product_status_updated', 'Updated status for product #' . $productId . ' to ' . $status);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    try {
        notify_inventory_threshold($productId);
    } catch (Throwable $notificationException) {
        error_log('Unable to create inventory threshold notification for product #'
            . $productId . ': ' . $notificationException->getMessage());
    }
}

function delete_product_as_admin(array $viewer, int $productId): void
{
    $administrator = assert_active_product_administrator($viewer);
    delete_product($productId, (int) $administrator['id']);
}

function save_product(
    array $data,
    ?int $id = null,
    ?int $requiredShopId = null,
    bool $deleteIncomingImageOnFailure = false
): void
{
    $incomingImage = trim((string) ($data['image_url'] ?? ''));
    $existingBefore = $id !== null ? fetch_product_by_id($id, $requiredShopId) : null;
    $oldImage = (string) ($existingBefore['image_url'] ?? '');
    $database = get_db();
    $database->begin_transaction();

    try {
        $name = require_non_empty_string((string) ($data['name'] ?? ''), 'Product name is required.');
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Product name must be 150 characters or fewer.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if (mb_strlen($description) > 5000) {
            throw new InvalidArgumentException('Product description must be 5,000 characters or fewer.');
        }
        $status = $id === null
            ? 'active'
            : ($requiredShopId === null
                ? require_allowed_value((string) ($data['status'] ?? ''), ['active', 'inactive'], 'Please select a valid product status.')
                : '');
        $availabilityRaw = $data['is_available'] ?? null;
        if (!in_array((string) $availabilityRaw, ['0', '1'], true)) {
            throw new InvalidArgumentException('Please select a valid product availability.');
        }
        $isAvailable = (int) $availabilityRaw;
        $stockQuantity = filter_var(
            $data['stock_quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 2147483647]]
        );
        if ($stockQuantity === false) {
            throw new InvalidArgumentException('Stock quantity must be a whole number of zero or greater.');
        }
        $priceRaw = $data['price'] ?? null;
        if (!is_scalar($priceRaw) || !is_numeric((string) $priceRaw)) {
            throw new InvalidArgumentException('Product price must be a valid non-negative amount.');
        }
        $price = round((float) $priceRaw, 2);
        $existing = null;
        if ($id !== null) {
            $existing = $requiredShopId === null
                ? db_find('SELECT * FROM products WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id])
                : db_find('SELECT * FROM products WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE', 'ii', [$id, $requiredShopId]);
            if (!$existing) {
                throw new InvalidArgumentException(
                    $requiredShopId === null ? 'Product does not exist.' : 'Product does not belong to your assigned shop.'
                );
            }
            $oldImage = (string) ($existing['image_url'] ?? '');
            if ($requiredShopId !== null) {
                $status = (string) $existing['status'];
            }
        }

        if ($requiredShopId !== null) {
            if ($requiredShopId < 1) {
                throw new InvalidArgumentException('Please select a valid shop location.');
            }
            $shopId = $requiredShopId;
        } elseif (array_key_exists('shop_id', $data)) {
            $shopId = filter_var($data['shop_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            if ($data['shop_id'] !== null && $data['shop_id'] !== '' && $shopId === null) {
                throw new InvalidArgumentException('Please select a valid shop location.');
            }
        } elseif ($id !== null) {
            $shopId = !empty($existing['shop_id']) ? (int) $existing['shop_id'] : default_product_shop_id();
        } else {
            $shopId = default_product_shop_id();
        }

        if (!is_finite($price) || $price < 0 || $price > 99999999.99) {
            throw new InvalidArgumentException('Product price must be a valid non-negative amount.');
        }

        if ($shopId !== null && !db_find('SELECT id FROM shop_locations WHERE id = ? LIMIT 1', 'i', [$shopId])) {
            throw new InvalidArgumentException('Selected shop location does not exist.');
        }

        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $category = db_find('SELECT id, status FROM categories WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$categoryId]);
        if (!$category) {
            throw new InvalidArgumentException('Selected category does not exist.');
        }
        if ($status === 'active' && ($category['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Activate the selected category before activating this product.');
        }

        if ($id === null) {
            db_execute(
                'INSERT INTO products (category_id, shop_id, name, description, price, is_available, status, stock_quantity, image_url, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                'iissdisis',
                [(int) $category['id'], $shopId, $name, $description, $price, $isAvailable, $status, $stockQuantity, $incomingImage]
            );
            $productId = db_last_id();
            log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'product_created', 'Added product: ' . $name);
        } else {
            if ($requiredShopId === null) {
                db_execute(
                    'UPDATE products
                     SET category_id = ?, shop_id = ?, name = ?, description = ?, price = ?, is_available = ?, status = ?, stock_quantity = ?, image_url = ?, updated_at = NOW()
                     WHERE id = ?',
                    'iissdisisi',
                    [(int) $category['id'], $shopId, $name, $description, $price, $isAvailable, $status, $stockQuantity, $incomingImage, $id]
                );
            } else {
                db_execute(
                    'UPDATE products
                     SET category_id = ?, shop_id = ?, name = ?, description = ?, price = ?, is_available = ?, status = ?, stock_quantity = ?, image_url = ?, updated_at = NOW()
                     WHERE id = ? AND shop_id = ?',
                    'iissdisisii',
                    [(int) $category['id'], $shopId, $name, $description, $price, $isAvailable, $status, $stockQuantity, $incomingImage, $id, $requiredShopId]
                );
            }
            $productId = $id;
            log_audit(isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, 'product_updated', 'Updated product #' . $id);
        }

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        if ($deleteIncomingImageOnFailure) {
            delete_local_product_image($incomingImage);
        }
        throw $exception;
    }

    try {
        notify_inventory_threshold($productId);
    } catch (Throwable $notificationException) {
        error_log('Unable to create inventory threshold notification for product #'
            . $productId . ': ' . $notificationException->getMessage());
    }

    if ($oldImage !== '' && $oldImage !== $incomingImage) {
        delete_local_product_image($oldImage);
    }
}

function delete_product(int $id, int $deletedById, ?int $shopId = null): void
{
    $database = get_db();
    $database->begin_transaction();

    try {
        $product = $shopId === null
            ? db_find('SELECT * FROM products WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$id])
            : db_find('SELECT * FROM products WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE', 'ii', [$id, $shopId]);
        if (!$product) {
            throw new InvalidArgumentException($shopId === null ? 'Product does not exist.' : 'Product does not belong to your assigned shop.');
        }
        if (db_find('SELECT id FROM order_items WHERE product_id = ? LIMIT 1 FOR UPDATE', 'i', [$id])) {
            throw new InvalidArgumentException('This product is already linked to order records. Deactivate it instead to preserve transaction history.');
        }
        if ($shopId === null) {
            db_execute('DELETE FROM products WHERE id = ?', 'i', [$id]);
        } else {
            db_execute('DELETE FROM products WHERE id = ? AND shop_id = ?', 'ii', [$id, $shopId]);
        }
        log_audit($deletedById, 'product_deleted', 'Deleted product #' . $id . ' (' . $product['name'] . ')');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    delete_local_product_image((string) ($product['image_url'] ?? ''));
}

