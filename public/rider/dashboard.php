<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('rider');
require_once APP_ROOT . '/app/includes/dashboard-widgets.php';

$viewer = dashboard_viewer(['rider']);
$dashboard = fetch_rider_dashboard_data();
$counts = $dashboard['counts'];
$failedCount = (int) ($counts['failed_delivery'] ?? 0) + (int) ($counts['issue_reported'] ?? 0) + (int) ($counts['cancelled'] ?? 0);
$pageTitle = 'Rider Dashboard';
$isDashboardPage = true;
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section dashboard-page">
            <?php render_page_header('Welcome, ' . (string) $viewer['full_name'], 'Review your assigned deliveries and today’s activity.', 'delivery', 'Delivery Operations', static function (): void { ?><a class="btn btn-primary" href="<?= e(url('rider/deliveries.php')) ?>">Open Assigned Deliveries</a><?php }); ?>
            <?php dashboard_stat_cards([
                ['icon' => 'delivery', 'label' => 'Assigned Deliveries', 'value' => number_format($dashboard['assigned_total']), 'hint' => 'Your delivery records'],
                ['icon' => 'package', 'label' => 'Pending Pickups', 'value' => number_format((int) ($counts['pending'] ?? 0) + (int) ($counts['assigned'] ?? 0)), 'hint' => 'Pending or ready to start'],
                ['icon' => 'clock', 'label' => 'Out for Delivery', 'value' => number_format((int) ($counts['out_for_delivery'] ?? 0)), 'hint' => 'Currently in transit'],
                ['icon' => 'check-circle', 'label' => 'Completed Today', 'value' => number_format($dashboard['completed_today']), 'hint' => 'Delivered today'],
                ['icon' => 'alert-triangle', 'label' => 'Failed / Cancelled', 'value' => number_format($failedCount), 'hint' => 'Needs attention'],
            ]); ?>
            <div class="dashboard-grid">
                <section class="dashboard-panel"><?php dashboard_panel_heading("Today's Deliveries", 'rider/deliveries.php'); ?><?php dashboard_delivery_list($dashboard['today'], 'No deliveries scheduled or updated today.'); ?></section>
                <section class="dashboard-panel"><?php dashboard_panel_heading('Assigned Orders', 'rider/deliveries.php'); ?><?php dashboard_delivery_list($dashboard['assigned'], 'No active deliveries assigned.'); ?></section>
            </div>
            <div class="dashboard-grid">
                <section class="dashboard-panel"><?php dashboard_panel_heading('Delivery Status Summary'); ?><?php dashboard_status_list($dashboard['statuses'], 'delivery_status'); ?></section>
                <section class="dashboard-panel">
                    <?php dashboard_panel_heading('Recent Delivery Activity', 'rider/deliveries.php'); ?>
                    <ul class="dashboard-item-list">
                        <?php foreach ($dashboard['activity'] as $activity): ?><li><span><strong><?= e($activity['reference_no']) ?></strong><small><?= e(format_display_datetime($activity['created_at'])) ?></small></span><?= status_badge($activity['new_status']) ?></li><?php endforeach; ?>
                        <?php if ($dashboard['activity'] === []): ?><li class="dashboard-empty">No delivery activity yet.</li><?php endif; ?>
                    </ul>
                </section>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
