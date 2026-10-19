<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

$search = table_search_value();
$categoryId = (int) request('category_id', 0);
$availabilityFilter = (string) request('availability', '');
$statusFilter = (string) request('status', '');
$stockFilter = (string) request('stock_status', '');
$availabilityFilter = in_array($availabilityFilter, ['available', 'unavailable'], true) ? $availabilityFilter : '';
$statusFilter = in_array($statusFilter, ['active', 'inactive'], true) ? $statusFilter : '';
$stockFilter = in_array($stockFilter, ['in_stock', 'low_stock', 'out_of_stock'], true) ? $stockFilter : '';
$categories = fetch_categories();
$allProducts = fetch_products($search);
$products = array_values(array_filter($allProducts, static function (array $product) use ($categoryId, $availabilityFilter, $statusFilter, $stockFilter): bool {
    $stock = (int) ($product['stock_quantity'] ?? 0);

    if ($categoryId > 0 && (int) ($product['category_id'] ?? 0) !== $categoryId) {
        return false;
    }

    if ($availabilityFilter === 'available' && (int) ($product['is_available'] ?? 0) !== 1) {
        return false;
    }

    if ($availabilityFilter === 'unavailable' && (int) ($product['is_available'] ?? 0) === 1) {
        return false;
    }

    if ($statusFilter !== '' && ($product['status'] ?? '') !== $statusFilter) {
        return false;
    }

    if ($stockFilter === 'in_stock' && $stock <= 10) {
        return false;
    }

    if ($stockFilter === 'low_stock' && ($stock <= 0 || $stock > 10)) {
        return false;
    }

    if ($stockFilter === 'out_of_stock' && $stock !== 0) {
        return false;
    }

    return true;
}));
$availableProducts = count(array_filter($allProducts, static fn (array $product): bool => (int) ($product['is_available'] ?? 0) === 1));
$lowStockProducts = count(array_filter($allProducts, static fn (array $product): bool => (int) ($product['stock_quantity'] ?? 0) > 0 && (int) ($product['stock_quantity'] ?? 0) <= 10));
$outOfStockProducts = count(array_filter($allProducts, static fn (array $product): bool => (int) ($product['stock_quantity'] ?? 0) === 0));

$pageTitle = 'Staff Product List';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Product List', 'View product availability, category, and stock status.', 'package', 'Inventory'); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue">P</div><strong><?= e((string) count($allProducts)) ?></strong><span>Total Products</span></article>
                <article class="metric-box"><div class="metric-box__icon green">A</div><strong><?= e((string) $availableProducts) ?></strong><span>Available</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">L</div><strong><?= e((string) $lowStockProducts) ?></strong><span>Low Stock</span></article>
                <article class="metric-box"><div class="metric-box__icon red">O</div><strong><?= e((string) $outOfStockProducts) ?></strong><span>Out of Stock</span></article>
            </div>

            <div class="page-panel">
                <form method="get" class="toolbar">
                    <input type="search" name="search" placeholder="Search product, category, or description" value="<?= e($search) ?>">
                    <select name="category_id">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="availability">
                        <option value="">All Availability</option>
                        <option value="available" <?= $availabilityFilter === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="unavailable" <?= $availabilityFilter === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                    </select>
                    <select name="status">
                        <option value="">All Product Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <select name="stock_status">
                        <option value="">All Stock Levels</option>
                        <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                    <button class="btn btn-primary" type="submit">Filter Products</button>
                    <a class="btn btn-ghost" href="<?= e(url('staff/products.php')) ?>">Reset</a>
                </form>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Availability</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr<?= priority_item_attrs($product) ?>>
                                    <td>
                                        <img src="<?= e(public_file_url($product['image_url'] ?? '', asset('images/product-placeholder.svg'))) ?>" alt="<?= e($product['name']) ?>" style="width: 48px; height: 48px; object-fit: cover; border-radius: 4px;">
                                    </td>
                                    <td><?= e($product['name']) ?><?= priority_badge($product, 'product') ?></td>
                                    <td><?= e($product['category_name']) ?></td>
                                    <td><?= e(currency((float) $product['price'])) ?></td>
                                    <td>
                                        <?= e((string) $product['stock_quantity']) ?>
                                        <?php if ((int) $product['stock_quantity'] === 0): ?>
                                            <span class="badge badge-danger">Out of Stock</span>
                                        <?php elseif ((int) $product['stock_quantity'] <= 10): ?>
                                            <span class="badge badge-warning">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge((int) $product['is_available'] === 1 ? 'active' : 'inactive') ?></td>
                                    <td><?= status_badge($product['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($products === []): ?>
                                <tr><td colspan="7">No products found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
