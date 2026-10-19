<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('customer');
require_once APP_ROOT . '/app/includes/dashboard-widgets.php';

$viewer = dashboard_viewer(['customer']);
$dashboard = fetch_customer_dashboard_data();
$summary = $dashboard['summary'];
$pageTitle = 'My Dashboard';
$bodyClass = 'customer-body';
$isDashboardPage = true;
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section dashboard-page">
        <?php render_page_header('Welcome, ' . (string) $viewer['full_name'], 'See your orders, notifications, promos, and recent purchases.', 'dashboard', 'My Account', static function (): void { ?><a class="btn btn-primary" href="<?= e(url('customer/catalog.php')) ?>">Order Now</a><?php }); ?>

        <?php dashboard_stat_cards([
            ['icon' => 'orders', 'label' => 'My Orders', 'value' => number_format($summary['total']), 'hint' => 'All your orders'],
            ['icon' => 'clock', 'label' => 'Pending Orders', 'value' => number_format($summary['pending']), 'hint' => 'Awaiting confirmation'],
            ['icon' => 'package', 'label' => 'Processing Orders', 'value' => number_format($summary['processing']), 'hint' => 'Being prepared'],
            ['icon' => 'check-circle', 'label' => 'Delivered Orders', 'value' => number_format($summary['delivered']), 'hint' => 'Successfully delivered'],
        ]); ?>

        <div class="dashboard-grid dashboard-grid--analytics">
            <section class="dashboard-panel"><?php dashboard_panel_heading('Recent Orders', 'customer/my-orders.php'); ?><?php dashboard_order_table($dashboard['recent_orders'], 'customer'); ?></section>
            <section class="dashboard-panel"><?php dashboard_panel_heading('Order Status'); ?><?php dashboard_status_list($summary['statuses']); ?></section>
        </div>
        <div class="dashboard-grid">
            <?php dashboard_notification_panel($dashboard['notifications'], 'customer'); ?>
            <section class="dashboard-panel">
                <?php dashboard_panel_heading('Active Promos', 'customer/catalog.php', 'Browse Products'); ?>
                <ul class="dashboard-item-list">
                    <?php foreach ($dashboard['promos'] as $promo): ?>
                        <?php $offer = promo_discount_description($promo); ?>
                        <li><span><strong><?= e($promo['code']) ?></strong><?php if (!empty($promo['description'])): ?><small><?= e((string) $promo['description']) ?></small><?php endif; ?><small><?= e($offer) ?></small><small>Minimum <?= e(currency((float) $promo['minimum_subtotal'])) ?><?= $promo['ends_at'] ? ' · Ends ' . e(format_display_datetime($promo['ends_at'])) : '' ?></small></span><span class="badge badge-success"><?= $promo['discount_type'] === 'percent' ? e(rtrim(rtrim(number_format((float) $promo['discount_value'], 2), '0'), '.')) . '%' : e(currency((float) $promo['discount_value'])) ?></span></li>
                    <?php endforeach; ?>
                    <?php if ($dashboard['promos'] === []): ?><li class="dashboard-empty">No active promos right now.</li><?php endif; ?>
                </ul>
            </section>
        </div>
        <section class="dashboard-panel">
            <?php dashboard_panel_heading('Recent Purchases', 'customer/my-orders.php'); ?>
            <ul class="dashboard-item-list dashboard-item-list--columns">
                <?php foreach ($dashboard['purchases'] as $purchase): ?><li><span><a href="<?= e(url('customer/order-detail.php?id=' . (int) $purchase['order_id'])) ?>"><strong><?= e($purchase['name']) ?></strong></a><small><?= e($purchase['reference_no']) ?> · <?= e(format_display_datetime($purchase['created_at'])) ?></small></span><strong><?= e((string) $purchase['quantity']) ?> ×</strong></li><?php endforeach; ?>
                <?php if ($dashboard['purchases'] === []): ?><li class="dashboard-empty">No completed purchases yet.</li><?php endif; ?>
            </ul>
        </section>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
