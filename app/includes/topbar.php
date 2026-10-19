<?php
$viewer = current_user();
$currentUrl = $_SERVER['PHP_SELF'] ?? '';
$currentPage = basename((string) (parse_url($currentUrl, PHP_URL_PATH) ?: $currentUrl));
$showBackToHome = $currentPage !== 'index.php';
$notificationBellIcon = '<svg class="notification-button__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 9.8a6 6 0 0 0-12 0c0 5.2-2 6.4-2 6.4h16s-2-1.2-2-6.4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.7 19a2.5 2.5 0 0 0 4.6 0" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$notificationCount = 0;
$notificationCountLabel = '0';
$notificationHref = '';
$notificationAriaLabel = '';
$notificationScopeUserId = null;
$notificationPanelNotifications = [];
$cartCount = cart_count();

if ($viewer) {
    $notificationScopeUserId = notification_scope_user_id($viewer);
    $notificationBadgeState = notification_badge_state($viewer);
    $notificationCount = (int) $notificationBadgeState['unread_count'];
    $notificationCountLabel = (string) $notificationBadgeState['unread_label'];
    $notificationHref = role_folder($viewer['role']) . '/notifications.php';
    $notificationPanelNotifications = fetch_notifications($notificationScopeUserId, 10);
    $notificationAriaLabel = $notificationCount > 0
        ? 'Notifications: ' . $notificationCountLabel . ' unread update' . ($notificationCount === 1 ? '' : 's') . '. View alerts, messages, order status, payment, delivery, and system announcements.'
        : 'Notifications: no new updates. View alerts, messages, order status, payment, delivery, and system announcements.';
}

 $renderNotificationCenter = function (string $extraClass = '') use ($viewer, $notificationScopeUserId, $notificationBellIcon, $notificationCount, $notificationCountLabel, $notificationHref, $notificationAriaLabel, $notificationPanelNotifications): void {
    ?>
    <div
        class="notification-center <?= e($extraClass) ?>"
        data-notification-center
        data-notification-read-url="<?= e(url('notification-read.php')) ?>"
        data-notification-bulk-url="<?= e(url('notification-bulk.php')) ?>"
        data-notification-count-url="<?= e(url('notification-count.php')) ?>"
        data-notification-csrf="<?= e(csrf_token()) ?>"
        data-notification-scope="<?= e((string) $notificationScopeUserId) ?>"
    >
        <button
            class="notification-button <?= $notificationCount > 0 ? 'has-unread' : '' ?>"
            type="button"
            data-notification-toggle
            aria-expanded="false"
            aria-label="<?= e($notificationAriaLabel) ?>"
            title="<?= e($notificationAriaLabel) ?>"
        >
            <?= $notificationBellIcon ?>
            <span class="notification-button__badge" data-notification-count <?= $notificationCount > 0 ? '' : 'hidden' ?>><?= e($notificationCountLabel) ?></span>
        </button>

        <div class="notification-panel" data-notification-panel hidden>
            <div class="notification-panel__header">
                <div class="notification-panel__heading">
                    <strong>Notifications</strong>
                    <span data-notification-summary><?= $notificationCount > 0 ? e($notificationCountLabel . ' unread') : 'No unread updates' ?></span>
                </div>
                <div class="notification-panel__header-actions">
                    <form method="post" class="inline-form notification-panel__bulk-form" data-notification-bulk-form data-confirm-message="Mark all notifications as read?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_all_read">
                        <button class="auth-link" type="submit">Mark all read</button>
                    </form>
                    <a class="auth-link" href="<?= e(url($notificationHref)) ?>">View page</a>
                    <button class="notification-panel__close" type="button" data-notification-close aria-label="Close notifications panel" title="Close notifications panel">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>

            <div class="notification-panel__list" data-notification-list>
                <?php foreach ($notificationPanelNotifications as $notification): ?>
                    <?php
                    $status = (string) ($notification['status'] ?? 'unread');
                    $statusLabel = ucwords(str_replace('_', ' ', $status));
                    $statusClass = $status === 'unread' ? 'badge-warning' : 'badge-success';
                    $createdLabel = format_display_datetime($notification['created_at'] ?? null, 'No date recorded');
                    $typeLabel = notification_type_label((string) ($notification['type'] ?? 'info'));
                    $sourceLabel = notification_source_label($notification);
                    $relatedUrl = notification_absolute_url($viewer, $notification);
                    ?>
                    <button
                        class="notification-panel__item <?= $status === 'unread' ? 'is-unread' : 'is-read' ?>"
                        type="button"
                        data-notification-item
                        data-notification-id="<?= e((string) $notification['id']) ?>"
                        data-notification-status="<?= e($status) ?>"
                        data-notification-title="<?= e((string) $notification['title']) ?>"
                        data-notification-message="<?= e((string) $notification['message']) ?>"
                        data-notification-date="<?= e($createdLabel) ?>"
                        data-notification-type="<?= e($typeLabel) ?>"
                        data-notification-source="<?= e($sourceLabel) ?>"
                        data-notification-related-url="<?= e($relatedUrl) ?>"
                    >
                        <span>
                            <strong><?= e((string) $notification['title']) ?></strong>
                            <small><?= e($createdLabel) ?></small>
                        </span>
                        <span class="badge <?= e($statusClass) ?>" data-notification-item-status><?= e($statusLabel) ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if ($notificationPanelNotifications === []): ?>
                    <div class="notification-panel__empty">No notifications yet.</div>
                <?php endif; ?>
            </div>

            <div class="notification-panel__detail" data-notification-detail>
                <span class="eyebrow">Notification Details</span>
                <h3 data-notification-detail-title>Select a notification</h3>
                <p data-notification-detail-message>Click a notification above to view the full title, message, date, time, and status.</p>
                <dl>
                    <div><dt>Type</dt><dd data-notification-detail-type>-</dd></div>
                    <div><dt>Date and Time</dt><dd data-notification-detail-date>-</dd></div>
                    <div><dt>Source</dt><dd data-notification-detail-source>-</dd></div>
                    <div><dt>Status</dt><dd><span class="badge badge-muted" data-notification-detail-status>-</span></dd></div>
                </dl>
                <a class="btn btn-secondary btn-sm notification-panel__related" href="#" data-notification-detail-related hidden>Open Related Page</a>
            </div>
        </div>
    </div>
    <?php
};
?>
<?php if ($viewer && $viewer['role'] === 'customer'): ?>
    <header class="customer-topbar">
        <a href="<?= e(url('index.php')) ?>" class="customer-topbar__brand">
            <span class="customer-topbar__logo"><img src="<?= e(get_site_logo_url()) ?>" alt="" aria-hidden="true" data-drawer-logo></span>
            <strong>Fred's</strong>
        </a>
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
            <span class="menu-toggle__badge" data-notification-count <?= $notificationCount > 0 ? '' : 'hidden' ?>><?= e($notificationCountLabel) ?></span>
        </button>
        <nav class="customer-topbar__nav" data-mobile-drawer-item>
            <a class="<?= str_contains($currentUrl, 'customer/dashboard.php') ? 'is-active' : '' ?>" href="<?= e(url('customer/dashboard.php')) ?>" <?= str_contains($currentUrl, 'customer/dashboard.php') ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('dashboard', 'sidebar__icon--drawer-only') ?><span>Dashboard</span></a>
            <a class="<?= str_contains($currentUrl, 'catalog.php') ? 'is-active' : '' ?>" href="<?= e(url('customer/catalog.php')) ?>" <?= str_contains($currentUrl, 'catalog.php') ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('package', 'sidebar__icon--drawer-only') ?><span>Products</span></a>
            <a class="customer-topbar__cart-link <?= str_contains($currentUrl, 'cart.php') ? 'is-active' : '' ?>" href="<?= e(url('customer/cart.php')) ?>" <?= str_contains($currentUrl, 'cart.php') ? 'aria-current="page"' : '' ?>>
                <?= cart_icon_svg() ?>
                <span>My Cart</span>
                <span class="customer-topbar__badge"><?= e((string) $cartCount) ?></span>
            </a>
            <a class="<?= str_contains($currentUrl, 'my-orders.php') || str_contains($currentUrl, 'order-') ? 'is-active' : '' ?>" href="<?= e(url('customer/my-orders.php')) ?>" <?= str_contains($currentUrl, 'my-orders.php') || str_contains($currentUrl, 'order-') ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('receipt', 'sidebar__icon--drawer-only') ?><span>My Orders</span></a>
            <a class="<?= str_contains($currentUrl, 'notifications.php') ? 'is-active' : '' ?>" href="<?= e(url('customer/notifications.php')) ?>" <?= str_contains($currentUrl, 'notifications.php') ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('bell', 'sidebar__icon--drawer-only') ?><span>Notifications</span><span class="notification-nav-badge" data-notification-nav-count <?= $notificationCount > 0 ? '' : 'hidden' ?>><?= e($notificationCountLabel) ?></span></a>
            <a class="<?= str_contains($currentUrl, 'support.php') ? 'is-active' : '' ?>" href="<?= e(url('customer/support.php')) ?>" <?= str_contains($currentUrl, 'support.php') ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('chat', 'sidebar__icon--drawer-only') ?><span>Support</span></a>
            <a class="<?= $currentPage === 'contact.php' ? 'is-active' : '' ?>" href="<?= e(url('contact.php')) ?>" <?= $currentPage === 'contact.php' ? 'aria-current="page"' : '' ?>><?= sidebar_icon_svg('phone', 'sidebar__icon--drawer-only') ?><span>Contact Us</span></a>
        </nav>
        <div class="customer-topbar__user" data-mobile-drawer-item>
            <?php if ($showBackToHome): ?>
                <a class="btn btn-ghost btn-icon topbar-nav-icon topbar-home-link customer-topbar__home" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
                    <?= home_icon_svg() ?>
                    <span class="sr-only">Back to Home</span>
                </a>
            <?php endif; ?>
            <?php $renderNotificationCenter(); ?>
            <span class="system-theme-slot" data-system-theme-slot data-system-theme-class="customer-topbar__theme-control" aria-hidden="true"></span>
            <div data-profile-name="<?= e($viewer['full_name']) ?>">
                <strong><?= e(role_label($viewer['role'])) ?></strong>
                <span><?= e($viewer['email']) ?></span>
            </div>
            <a class="customer-topbar__avatar" href="<?= e(url('customer/profile.php')) ?>"><?= e(strtoupper(substr($viewer['full_name'], 0, 1))) ?></a>
            <form class="logout-form" method="post" action="<?= e(url('logout.php')) ?>" data-logout-form>
                <?= csrf_field() ?>
                <button class="logout-button header-logout-button customer-topbar__logout" type="submit" title="Log out" aria-label="Log out" aria-busy="false" data-logout-button>
                    <span class="logout-button__icon" data-logout-icon aria-hidden="true"><?= logout_icon_svg() ?></span>
                    <span class="logout-button__spinner" data-logout-spinner hidden aria-hidden="true"></span>
                    <span class="logout-button__label" data-logout-label data-ready-label="Log out" aria-hidden="true">Log out</span>
                    <span class="sr-only" data-logout-status aria-live="polite">Log out</span>
                </button>
            </form>
        </div>
    </header>
    <?php if (!customer_registration_email_is_verified((int) $viewer['id']) && empty($_SESSION['email_verification_reminder_dismissed'])): ?>
        <aside class="email-verification-reminder" aria-label="Email verification reminder">
            <div>
                <strong>Your email address has not been verified.</strong>
                <p>Verify your registered email to enable password recovery. You can continue using your account without verifying now.</p>
            </div>
            <div class="email-verification-reminder__actions">
                <a class="btn btn-primary btn-sm" href="<?= e(url('auth/verify-registration.php')) ?>">Verify Email</a>
                <form method="post" action="<?= e(url('auth/verify-registration.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="skip">
                    <button class="btn btn-ghost btn-sm" type="submit">Remind Me Later</button>
                </form>
            </div>
        </aside>
    <?php endif; ?>
