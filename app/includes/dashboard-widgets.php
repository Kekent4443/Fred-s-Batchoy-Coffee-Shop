<?php

declare(strict_types=1);

/** Presentation only. Data passed to these widgets is already role-scoped. */
function dashboard_stat_cards(array $cards): void
{
    ?>
    <div class="dashboard-stat-grid">
        <?php foreach ($cards as $card): ?>
            <article class="dashboard-stat-card">
                <?= sidebar_icon_svg($card['icon'], 'dashboard-stat-card__icon') ?>
                <span class="dashboard-stat-card__label"><?= e($card['label']) ?></span>
                <strong><?= e((string) $card['value']) ?></strong>
                <small><?= e($card['hint']) ?></small>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
}

function dashboard_panel_heading(string $title, string $path = '', string $action = 'View all'): void
{
    ?>
    <div class="dashboard-panel__heading">
        <h2><?= e($title) ?></h2>
        <?php if ($path !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($path)) ?>"><?= e($action) ?></a><?php endif; ?>
    </div>
    <?php
}

function dashboard_order_table(array $orders, string $role, string $caption = 'Recent orders'): void
{
    $personal = $role === 'customer';
    ?>
    <div class="table-responsive dashboard-table">
        <table class="data-table">
            <caption class="sr-only"><?= e($caption) ?></caption>
            <thead><tr><th scope="col">Order Number</th><?php if (!$personal): ?><th scope="col">Customer</th><?php endif; ?><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Date</th></tr></thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php $path = $personal ? 'customer/order-detail.php?id=' . (int) $order['id'] : role_folder($role) . '/orders.php?search=' . rawurlencode($order['reference_no']); ?>
                    <tr>
                        <td><a href="<?= e(url($path)) ?>"><?= e($order['reference_no']) ?></a><?php if (isset($order['priority_level'])): ?><?= priority_badge($order, 'order') ?><?php endif; ?></td>
                        <?php if (!$personal): ?><td><?= e($order['customer_name']) ?></td><?php endif; ?>
                        <td><?= e(currency((float) $order['total_amount'])) ?></td>
                        <td><?= status_badge($order['order_status']) ?></td>
                        <td><?= e(format_display_datetime($order['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($orders === []): ?><tr><td colspan="<?= $personal ? 4 : 5 ?>">No orders to show.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function dashboard_status_list(array $rows, string $field = 'order_status'): void
{
    ?>
    <ul class="dashboard-status-list">
        <?php foreach ($rows as $row): ?><li><?= status_badge($row[$field]) ?><strong><?= e(number_format((int) $row['total'])) ?></strong></li><?php endforeach; ?>
        <?php if ($rows === []): ?><li class="dashboard-empty">No activity to show.</li><?php endif; ?>
    </ul>
    <?php
}

function dashboard_stock_panel(array $stock, string $role): void
{
    ?>
    <section class="dashboard-panel">
        <?php dashboard_panel_heading('Stock / Inventory Alerts', role_folder($role) . '/products.php', 'Products'); ?>
        <p class="dashboard-panel__note">Current stock. Low-stock threshold: <?= e((string) $stock['threshold']) ?> units.</p>
        <ul class="dashboard-item-list">
            <?php foreach ($stock['rows'] as $product): ?>
                <li><span><strong><?= e($product['name']) ?></strong><small><?= e($product['category_name']) ?></small></span>
                    <span class="dashboard-item-list__value"><strong><?= e((string) $product['stock_quantity']) ?> left</strong><small class="<?= (int) $product['stock_quantity'] <= 0 ? 'dashboard-danger' : '' ?>"><?= (int) $product['stock_quantity'] <= 0 ? 'Out of stock' : 'Low stock' ?></small></span>
                </li>
            <?php endforeach; ?>
            <?php if ($stock['rows'] === []): ?><li class="dashboard-empty">No low-stock products.</li><?php endif; ?>
        </ul>
        <?php if ($stock['total'] > 5): ?><p class="dashboard-panel__note">Showing 5 of <?= e((string) $stock['total']) ?> alerts.</p><?php endif; ?>
    </section>
    <?php
}

function dashboard_notification_panel(array $notifications, string $role): void
{
    ?>
    <section class="dashboard-panel">
        <?php dashboard_panel_heading('Notifications', role_folder($role) . '/notifications.php'); ?>
        <ul class="dashboard-item-list">
            <?php foreach ($notifications as $notification): ?>
                <li><span><strong><?= e($notification['title']) ?></strong><small><?= e(format_display_datetime($notification['created_at'])) ?></small></span><?= status_badge($notification['status']) ?></li>
            <?php endforeach; ?>
            <?php if ($notifications === []): ?><li class="dashboard-empty">No notifications yet.</li><?php endif; ?>
        </ul>
    </section>
    <?php
}

function dashboard_delivery_list(array $deliveries, string $emptyMessage): void
{
    ?>
    <ul class="dashboard-item-list">
        <?php foreach ($deliveries as $delivery): ?>
            <li>
                <span><a href="<?= e(url('rider/deliveries.php?search=' . rawurlencode($delivery['reference_no']))) ?>"><strong><?= e($delivery['reference_no']) ?></strong></a>
                    <small><?= e($delivery['customer_name']) ?></small><small><?= e($delivery['delivery_address']) ?></small>
                    <?php if (!empty($delivery['delivery_landmark'])): ?><small>Landmark: <?= e((string) $delivery['delivery_landmark']) ?></small><?php endif; ?>
                    <?php if ($delivery['estimated_delivery_at']): ?><small>Scheduled <?= e(format_display_datetime($delivery['estimated_delivery_at'])) ?></small><?php endif; ?>
                </span>
                <?= status_badge($delivery['delivery_status']) ?>
            </li>
        <?php endforeach; ?>
        <?php if ($deliveries === []): ?><li class="dashboard-empty"><?= e($emptyMessage) ?></li><?php endif; ?>
    </ul>
    <?php
}

/** Responsive SVG, with a keyboard-readable data table and no chart dependency. */
function dashboard_sales_chart(array $series): void
{
    $peak = max(array_column($series, 'total') ?: [0]);
    if ($peak <= 0) {
        echo '<p class="dashboard-empty">No sales data for this period.</p>';
        return;
    }
    $top = ceil($peak / (10 ** floor(log10($peak)))) * (10 ** floor(log10($peak)));
    $count = count($series);
    $step = 610 / max(1, $count);
    ?>
    <div class="dashboard-chart">
        <svg viewBox="0 0 740 290" role="img" aria-labelledby="dashboard-chart-title dashboard-chart-description">
            <title id="dashboard-chart-title">Sales trend for the selected period</title>
            <desc id="dashboard-chart-description">Paid, non-cancelled orders by order date. Exact values are available in the sales data table below.</desc>
            <?php for ($tick = 0; $tick <= 4; $tick++): $y = 240 - $tick * 52; ?>
                <line class="dashboard-chart__grid" x1="100" y1="<?= $y ?>" x2="720" y2="<?= $y ?>"/>
                <text class="dashboard-chart__label" x="92" y="<?= $y + 4 ?>" text-anchor="end"><?= e(number_format($top * $tick / 4, 0)) ?></text>
            <?php endfor; ?>
            <?php foreach ($series as $i => $point): $height = 208 * $point['total'] / $top; $x = 105 + $step * $i; ?>
                <rect class="dashboard-chart__bar" x="<?= round($x, 2) ?>" y="<?= round(240 - $height, 2) ?>" width="<?= round(max(.3, $step * .65), 2) ?>" height="<?= round($height, 2) ?>" rx="2"><title><?= e($point['label'] . ': ' . currency($point['total'])) ?></title></rect>
                <?php if ($i % max(1, (int) ceil($count / 6)) === 0): ?><text class="dashboard-chart__label" x="<?= round($x, 2) ?>" y="266" text-anchor="<?= $i === 0 ? 'start' : 'middle' ?>"><?= e($point['label']) ?></text><?php endif; ?>
            <?php endforeach; ?>
        </svg>
    </div>
    <details class="dashboard-chart-data">
        <summary>View exact sales data</summary>
        <div class="table-responsive"><table class="data-table"><thead><tr><th scope="col">Period</th><th scope="col">Total Sales</th></tr></thead><tbody>
            <?php foreach ($series as $point): ?><tr><td><?= e($point['label']) ?></td><td><?= e(currency($point['total'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php
}
