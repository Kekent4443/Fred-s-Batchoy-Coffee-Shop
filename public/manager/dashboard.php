<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('manager');
dashboard_viewer(['manager']);
$dashboardPeriod = dashboard_period_filters($_GET);
$dashboard = $dashboardPeriod['errors'] === [] ? fetch_business_dashboard_data($dashboardPeriod) : null;

if (request('export') === 'dashboard' && $dashboard !== null) {
    export_csv('manager-dashboard-data.csv', ['Metric', 'Value'], [
        ['Period', $dashboardPeriod['range_label']],
        ['Total Sales', (string) $dashboard['stats']['sales_total']],
        ['Total Orders', (string) $dashboard['stats']['orders_total']],
        ['Total Customers', (string) $dashboard['customers_total']],
        ['Low Stock Alerts', (string) $dashboard['stock']['total']],
    ]);
}

$pageTitle = 'Manager Dashboard';
$isDashboardPage = true;
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section dashboard-page">
            <?php render_page_header('Manager Dashboard', 'Review the current business pulse, then open Reports for detailed analysis.', 'dashboard', 'Dashboard & Analytics', $dashboard !== null ? static function () use ($dashboardPeriod): void { ?><a class="btn btn-secondary" href="<?= e(url('manager/dashboard.php?' . http_build_query(array_merge(['period' => $dashboardPeriod['period']], $dashboardPeriod['values'], array_intersect_key($dashboardPeriod['filters'], ['order_status' => true, 'transaction_status' => true]), ['export' => 'dashboard'])))) ?>">Export Dashboard Data</a><?php } : null); ?>
            <?php $dashboardBasePath = 'manager/dashboard.php'; $dashboardRole = 'manager'; require APP_ROOT . '/app/includes/dashboard-business.php'; ?>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
