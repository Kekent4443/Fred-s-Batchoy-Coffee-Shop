<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$search = sanitize_search_query((string) request('search', ''));
$inventoryMessages = sync_cart_with_inventory();

if ($inventoryMessages !== []) {
    flash_many($inventoryMessages, 'warning', 'catalog_stock');
}

if (is_post() && request('action') === 'add_to_cart') {
    add_product_to_cart_with_feedback((int) request('product_id'), 1);
    redirect('index.php' . ($search !== '' ? '?search=' . rawurlencode($search) : ''));
}

$products = fetch_products($search, true);
$featuredProducts = array_slice($products, 0, 8);
$fullMenuPath = 'customer/catalog.php' . ($search !== '' ? '?search=' . rawurlencode($search) : '');
$shopLocation = current_shop_location();
$contactInfo = contact_business_info();
$storeMapUrl = shop_location_public_url($shopLocation);
$storeMapEmbedUrl = shop_location_embed_src($shopLocation);
$facebookLabel = (string) $contactInfo['facebook_label'];
$facebookUrl = (string) $contactInfo['facebook_url'];
$gmailAddress = (string) $contactInfo['email_address'];
$phoneNumber = (string) $contactInfo['contact_number'];
$phoneLink = preg_replace('/[^0-9+]/', '', $phoneNumber) ?: $phoneNumber;
$supportHeading = (string) $contactInfo['support_heading'];
$supportDescription = (string) $contactInfo['support_description'];
$facebookIcon = '<svg class="portal-contact-footer__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15.4 8.2h2.2V4.6a11 11 0 0 0-3.2-.2c-3.2 0-5.3 1.9-5.3 5.5v3.1H5.6v4h3.5v8h4.3v-8H17l.6-4h-4.2v-2.7c0-1.2.3-2.1 2-2.1z" fill="currentColor"/></svg>';
$gmailIcon = '<svg class="portal-contact-footer__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.8 6.5h14.4a1.8 1.8 0 0 1 1.8 1.8v8.4a1.8 1.8 0 0 1-1.8 1.8H4.8A1.8 1.8 0 0 1 3 16.7V8.3a1.8 1.8 0 0 1 1.8-1.8z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="m4.2 7.4 7.8 6.1 7.8-6.1" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$phoneIcon = '<svg class="portal-contact-footer__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.1 5.1 9.5 8c.4.8.2 1.7-.5 2.2l-1 .8a11 11 0 0 0 5 5l.8-1c.5-.7 1.5-.9 2.2-.5l2.9 1.4c.8.4 1.2 1.3 1 2.1l-.5 2a2.2 2.2 0 0 1-2.3 1.7C8.9 21 3 15.1 2.3 6.9A2.2 2.2 0 0 1 4 4.6l2-.5c.8-.2 1.7.2 2.1 1z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$pageTitle = 'Home';
$bodyClass = 'guest-body landing-portal-body';
$viewer = current_user();
$viewerDashboardPath = $viewer ? role_home((string) $viewer['role']) : '';
$viewerProfilePath = $viewer ? role_folder((string) $viewer['role']) . '/profile.php' : '';

require_once APP_ROOT . '/app/includes/head.php';