<?php elseif ($viewer): ?>
    <header class="ops-topbar">
        <div class="ops-topbar__left">
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
                <span class="menu-toggle__badge" data-notification-count <?= $notificationCount > 0 ? '' : 'hidden' ?>><?= e($notificationCountLabel) ?></span>
            </button>
            <?php $operationalSearchPath = ($viewer['role'] ?? '') === 'rider' ? 'rider/deliveries.php' : role_folder((string) $viewer['role']) . '/orders.php'; ?>
            <form class="ops-search" method="get" action="<?= e(url($operationalSearchPath)) ?>" role="search">
                <input type="search" name="search" value="<?= e((string) ($_GET['search'] ?? '')) ?>" placeholder="Search orders..." aria-label="Search orders">
            </form>
        </div>
        <div class="ops-topbar__right" data-mobile-drawer-item>
            <?php $renderNotificationCenter(); ?>
            <span class="system-theme-slot" data-system-theme-slot data-system-theme-class="ops-topbar__theme-control" aria-hidden="true"></span>
            <div class="ops-topbar__user">
                <div data-profile-name="<?= e($viewer['full_name']) ?>">
                    <strong><?= e(role_label($viewer['role'])) ?></strong>
                    <span><?= e($viewer['email']) ?></span>
                </div>
                <a class="ops-topbar__avatar" href="<?= e(url(role_folder($viewer['role']) . '/profile.php')) ?>"><?= e(strtoupper(substr($viewer['full_name'], 0, 1))) ?></a>
                <form class="logout-form" method="post" action="<?= e(url('logout.php')) ?>" data-logout-form>
                    <?= csrf_field() ?>
                    <button class="logout-button header-logout-button customer-topbar__logout ops-topbar__logout" type="submit" title="Log out" aria-label="Log out" aria-busy="false" data-logout-button>
                        <span class="logout-button__icon" data-logout-icon aria-hidden="true"><?= logout_icon_svg() ?></span>
                        <span class="logout-button__spinner" data-logout-spinner hidden aria-hidden="true"></span>
                        <span class="logout-button__label" data-logout-label data-ready-label="Log out" aria-hidden="true">Log out</span>
                        <span class="sr-only" data-logout-status aria-live="polite">Log out</span>
                    </button>
                </form>
            </div>
        </div>
    </header>
