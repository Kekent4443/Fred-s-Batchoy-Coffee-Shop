<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$inventoryMessages = sync_cart_with_inventory();

if ($inventoryMessages !== []) {
    flash_many($inventoryMessages, 'warning', 'catalog_stock');
}

$search = sanitize_search_query((string) request('search', ''));
$categoryId = (int) request('category_id', 0);

if (is_post() && request('action') === 'add_to_cart') {
    $productId = (int) request('product_id');
    $requestedQuantity = max(1, (int) request('quantity', 1));
    add_product_to_cart_with_feedback($productId, $requestedQuantity);

    $redirectParams = [];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }
    if ($categoryId > 0) {
        $redirectParams['category_id'] = $categoryId;
    }

    redirect('customer/catalog.php' . ($redirectParams !== [] ? '?' . http_build_query($redirectParams) : ''));
}

$products = fetch_products($search, true);
$categories = fetch_categories();
$allProductNames = array_map(
    static fn (array $product): string => strtolower(trim((string) $product['name'])),
    fetch_products('', true)
);
$publicCategories = array_values(array_filter(
    $categories,
    static fn (array $category): bool => !in_array(strtolower(trim((string) $category['name'])), $allProductNames, true)
));

if ($categoryId > 0) {
    $products = array_values(array_filter($products, static fn ($product) => (int) $product['category_id'] === $categoryId));
}

$pageTitle = 'Order Menu';
$bodyClass = 'customer-body catalog-page';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section">
        <?php render_page_header('Our Menu', 'Discover our delicious food selection.', 'package', 'Menu'); ?>

        <div class="page-panel catalog-filter-panel">
            <form method="get" class="toolbar catalog-filter">
                <input type="search" name="search" placeholder="Search for dishes..." value="<?= e($search) ?>">
                <select name="category_id">
                    <option value="0">All Categories</option>
                    <?php foreach ($publicCategories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary" type="submit">Filter Menu</button>
            </form>
        </div>

        <div class="content-grid catalog-layout">
            <aside class="form-card">
                <div class="card-head"><h2>Categories</h2></div>
                <div class="activity-list">
                    <a class="btn <?= $categoryId === 0 ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('customer/catalog.php')) ?>">All Products</a>
                    <?php foreach ($publicCategories as $category): ?>
                        <a class="btn <?= $categoryId === (int) $category['id'] ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('customer/catalog.php?category_id=' . $category['id'])) ?>"><?= e($category['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <div>
                <div class="portal-product-grid catalog-menu-grid">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $stock = product_stock_quantity($product);
                        $cartQuantity = (int) (cart_items()[(int) $product['id']]['quantity'] ?? 0);
                        $remainingStock = max(0, $stock - $cartQuantity);
                        $canOrder = product_can_be_ordered($product) && $remainingStock > 0;
                        $productImage = public_file_url(
                            $product['image_url'] ?? '',
                            asset('images/product-placeholder.svg')
                        );
                        $productName = trim((string) $product['name']);
                        $categoryName = trim((string) ($product['category_name'] ?? ''));
                        $showCategory = $categoryName !== '' && strcasecmp($categoryName, $productName) !== 0;
                        $productDescription = trim((string) $product['description']);
                        $productDescription = $productDescription !== '' ? $productDescription : 'Product description not available yet.';
                        $productPrice = currency((float) $product['price']);
                        $stockLabel = $stock > 0 ? 'Stock quantity: ' . $stock : 'Out of stock';
                        $detailCategory = $showCategory ? $categoryName : 'Menu Item';
                        ?>
                        <article class="portal-product-card">
                            <div class="portal-product-card__media">
                                <img src="<?= e($productImage) ?>" alt="<?= e($productName) ?>" loading="lazy">
                            </div>
                            <div class="portal-product-card__body">
                                <h3><?= e($productName) ?></h3>
                                <span class="portal-product-card__description"><?= e($productDescription) ?></span>
                            </div>
                            <div class="portal-product-card__footer">
                                <div class="portal-product-card__purchase">
                                    <strong><?= e($productPrice) ?></strong>
                                    <span class="portal-product-card__stock <?= $stock > 0 ? 'is-available' : 'is-out' ?>"><?= e($stockLabel) ?></span>
                                </div>
                                <div class="portal-product-card__actions">
                                    <button
                                        class="btn btn-ghost btn-sm portal-product-card__action"
                                        type="button"
                                        data-product-detail
                                        data-product-name="<?= e($productName) ?>"
                                        data-product-category="<?= e($detailCategory) ?>"
                                        data-product-description="<?= e($productDescription) ?>"
                                        data-product-price="<?= e($productPrice) ?>"
                                        data-product-stock="<?= e($stockLabel) ?>"
                                        data-product-image="<?= e($productImage) ?>"
                                    >View Details</button>
                                    <form method="post" class="portal-product-card__cart-form" data-add-to-cart-form data-confirm-message="Add this item to your cart?" data-confirm-title="Add to Cart" data-confirm-label="Add Item" data-confirm-variant="info">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
                                        <input type="hidden" name="search" value="<?= e($search) ?>">
                                        <input type="hidden" name="category_id" value="<?= e((string) $categoryId) ?>">
                                        <button class="btn btn-primary btn-sm portal-product-card__add" type="submit" <?= $canOrder ? '' : 'disabled' ?>><?= $canOrder ? 'Add to Cart' : 'Out of Stock' ?></button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <dialog class="product-detail-dialog" id="product-details-dialog" data-product-detail-dialog aria-labelledby="product-detail-title">
        <div class="product-detail-dialog__card">
            <button class="product-detail-dialog__close" type="button" data-product-detail-close aria-label="Close product details">&times;</button>
            <div class="product-detail-dialog__media">
                <img src="" alt="" data-product-detail-image>
            </div>
            <div class="product-detail-dialog__content">
                <span class="chip" data-product-detail-category></span>
                <h2 id="product-detail-title" data-product-detail-name></h2>
                <p data-product-detail-description></p>
                <strong data-product-detail-price></strong>
                <span class="product-detail-dialog__stock" data-product-detail-stock></span>
            </div>
        </div>
    </dialog>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