?>
<main class="portal-home portal-home--browse">
    <div class="portal-home__actions">
        <?php
        $guestCartId = 'portal-home-cart';
        require APP_ROOT . '/app/includes/public_cart.php';
        ?>
        <button
            class="menu-toggle topbar-drawer-toggle"
            type="button"
            data-toggle-sidebar
            aria-label="Open menu"
            aria-expanded="false"
            aria-controls="app-sidebar"
            title="Open menu"
        >
            <span class="menu-toggle__bars" aria-hidden="true">
                <span class="menu-toggle__bar" data-menu-line></span>
                <span class="menu-toggle__bar" data-menu-line></span>
                <span class="menu-toggle__bar" data-menu-line></span>
            </span>
        </button>
        <?php if ($viewer): ?>
            <a class="btn btn-primary btn-sm portal-home__action" data-mobile-drawer-item href="<?= e(url($viewerDashboardPath)) ?>">Dashboard</a>
            <a class="btn btn-ghost btn-sm portal-home__action" data-mobile-drawer-item href="<?= e(url($viewerProfilePath)) ?>">My Account</a>
            <form class="logout-form portal-home__logout-form" data-mobile-drawer-item method="post" action="<?= e(url('logout.php')) ?>" data-logout-form>
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm portal-home__action logout-button portal-logout-button" type="submit" aria-label="Log out" aria-busy="false" data-logout-button>
                    <span class="logout-button__icon" data-logout-icon aria-hidden="true"><?= logout_icon_svg() ?></span>
                    <span class="logout-button__spinner" data-logout-spinner hidden aria-hidden="true"></span>
                    <span class="logout-button__label" data-logout-label data-ready-label="Logout">Logout</span>
                    <span class="sr-only" data-logout-status aria-live="polite">Log out</span>
                </button>
            </form>
        <?php else: ?>
            <a class="btn btn-ghost btn-sm portal-home__action" data-mobile-drawer-item href="<?= e(url('auth/customer-register.php')) ?>">Sign Up</a>
            <a class="btn btn-primary btn-sm portal-home__action" data-mobile-drawer-item href="<?= e(url('auth/login.php')) ?>">Login</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm portal-home__action portal-contact-nav" data-mobile-drawer-item href="<?= e(url('contact.php')) ?>">Contact Us</a>
        <div class="catalog-mobile-icon-row" data-mobile-drawer-item role="group" aria-label="Display settings">
            <span class="system-theme-slot" data-system-theme-slot data-system-theme-class="portal-theme-control" aria-hidden="true"></span>
        </div>
    </div>

    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>

    <section class="portal-home__wrap">
        <div class="portal-brand">
            <div class="portal-brand__logo">
                <img src="<?= e(get_site_logo_url()) ?>" alt="Fred's Batchoy and Coffee Shop logo">
            </div>
            <h1>Fred's Batchoy &amp; Coffee Shop</h1>
            <p class="portal-brand__subtitle">Welcome to Fred's Batchoy &amp; Coffee Shop! Browse our delicious menu, discover your favorite meals and drinks, and enjoy a convenient ordering experience. You may explore our products as a visitor and sign in only when you are ready to checkout or manage your account.</p>
        </div>

        <div class="portal-customer-panel">
            <div class="portal-customer-panel__intro">
                <div>
                    <span class="eyebrow">Customer Portal</span>
                    <h2>Browse Products</h2>
                    <p>Search meals and drinks, view clear product details, and continue to ordering when you are ready.</p>
                </div>
                <a class="portal-link-button portal-link-button--compact" href="<?= e(url($fullMenuPath)) ?>">Open Full Menu <span>&rarr;</span></a>
            </div>

            <form class="portal-product-search" method="get">
                <input type="search" name="search" aria-label="Search products, meals, and drinks" placeholder="Search products, meals, drinks..." value="<?= e($search) ?>">
                <button class="btn btn-secondary" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-ghost" href="<?= e(url('index.php')) ?>">Clear</a>
                <?php endif; ?>
            </form>

            <div class="portal-product-grid">
                <?php foreach ($featuredProducts as $product): ?>
                    <?php
                    $stock = product_stock_quantity($product);
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
                    $canOrder = product_can_be_ordered($product);
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
                                    <button class="btn btn-primary btn-sm portal-product-card__add" type="submit" <?= $canOrder ? '' : 'disabled' ?>><?= $canOrder ? 'Add to Cart' : 'Out of Stock' ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($featuredProducts === []): ?>
                <div class="empty-state portal-empty-state">
                    <h2>No products found</h2>
                    <p>Try a different search keyword or browse the full menu.</p>
                    <a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Open Full Menu</a>
                </div>
            <?php endif; ?>
        </div>

        <section class="portal-map-section" aria-labelledby="portal-map-title">
            <div class="portal-map-section__content">
                <span class="eyebrow">Store Location</span>
                <h2 id="portal-map-title"><?= e((string) $shopLocation['shop_name']) ?></h2>
                <div class="portal-map-section__details">
                    <p><?= e((string) $shopLocation['complete_address']) ?></p>
                    <?php if ($phoneNumber !== ''): ?><p><strong>Contact:</strong> <?= e($phoneNumber) ?></p><?php endif; ?>
                    <?php if (trim((string) ($shopLocation['landmark'] ?? '')) !== ''): ?>
                        <p><strong>Landmark:</strong> <?= e((string) $shopLocation['landmark']) ?></p>
                    <?php endif; ?>
                </div>
                <a class="portal-link-button portal-map-section__button" href="<?= e($storeMapUrl) ?>" target="_blank" rel="noopener noreferrer">
                    View in Google Maps <span>&rarr;</span>
                </a>
            </div>
            <div class="portal-map-section__frame">
                <?php render_external_map((string) $shopLocation['shop_name'] . ' location on Google Maps', $storeMapEmbedUrl, $storeMapUrl); ?>
            </div>
        </section>

        <footer class="portal-contact-footer" id="contact-us" aria-labelledby="portal-contact-title">
            <div class="portal-contact-footer__heading">
                <span class="eyebrow">Contact Us</span>
                <h2 id="portal-contact-title"><?= e($supportHeading) ?></h2>
                <p><?= e($supportDescription) ?></p>
            </div>
            <div class="portal-contact-footer__links">
                <a class="portal-contact-footer__link" href="<?= e($facebookUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open Facebook page in a new tab">
                    <?= $facebookIcon ?>
                    <span>
                        <strong><?= e($facebookLabel) ?></strong>
                        <small>Message us online</small>
                    </span>
                </a>
                <?php if ($gmailAddress !== ''): ?><a class="portal-contact-footer__link" href="mailto:<?= e($gmailAddress) ?>" aria-label="Send an email to <?= e($gmailAddress) ?>">
                    <?= $gmailIcon ?>
                    <span>
                        <strong>Gmail</strong>
                        <small><?= e($gmailAddress) ?></small>
                    </span>
                </a><?php endif; ?>
                <?php if ($phoneNumber !== ''): ?><a class="portal-contact-footer__link" href="tel:<?= e($phoneLink) ?>" aria-label="Call <?= e($phoneNumber) ?>">
                    <?= $phoneIcon ?>
                    <span>
                        <strong>Phone Number</strong>
                        <small><?= e($phoneNumber) ?></small>
                    </span>
                </a><?php endif; ?>
            </div>
        </footer>
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
                <a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Continue to Menu</a>
            </div>
        </div>
    </dialog>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
