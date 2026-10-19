<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

$type = (string) request('type', 'orders');
$allowedTypes = ['all', 'orders', 'deliveries', 'transactions'];
$type = in_array($type, $allowedTypes, true) ? $type : 'orders';
$filterErrors = date_input_errors($_GET, ['date' => 'date']);
$filters = [
    'type' => $type,
    'search' => table_search_value(),
    'status' => trim((string) request('status', '')),
    'date' => parse_display_date(request('date', '')) ?? '',
];
$statusOptions = [
    'pending' => 'Pending',
    'processing' => 'Processing',
    'confirmed' => 'Confirmed',
    'assigned' => 'Assigned',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'failed_delivery' => 'Failed Delivery',
    'accepted' => 'Accepted',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$records = $filterErrors === [] ? fetch_staff_record_history((int) current_user()['id'], $filters) : [];
$allRecords = fetch_staff_record_history((int) current_user()['id'], ['type' => 'all']);
$orderCount = count(array_filter($allRecords, static fn (array $record): bool => ($record['record_type'] ?? '') === 'Order'));
$deliveryCount = count(array_filter($allRecords, static fn (array $record): bool => ($record['record_type'] ?? '') === 'Delivery'));
$transactionCount = count(array_filter($allRecords, static fn (array $record): bool => ($record['record_type'] ?? '') === 'Transaction'));
$todayCount = count(array_filter($allRecords, static fn (array $record): bool => strncmp((string) ($record['created_at'] ?? ''), date('Y-m-d'), 10) === 0));

$pageTitle = 'Staff Records';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section staff-records">
            <?php render_page_header('Search Records History', 'Search previous orders, transactions, deliveries, and historical records.', 'records', 'Record Management'); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue">O</div><strong><?= e((string) $orderCount) ?></strong><span>Order Records</span></article>
                <article class="metric-box"><div class="metric-box__icon green">D</div><strong><?= e((string) $deliveryCount) ?></strong><span>Delivery Records</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">T</div><strong><?= e((string) $transactionCount) ?></strong><span>Transaction Records</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">N</div><strong><?= e((string) $todayCount) ?></strong><span>New Today</span></article>
            </div>

            <form method="get" class="records-filter-form staff-records-filter" novalidate>
                <div class="records-filter-grid staff-records-filter__grid">
                    <label class="filter-control-field"><span>Record Type</span><select name="type">
                        <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Historical Records</option>
                        <option value="orders" <?= $type === 'orders' ? 'selected' : '' ?>>Assigned Order History</option>
                        <option value="deliveries" <?= $type === 'deliveries' ? 'selected' : '' ?>>Delivery History</option>
                        <option value="transactions" <?= $type === 'transactions' ? 'selected' : '' ?>>Transaction History</option>
                    </select></label>
                    <label class="filter-control-field"><span>Search</span><input type="search" name="search" placeholder="Search reference, customer, or details" value="<?= e($filters['search']) ?>"></label>
                    <label class="filter-control-field"><span>Status</span><select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusOptions as $status => $label): ?>
                            <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <?= date_input_field('date', request('date', ''), 'Date', $filterErrors, 'staff-records', 'records-filter-field') ?>
                    <div class="records-filter-action staff-records-filter__action">
                        <span class="records-filter-action__spacer" aria-hidden="true"></span>
                        <div class="staff-records-filter__buttons">
                            <button class="btn btn-primary" type="submit">Search Records History</button>
                            <a class="btn btn-ghost" href="<?= e(url('staff/records.php')) ?>">Reset</a>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Customer</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr<?= priority_item_attrs($record) ?>>
                                <td><?= e(format_display_datetime($record['created_at'] ?? null)) ?><?= priority_badge($record) ?></td>
                                <td><?= e($record['record_type']) ?></td>
                                <td><?= e($record['reference']) ?></td>
                                <td><?= e($record['customer_name']) ?></td>
                                <td><?= status_badge($record['status']) ?></td>
                                <td><?= e($record['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($records === []): ?>
                            <tr><td colspan="6">No matching record history found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