<?php else: ?>
    <?php $usePortalDrawer = in_array($currentPage, ['catalog.php', 'contact.php'], true); ?>
    <header class="topbar topbar--public">
        <?php
        $guestCartId = 'public-topbar-cart';
        require APP_ROOT . '/app/includes/public_cart.php';
        ?>
        <div class="topbar__brand">
            <a href="<?= e(url('index.php')) ?>" class="brand-link">
                <?php if ($usePortalDrawer): ?>
                    <img class="sr-only" data-drawer-logo src="<?= e(get_site_logo_url()) ?>" alt="">
                <?php endif; ?>
                <span class="brand-link__eyebrow">Capstone System</span>
                <strong><?= e(app_settings()['brand_name']) ?></strong>
            </a>
        </div>
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
        <div class="topbar__actions" <?= $usePortalDrawer ? '' : 'data-mobile-drawer-item' ?>>
            <div class="topbar__nav-controls" role="group" aria-label="Navigation shortcuts">
                <?php if ($showBackToHome && !$usePortalDrawer): ?>
                    <a class="btn btn-ghost btn-icon topbar-nav-icon topbar-home-link" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
                        <?= home_icon_svg() ?>
                        <span class="sr-only">Back to Home</span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="topbar__account-actions">
                <a class="btn btn-primary btn-sm portal-home__action" <?= $usePortalDrawer ? 'data-mobile-drawer-item' : '' ?> href="<?= e(url('auth/customer-register.php')) ?>">Sign Up</a>
                <a class="btn btn-ghost btn-sm portal-home__action" <?= $usePortalDrawer ? 'data-mobile-drawer-item' : '' ?> href="<?= e(url('auth/login.php')) ?>">Login</a>
                <a class="btn <?= $currentPage === 'contact.php' ? 'btn-primary' : 'btn-ghost' ?> btn-sm portal-home__action portal-contact-nav" <?= $usePortalDrawer ? 'data-mobile-drawer-item' : '' ?> href="<?= e(url('contact.php')) ?>">Contact Us</a>
                <?php if ($usePortalDrawer): ?>
                    <div class="catalog-mobile-icon-row" data-mobile-drawer-item role="group" aria-label="Page shortcuts">
                        <a class="mobile-menu-icon-button" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
                            <?= home_icon_svg() ?>
                        </a>
                        <span class="system-theme-slot" data-system-theme-slot data-system-theme-class="portal-theme-control" aria-hidden="true"></span>
                    </div>
                <?php else: ?>
                    <span class="system-theme-slot" data-system-theme-slot data-system-theme-class="topbar__theme-control" aria-hidden="true"></span>
                <?php endif; ?>
            </div>
        </div>
    </header>
<?php endif; ?>
