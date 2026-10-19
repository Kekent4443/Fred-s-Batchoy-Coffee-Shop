<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('staff');
require_once APP_ROOT . '/app/includes/dashboard-widgets.php';

$viewer = dashboard_viewer(['staff']);
$dashboard = fetch_staff_dashboard_data();
$summary = $dashboard['summary'];
$pageTitle = 'Staff Dashboard';
$isDashboardPage = true;
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section dashboard-page">
            <?php render_page_header('Staff Dashboard', 'Focus on today’s orders, priority work, payments, and stock alerts.', 'dashboard', 'Daily Operations'); ?>

            <?php dashboard_stat_cards([
                ['icon' => 'orders', 'label' => 'Orders Today', 'value' => number_format($summary['today']), 'hint' => 'Created today'],
                ['icon' => 'clock', 'label' => 'Pending Orders', 'value' => number_format($summary['pending']), 'hint' => 'Awaiting action'],
                ['icon' => 'delivery', 'label' => 'Processing Orders', 'value' => number_format($summary['processing']), 'hint' => 'Being prepared'],
                ['icon' => 'check-circle', 'label' => 'Completed Orders', 'value' => number_format($summary['completed']), 'hint' => 'Completed in your scope'],
                ['icon' => 'alert-triangle', 'label' => 'Low Stock Items', 'value' => number_format($dashboard['stock']['total']), 'hint' => 'At or below ' . $dashboard['stock']['threshold'] . ' units'],
            ]); ?>

            <div class="dashboard-grid">
                <section class="dashboard-panel"><?php dashboard_panel_heading('Recent Orders', 'staff/orders.php'); ?><?php dashboard_order_table($dashboard['recent_orders'], 'staff'); ?></section>
                <section class="dashboard-panel"><?php dashboard_panel_heading('Priority Orders', 'staff/orders.php'); ?><?php dashboard_order_table($dashboard['priority_orders'], 'staff', 'Priority orders'); ?></section>
            </div>
            <div class="dashboard-grid">
                <section class="dashboard-panel"><?php dashboard_panel_heading('Order Status Overview'); ?><?php dashboard_status_list($summary['statuses']); ?></section>
                <?php dashboard_stock_panel($dashboard['stock'], 'staff'); ?>
            </div>
            <div class="dashboard-grid">
                <section class="dashboard-panel">
                    <?php dashboard_panel_heading('Recent Transactions', 'staff/transactions.php'); ?>
                    <ul class="dashboard-item-list">
                        <?php foreach ($dashboard['transactions'] as $transaction): ?><li><span><strong><?= e($transaction['reference_no']) ?></strong><small><?= e(format_display_datetime($transaction['created_at'])) ?></small></span><span class="dashboard-item-list__value"><strong><?= e(currency((float) $transaction['amount'])) ?></strong><?= status_badge($transaction['transaction_status']) ?></span></li><?php endforeach; ?>
                        <?php if ($dashboard['transactions'] === []): ?><li class="dashboard-empty">No recent transactions.</li><?php endif; ?>
                    </ul>
                </section>
                <?php dashboard_notification_panel($dashboard['notifications'], 'staff'); ?>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
