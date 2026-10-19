<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');
dashboard_viewer(['administrator']);
$dashboardPeriod = dashboard_period_filters($_GET);
$dashboard = $dashboardPeriod['errors'] === [] ? fetch_business_dashboard_data($dashboardPeriod) : null;

if (request('export') === 'dashboard' && $dashboard !== null) {
    $rows = [['Period', $dashboardPeriod['range_label'], '', ''], ['Total Sales', (string) $dashboard['stats']['sales_total'], '', ''], ['Total Orders', (string) $dashboard['stats']['orders_total'], '', ''], ['Total Customers', (string) $dashboard['customers_total'], '', ''], ['', '', '', ''], ['Low Stock Products', '', '', ''], ['Product', 'Category', 'Stock Quantity', 'Status']];
    foreach (fetch_low_stock_products() as $product) $rows[] = [$product['name'], $product['category_name'], (string) $product['stock_quantity'], (int) $product['stock_quantity'] <= 0 ? 'Out of Stock' : 'Low Stock'];
    export_csv('administrator-dashboard-data.csv', ['Metric', 'Value', 'Extra', 'Status'], $rows);
}

$pageTitle = 'Administrator Dashboard';
$isDashboardPage = true;
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section dashboard-page">
            <?php render_page_header('Dashboard', 'Track the business at a glance, then use Reports for deeper analysis.', 'dashboard', 'Dashboard & Analytics', $dashboard !== null ? static function () use ($dashboardPeriod): void { ?><a class="btn btn-secondary" href="<?= e(url('admin/dashboard.php?' . http_build_query(array_merge(['period' => $dashboardPeriod['period']], $dashboardPeriod['values'], ['export' => 'dashboard'])))) ?>">Export Dashboard Data</a><?php } : null); ?>
            <?php $dashboardBasePath = 'admin/dashboard.php'; $dashboardRole = 'administrator'; require APP_ROOT . '/app/includes/dashboard-business.php'; ?>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
