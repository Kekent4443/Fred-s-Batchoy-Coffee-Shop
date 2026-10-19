<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

$typeLabels = [
    'orders' => 'Order Records',
    'deliveries' => 'Delivery Records',
    'customer_verifications' => 'Customer Verification Records',
];
$requestedType = (string) request('type', 'orders');
$type = isset($typeLabels[$requestedType]) ? $requestedType : 'orders';
$search = table_search_value();
$records = match ($type) {
    'deliveries' => fetch_deliveries('manager', (int) current_user()['id']),
    'customer_verifications' => fetch_customer_verifications('all', $search, [
        'role' => 'manager',
        'user_id' => (int) current_user()['id'],
        'can_act' => can_manage_customer_verifications(current_user()),
    ]),
    default => fetch_orders('manager', (int) current_user()['id'], $search),
};
if ($type === 'deliveries' && $search !== '') {
    $records = array_values(array_filter($records, static function (array $record) use ($search): bool {
        foreach (['reference_no', 'customer_name', 'assigned_name', 'delivery_address'] as $field) {
            if (stripos((string) ($record[$field] ?? ''), $search) !== false) {
                return true;
            }
        }
        return false;
    }));
}
$pageTitle = 'Manager Records';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section record-management manager-records">
            <?php render_page_header('Manager Records', 'Review order, delivery, and customer verification records.', 'records', 'RECORDS'); ?>

            <div class="page-panel record-management__panel">
                <form method="get" class="records-filter-form">
                    <div class="records-filter-grid">
                        <label class="filter-control-field"><span>Record Type</span><select name="type"><?php foreach ($typeLabels as $value => $label): ?><option value="<?= e($value) ?>"<?= $type === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label class="filter-control-field"><span>Search</span><input type="search" name="search" placeholder="Search reference, customer, email, or address..." value="<?= e($search) ?>"></label>
                        <div class="records-filter-action"><span class="records-filter-action__spacer" aria-hidden="true"></span><button class="btn btn-primary" type="submit">Filter Records</button></div>
                    </div>
                    <div class="records-filter-actions"><a class="btn btn-ghost" href="<?= e(url('manager/records.php')) ?>">Clear Filters</a></div>
                </form>

                <div class="record-management__results-heading">
                    <div><h2><?= e($typeLabels[$type]) ?></h2><p><?= e((string) count($records)) ?> <?= count($records) === 1 ? 'record' : 'records' ?> found</p></div>
                </div>
                <div class="table-responsive record-management__table-wrap">
                    <table class="data-table record-management__table">
                        <caption class="sr-only"><?= e($typeLabels[$type]) ?> matching the current filters</caption>
                    <thead>
                        <?php if ($type === 'deliveries'): ?>
                            <tr><th>Reference</th><th>Customer</th><th>Status</th><th>Payment</th><th>Address</th></tr>
                        <?php elseif ($type === 'customer_verifications'): ?>
                            <tr><th>Customer Full Name</th><th>Account Status</th><th>Approver Full Name</th><th>Approver Role</th><th>Approval Date</th><th>Approval Time</th></tr>
                        <?php else: ?>
                            <tr><th>Reference</th><th>Customer</th><th>Status</th><th>Payment</th><th>Total</th></tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <?php if ($type === 'customer_verifications'): ?>
                                <?php
                                $reviewedAt = !empty($record['verification_reviewed_at']) ? strtotime((string) $record['verification_reviewed_at']) : null;
                                $approverName = (string) ($record['verification_reviewed_name'] ?? '');
                                $approverRole = (string) ($record['verification_reviewed_role'] ?? '');
                                ?>
                                <tr<?= priority_item_attrs($record) ?>>
                                    <td data-label="Customer Full Name" data-record-primary><?= e($record['full_name']) ?><?= priority_badge($record, 'customer_verification') ?><br><small><?= e($record['email']) ?></small></td>
                                    <td data-label="Account Status"><?= status_badge((string) $record['status'] === 'active' ? 'approved' : (string) $record['status']) ?></td>
                                    <td data-label="Approver Full Name"><?= e($approverName !== '' ? $approverName : 'Pending approval') ?></td>
                                    <td data-label="Approver Role"><?= e($approverRole !== '' ? role_label($approverRole) : 'Pending') ?></td>
                                    <td data-label="Approval Date"><?= $reviewedAt ? e(format_display_date($reviewedAt)) : 'Pending' ?></td>
                                    <td data-label="Approval Time"><?= $reviewedAt ? e(date('h:i A', $reviewedAt)) : 'Pending' ?></td>
                                </tr>
                            <?php else: ?>
                                <tr<?= priority_item_attrs($record) ?>>
                                    <td data-label="Reference" data-record-primary><?= e($record['reference_no']) ?><?= priority_badge($record, $type === 'deliveries' ? 'delivery' : 'order') ?></td>
                                    <td data-label="Customer"><?= e($record['customer_name']) ?></td>
                                    <td data-label="Status"><?= order_workflow_badge($record) ?></td>
                                    <td data-label="Payment"><?= order_payment_summary($record) ?></td>
                                    <td data-label="<?= $type === 'deliveries' ? 'Address' : 'Total' ?>"><?= e($type === 'deliveries' ? $record['delivery_address'] : currency((float) $record['total_amount'])) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($records === []): ?>
                            <tr class="record-management__empty-row"><td colspan="<?= $type === 'customer_verifications' ? '6' : '5' ?>">No records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
