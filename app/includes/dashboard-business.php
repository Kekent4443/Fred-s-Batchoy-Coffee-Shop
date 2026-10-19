<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/dashboard-widgets.php';

/** @var array $dashboard */
/** @var array $dashboardPeriod */
/** @var string $dashboardBasePath */
/** @var string $dashboardRole */
?>
<div class="dashboard-toolbar page-panel">
    <form method="get" class="dashboard-period-filter" novalidate>
        <label class="filter-control-field" for="dashboard-period">Period
            <select id="dashboard-period" name="period" data-dashboard-period aria-controls="dashboard-custom-range">
                <?php foreach ($dashboardPeriod['options'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $dashboardPeriod['period'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div id="dashboard-custom-range" class="dashboard-period-filter__custom" data-dashboard-custom-range <?= $dashboardPeriod['period'] === 'custom' ? '' : 'hidden' ?>>
            <?= date_input_field('date_from', $dashboardPeriod['values']['date_from'], 'From', $dashboardPeriod['errors'], 'dashboard') ?>
            <?= date_input_field('date_to', $dashboardPeriod['values']['date_to'], 'To', $dashboardPeriod['errors'], 'dashboard') ?>
        </div>
        <?php if ($dashboardRole === 'manager'): ?>
            <label class="filter-control-field" for="dashboard-order-status">Order Status
                <select id="dashboard-order-status" name="order_status"><option value="">All statuses</option>
                    <?php foreach (['pending', 'processing', 'confirmed', 'assigned', 'out_for_delivery', 'completed', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= ($dashboardPeriod['filters']['order_status'] ?? '') === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="filter-control-field" for="dashboard-transaction-status">Transaction Status
                <select id="dashboard-transaction-status" name="transaction_status"><option value="">All statuses</option>
                    <?php foreach (['pending', 'accepted', 'completed', 'failed', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= ($dashboardPeriod['filters']['transaction_status'] ?? '') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Update Dashboard</button>
        <a class="btn btn-ghost" href="<?= e(url($dashboardBasePath)) ?>">Clear</a>
    </form>
    <p class="dashboard-toolbar__scope"><strong><?= e($dashboardPeriod['label']) ?></strong><span><?= e($dashboardPeriod['range_label']) ?></span></p>
</div>

<?php if ($dashboard === null): ?>
    <div class="alert alert-danger" role="alert">Correct the custom dashboard date range and try again.</div>
    <?php return; ?>
<?php endif; ?>

<?php $stats = $dashboard['stats']; ?>

<?php dashboard_stat_cards([
    ['icon' => 'wallet', 'label' => 'Total Sales', 'value' => currency((float) $stats['sales_total']), 'hint' => 'Paid, valid orders · ' . $dashboardPeriod['label']],
    ['icon' => 'orders', 'label' => 'Total Orders', 'value' => number_format((int) $stats['orders_total']), 'hint' => $dashboardPeriod['range_label']],
    ['icon' => 'users', 'label' => 'Total Customers', 'value' => number_format((int) $dashboard['customers_total']), 'hint' => 'All registered customers'],
    ['icon' => 'alert-triangle', 'label' => 'Low Stock', 'value' => number_format((int) $dashboard['stock']['total']), 'hint' => 'At or below ' . $dashboard['stock']['threshold'] . ' units'],
]); ?>

<div class="dashboard-grid dashboard-grid--analytics">
    <section class="dashboard-panel">
        <?php dashboard_panel_heading('Sales Chart'); ?>
        <p class="dashboard-panel__note">Paid sales grouped <?= e($dashboardPeriod['interval'] === 'hour' ? 'hourly' : $dashboardPeriod['interval'] . 'ly') ?>.</p>
        <?php dashboard_sales_chart($dashboard['sales_trend']); ?>
    </section>
    <section class="dashboard-panel">
        <?php dashboard_panel_heading('Order Status Breakdown'); ?>
        <?php dashboard_status_list($dashboard['order_statuses']); ?>
    </section>
</div>

<div class="dashboard-grid">
    <section class="dashboard-panel">
        <?php dashboard_panel_heading('Best-Selling Products', role_folder($dashboardRole) . '/products.php', 'Products'); ?>
        <p class="dashboard-panel__note">Ranked by quantity sold from valid paid orders.</p>
        <ol class="dashboard-rank-list">
            <?php foreach ($dashboard['best_sellers'] as $product): ?>
                <li><span><strong><?= e($product['name']) ?></strong><small><?= e(number_format((int) $product['quantity_sold'])) ?> sold</small></span></li>
            <?php endforeach; ?>
            <?php if ($dashboard['best_sellers'] === []): ?><li class="dashboard-empty">No product sales for this period.</li><?php endif; ?>
        </ol>
    </section>
    <?php dashboard_stock_panel($dashboard['stock'], $dashboardRole); ?>
</div>

<section class="dashboard-panel">
    <?php dashboard_panel_heading('Recent Orders', role_folder($dashboardRole) . '/orders.php'); ?>
    <?php dashboard_order_table($dashboard['recent_orders'], $dashboardRole); ?>
</section>
