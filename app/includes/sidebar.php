<?php
$viewer = current_user();
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$navMap = [
    'administrator' => [
        ['Dashboard', 'admin/dashboard.php'],
        ['User Management', 'admin/users.php'],
        ['Customer Verification', 'customer-verifications.php'],
        ['Product Management', 'admin/products.php'],
        ['Category Management', 'admin/categories.php'],
        ['Discounts & Promotions', 'admin/discount-rules.php'],
        ['Orders', 'admin/orders.php'],
        ['Transactions', 'admin/transactions.php'],
        ['Delivery Tracking', 'admin/deliveries.php'],
        ['Notifications', 'admin/notifications.php'],
        ['Support Chat', 'admin/support.php'],
        ['Support FAQs', 'admin/support-faqs.php'],
        ['Shop Location', 'admin/shop-location.php'],
        ['GCash Settings', 'admin/gcash-settings.php'],
        ['Receipt Settings', 'admin/receipt-settings.php'],
        ['Contact Us', 'contact.php'],
        ['Contact Messages', 'admin/contact-messages.php'],
        ['Reports & Analytics', 'admin/reports.php'],
        ['Record Management', 'admin/records.php'],
        ['Authentication Records', 'admin/auth-records.php'],
    ],
    'manager' => [
        ['Dashboard', 'manager/dashboard.php'],
        ['Staff Accounts', 'manager/staff.php'],
        ['Customer Verification', 'customer-verifications.php'],
        ['Products', 'manager/products.php'],
        ['Discounts & Promotions', 'manager/discount-rules.php'],
        ['Orders', 'manager/orders.php'],
        ['Transactions', 'manager/transactions.php'],
        ['Deliveries', 'manager/deliveries.php'],
        ['Notifications', 'manager/notifications.php'],
        ['Support Chat', 'manager/support.php'],
        ['Shop Location', 'manager/shop-location.php'],
        ['Contact Us', 'contact.php'],
        ['Contact Messages', 'manager/contact-messages.php'],
        ['Reports', 'manager/reports.php'],
        ['Records', 'manager/records.php'],
    ],
    'staff' => [
        ['Dashboard', 'staff/dashboard.php'],
        ['Customer Verification', 'customer-verifications.php'],
        ['Product List', 'staff/products.php'],
        ['Orders', 'staff/orders.php'],
        ['Transactions', 'staff/transactions.php'],
        ['Notifications', 'staff/notifications.php'],
        ['Support Chat', 'staff/support.php'],
        ['Contact Us', 'contact.php'],
        ['Contact Messages', 'staff/contact-messages.php'],
        ['Records', 'staff/records.php'],
    ],
    'rider' => [
        ['Dashboard', 'rider/dashboard.php'],
        ['Assigned Deliveries', 'rider/deliveries.php'],
        ['Notifications', 'rider/notifications.php'],
    ],
];
$sidebarIconMap = [
    'Dashboard' => 'dashboard',
    'User Management' => 'users',
    'Staff Accounts' => 'users',
    'Customer Verification' => 'shield-check',
    'Product Management' => 'package',
    'Products' => 'package',
    'Product List' => 'package',
    'Category Management' => 'categories',
    'Discounts & Promotions' => 'percent',
    'Orders' => 'orders',
    'Transactions' => 'transactions',
    'Delivery Tracking' => 'delivery',
    'Deliveries' => 'delivery',
    'Assigned Deliveries' => 'delivery',
    'Notifications' => 'bell',
    'Support Chat' => 'chat',
    'Support FAQs' => 'help',
    'Shop Location' => 'location',
    'GCash Settings' => 'wallet',
    'Receipt Settings' => 'receipt',
    'Contact Us' => 'phone',
    'Contact Messages' => 'inbox',
    'Reports & Analytics' => 'reports',
    'Reports' => 'reports',
    'Record Management' => 'records',
    'Authentication Records' => 'shield-check',
    'Records' => 'records',
    'Profile Settings' => 'profile',
];
$navItems = $viewer ? ($navMap[$viewer['role']] ?? []) : [];
$sidebarBadgeState = $viewer ? notification_badge_state($viewer, true) : ['menu_counts' => []];
$sidebarMenuCounts = $sidebarBadgeState['menu_counts'] ?? [];
?>
<aside class="sidebar" id="app-sidebar" data-sidebar data-sidebar-scope="<?= e((string) ($viewer['role'] ?? 'guest')) ?>">
    <div class="sidebar__header">
        <div class="sidebar__logo"><img src="<?= e(get_site_logo_url()) ?>" alt="" aria-hidden="true"></div>
        <div>
            <span class="sidebar__eyebrow"><?= e(role_label($viewer['role'] ?? 'guest')) ?> Portal</span>
            <h2><?= e(app_settings()['brand_name']) ?></h2>
        </div>
    </div>
    <nav class="sidebar__nav">
        <?php foreach ($navItems as [$label, $path]): ?>
            <?php
            $active = basename($currentPath) === basename($path)
                || ($label === 'Discounts & Promotions' && basename($currentPath) === 'promo-codes.php');
            $itemBadgeKey = sidebar_menu_badge_key($label, $path);
            $itemBadgeState = $sidebarMenuCounts[$itemBadgeKey] ?? ['count' => 0, 'label' => '0'];
            $itemUnreadCount = (int) ($itemBadgeState['count'] ?? 0);
            $itemUnreadLabel = (string) ($itemBadgeState['label'] ?? notification_count_label($itemUnreadCount));
            $itemIconKey = $sidebarIconMap[$label] ?? 'link';
            ?>
            <a class="sidebar__link <?= $active ? 'is-active' : '' ?>" href="<?= e(url($path)) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                <?= sidebar_icon_svg($itemIconKey) ?>
                <span class="sidebar__link-label"><?= e($label) ?></span>
                <?php if ($itemBadgeKey !== ''): ?>
                    <span
                        class="notification-nav-badge sidebar__notification-badge"
                        data-notification-menu-badge="<?= e($itemBadgeKey) ?>"
                        <?= $label === 'Notifications' ? 'data-notification-nav-count' : '' ?>
                        <?= $itemUnreadCount > 0 ? '' : 'hidden' ?>
                    ><?= e($itemUnreadLabel) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <a class="sidebar__profile <?= basename($currentPath) === 'profile.php' ? 'is-active' : '' ?>" href="<?= e(url(role_folder($viewer['role']) . '/profile.php')) ?>" <?= basename($currentPath) === 'profile.php' ? 'aria-current="page"' : '' ?>>
        <?= sidebar_icon_svg($sidebarIconMap['Profile Settings']) ?>
        <span class="sidebar__link-label">Profile Settings</span>
    </a>
</aside>
