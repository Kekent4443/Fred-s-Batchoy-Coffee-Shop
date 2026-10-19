<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section product-management">
            <?php render_page_header(
                $productManagementPageTitle,
                $productManagementSubtitle,
                'package',
                'Inventory',
                static function () use ($productManagementBasePath, $productManagementCanAddProduct): void {
                    if ($productManagementCanAddProduct): ?>
                        <a class="btn btn-primary" href="<?= e(url($productManagementBasePath . '?edit=new#product-form')) ?>">Add Product</a>
                    <?php endif;
                }
            ); ?>

            <?php if ($productManagementPageError !== ''): ?>
                <div class="alert alert-warning product-management__notice" role="alert"><?= e($productManagementPageError) ?></div>
            <?php endif; ?>

            <div class="metric-grid product-management__metrics">
                <article class="metric-box"><div class="metric-box__icon blue" aria-hidden="true">P</div><strong><?= e((string) count($productManagementProducts)) ?></strong><span>Total Products</span></article>
                <article class="metric-box"><div class="metric-box__icon green" aria-hidden="true">✓</div><strong><?= e((string) $productManagementInStock) ?></strong><span>In Stock</span></article>
                <article class="metric-box"><div class="metric-box__icon orange" aria-hidden="true">!</div><strong><?= e((string) $productManagementLowStock) ?></strong><span>Low Stock</span></article>
                <article class="metric-box"><div class="metric-box__icon red" aria-hidden="true">×</div><strong><?= e((string) $productManagementOutOfStock) ?></strong><span>Out of Stock</span></article>
            </div>

            <div class="page-panel" id="products-list">
                <div class="product-management__panel-heading">
                    <div>
                        <h2>Products</h2>
                        <p><?= $productManagementAssignedShop ? 'Showing products assigned to ' . e((string) $productManagementAssignedShop['shop_name']) . '.' : 'Manage product information, availability, and stock.' ?></p>
                    </div>
                </div>
                <form method="get" class="toolbar products-toolbar">
                    <label class="sr-only" for="product-search-<?= e($productManagementRole) ?>">Search products</label>
                    <input id="product-search-<?= e($productManagementRole) ?>" type="search" name="search" placeholder="Search products by name or category" value="<?= e($productManagementSearch) ?>">
                    <label class="sr-only" for="product-category-<?= e($productManagementRole) ?>">Category</label>
                    <select id="product-category-<?= e($productManagementRole) ?>" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($productManagementCategories as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>" <?= $productManagementCategoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e((string) $category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="sr-only" for="product-status-<?= e($productManagementRole) ?>">Status</label>
                    <select id="product-status-<?= e($productManagementRole) ?>" name="status_filter">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $productManagementStatusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $productManagementStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button class="btn btn-primary" type="submit">Filter Products</button>
                    <?php if ($productManagementCapabilities['can_export_products']): ?>
                        <button class="btn btn-secondary products-export-button" type="button" data-export-table="products"><?= sidebar_icon_svg('download') ?><span>Export CSV</span></button>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="<?= e(url($productManagementBasePath . '#products-list')) ?>">Reset</a>
                </form>

                <div class="table-responsive product-management__table-wrap">
                    <table class="data-table searchable-table product-management__table" data-export-name="products">
                        <thead>
                            <tr>
                                <th data-export-exclude>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Availability</th>
                                <th data-export-exclude>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productManagementProducts as $product): ?>
                                <?php $stock = (int) $product['stock_quantity']; ?>
                                <tr<?= priority_item_attrs($product) ?>>
                                    <td data-label="Image" data-export-exclude><img class="product-management__image" src="<?= e(public_file_url($product['image_url'] ?? '', asset('images/product-placeholder.svg'))) ?>" alt="<?= e((string) $product['name']) ?>"></td>
                                    <td data-label="Product" data-export-value="<?= e((string) $product['name']) ?>"><strong><?= e((string) $product['name']) ?></strong><?= priority_badge($product, 'product') ?></td>
                                    <td data-label="Category"><?= e((string) ($product['category_name'] ?? 'Uncategorized')) ?></td>
                                    <td data-label="Price"><?= e(currency((float) $product['price'])) ?></td>
                                    <td data-label="Stock"><strong><?= e((string) $stock) ?></strong><?php if ($stock <= 0): ?> <span class="badge badge-danger">Out of Stock</span><?php elseif ($stock <= inventory_low_stock_threshold()): ?> <span class="badge badge-warning">Low Stock</span><?php endif; ?></td>
                                    <td data-label="Status"><?= status_badge((string) $product['status']) ?></td>
                                    <td data-label="Availability"><span class="badge <?= (int) $product['is_available'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $product['is_available'] === 1 ? 'Available' : 'Unavailable' ?></span></td>
                                    <td data-label="Actions" data-export-exclude>
                                        <div class="table-actions product-management__actions">
                                            <?php if ($productManagementCapabilities['can_edit_product']): ?>
                                                <a class="btn btn-icon btn-edit-icon" href="<?= e(url($productManagementBasePath . '?edit=' . $product['id'] . '#product-form')) ?>" aria-label="Edit <?= e((string) $product['name']) ?>" title="Update Product Information"><?= edit_icon_svg() ?><span class="sr-only">Update Product Information</span></a>
                                            <?php endif; ?>
                                            <?php if ($productManagementCapabilities['can_change_product_status']): ?>
                                                <form method="post" class="inline-form inline-form--icon" data-confirm-message="<?= e($product['status'] === 'active' ? 'Deactivate this product and hide it from the active catalog?' : 'Activate this product and update its status to Active?') ?>" data-confirm-title="<?= e($product['status'] === 'active' ? 'Deactivate Product' : 'Activate Product') ?>" data-confirm-label="<?= e($product['status'] === 'active' ? 'Deactivate' : 'Activate') ?>" data-confirm-variant="<?= $product['status'] === 'active' ? 'danger' : 'warning' ?>"<?= $product['status'] === 'active' ? ' data-confirm-sensitive="true"' : '' ?>>
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle_product_status"><input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>"><input type="hidden" name="status" value="<?= $product['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <button class="btn btn-icon <?= $product['status'] === 'active' ? 'btn-deactivate-icon' : 'btn-activate-icon' ?>" type="submit" aria-label="<?= $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?> Product" title="<?= $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?> Product"><?= $product['status'] === 'active' ? eye_slash_icon_svg() : eye_icon_svg() ?><span class="sr-only"><?= $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?> Product</span></button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($productManagementCapabilities['can_delete_product']): ?>
                                                <form method="post" class="inline-form inline-form--icon" data-confirm-message="Delete this product permanently? This action cannot be undone." data-confirm-title="Delete Product" data-confirm-label="Delete Permanently" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
                                                    <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete Product" title="Delete Product"><?= trash_icon_svg() ?><span class="sr-only">Delete Product</span></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($productManagementProducts === []): ?>
                                <tr><td colspan="8"><div class="empty-state product-management__empty"><strong>No products found</strong><span><?= $productManagementSearch !== '' || $productManagementCategoryFilter > 0 || $productManagementStatusFilter !== '' ? 'Try changing or clearing the filters.' : 'Add the first product to get started.' ?></span></div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="page-panel page-panel--spaced" id="product-form">
                <div class="product-management__panel-heading">
                    <div><h2><?= !empty($productManagementEditProduct['id']) ? 'Update Product Information' : 'Add Product' ?></h2><p><?= !empty($productManagementEditProduct['id']) ? 'Update product details, availability, and stock.' : 'New products start Active automatically.' ?></p></div>
                    <?php if (!empty($productManagementEditProduct['id'])): ?><a class="btn btn-ghost" href="<?= e(url($productManagementBasePath . '#product-form')) ?>">Cancel Edit</a><?php endif; ?>
                </div>
                <?php if ($productManagementFormCategories === []): ?><div class="alert alert-warning product-management__notice">No active categories are available. Ask an Administrator to activate or add a category.</div><?php endif; ?>
                <form method="post" class="form-grid product-management__form" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $productManagementRole === 'manager' && empty($productManagementEditProduct['id']) ? 'add_product' : 'save_product' ?>">
                    <input type="hidden" name="product_id" value="<?= e((string) ($productManagementEditProduct['id'] ?? '')) ?>">
                    <label>Product Name<input type="text" name="name" maxlength="150" required value="<?= e((string) ($productManagementEditProduct['name'] ?? '')) ?>"<?= form_error_attributes($productManagementFormErrors, 'name', 'product') ?>><?= form_field_error($productManagementFormErrors, 'name', 'product') ?></label>
                    <label>Category<select name="category_id" required <?= $productManagementFormDisabled ? 'disabled' : '' ?><?= form_error_attributes($productManagementFormErrors, 'category_id', 'product') ?>><option value="">Select category</option><?php foreach ($productManagementFormCategories as $category): ?><option value="<?= e((string) $category['id']) ?>" <?= (string) ($productManagementEditProduct['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>><?= e((string) $category['name']) ?><?= ($category['status'] ?? '') !== 'active' ? ' (Inactive)' : '' ?></option><?php endforeach; ?></select><?= form_field_error($productManagementFormErrors, 'category_id', 'product') ?></label>
                    <label>Price<input type="number" step="0.01" min="0" max="99999999.99" name="price" inputmode="decimal" required value="<?= e((string) ($productManagementEditProduct['price'] ?? '0')) ?>"<?= form_error_attributes($productManagementFormErrors, 'price', 'product') ?>><?= form_field_error($productManagementFormErrors, 'price', 'product') ?></label>
                    <label>Stock Quantity<input type="number" min="0" max="2147483647" step="1" name="stock_quantity" inputmode="numeric" required value="<?= e((string) ($productManagementEditProduct['stock_quantity'] ?? '0')) ?>"<?= form_error_attributes($productManagementFormErrors, 'stock_quantity', 'product') ?>><?= form_field_error($productManagementFormErrors, 'stock_quantity', 'product') ?></label>
                    <label>Availability<select name="is_available" required<?= form_error_attributes($productManagementFormErrors, 'is_available', 'product') ?>><option value="1" <?= (string) ($productManagementEditProduct['is_available'] ?? '1') === '1' ? 'selected' : '' ?>>Available</option><option value="0" <?= (string) ($productManagementEditProduct['is_available'] ?? '1') === '0' ? 'selected' : '' ?>>Unavailable</option></select><?= form_field_error($productManagementFormErrors, 'is_available', 'product') ?></label>
                    <?php if (!empty($productManagementEditProduct['id']) && $productManagementCapabilities['can_edit_product_status_field']): ?>
                        <label>Status<select name="status"><option value="active" <?= ($productManagementEditProduct['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($productManagementEditProduct['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
                    <?php endif; ?>
                    <label class="form-grid__full">Upload Product Image<input type="file" name="image_upload" accept="image/png,image/jpeg,image/gif,image/webp" <?= !empty($productManagementEditProduct['id']) ? '' : 'required' ?><?= form_error_attributes($productManagementFormErrors, 'image_upload', 'product') ?>><small><?= !empty($productManagementEditProduct['id']) ? 'Leave blank to keep the current image.' : 'Upload a JPG, PNG, GIF, or WEBP product image (maximum 5 MB).' ?></small><?= form_field_error($productManagementFormErrors, 'image_upload', 'product') ?></label>
                    <?php if (!empty($productManagementEditProduct['image_url'])): ?><small class="form-grid__full">Current image: <?= e((string) $productManagementEditProduct['image_url']) ?></small><?php endif; ?>
                    <label class="form-grid__full">Description<textarea name="description" rows="4" maxlength="5000"><?= e((string) ($productManagementEditProduct['description'] ?? '')) ?></textarea></label>
                    <div class="form-grid__full product-management__form-actions"><button class="btn btn-primary" type="submit" <?= $productManagementFormDisabled ? 'disabled' : '' ?>><?= !empty($productManagementEditProduct['id']) ? 'Update Product' : 'Add Product' ?></button><?php if (!empty($productManagementEditProduct['id'])): ?><a class="btn btn-ghost" href="<?= e(url($productManagementBasePath . '#products-list')) ?>">Cancel</a><?php endif; ?></div>
                </form>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
