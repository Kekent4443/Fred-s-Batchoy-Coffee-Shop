<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

$currentUser = current_user();

if (is_post()) {
    $type = (string) request('record_type');
    $id = (int) request('record_id');
    $action = (string) request('action');
    $state = match ($action) {
        'archive_record' => 'archived',
        'recover_record' => 'active',
        default => null,
    };

    try {
        if ($state === null) {
            throw new InvalidArgumentException('Unsupported record action.');
        }
        // Authentication history is intentionally not a supported record type
        // in set_record_state(), and has no mutation endpoint in its own page.
        set_record_state($type, $id, $state, (int) $currentUser['id']);
        flash('records', match ($state) {
            'archived' => 'Record archived successfully.',
            default => 'Record recovered successfully.',
        }, 'success');
    } catch (InvalidArgumentException $exception) {
        flash('records', $exception->getMessage(), 'danger');
    }
    redirect('admin/records.php');
}

$typeOptions = record_type_options();
$stateOptions = record_status_options();
$filterErrors = date_input_errors($_GET, ['date' => 'date']);
$recordType = (string) request('record_type', 'all');
$recordState = (string) request('record_state', 'active');
$filters = [
    'search' => table_search_value(),
    'record_type' => isset($typeOptions[$recordType]) ? $recordType : 'all',
    'record_state' => isset($stateOptions[$recordState]) ? $recordState : 'active',
    'status' => trim((string) request('status', '')),
    'date' => parse_display_date(request('date', '')) ?? '',
];
$records = $filterErrors === [] ? fetch_record_management_rows($filters) : [];
$isCustomerVerificationView = $filters['record_type'] === 'customer_verification';
$activeCount = count(fetch_record_management_rows(['record_state' => 'active']));
$archivedCount = count(fetch_record_management_rows(['record_state' => 'archived']));
$deletedCount = count(fetch_record_management_rows(['record_state' => 'deleted']));

$pageTitle = 'Record Management';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section record-management">
            <?php render_page_header('Record Management', 'Archive, recover, and review orders, transactions, deliveries, customer verifications, and user activity.', 'records', 'RECORDS'); ?>

            <div class="metric-grid record-management__metrics">
                <article class="metric-box"><div class="metric-box__icon blue">A</div><strong><?= e((string) $activeCount) ?></strong><span>Active Records</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">R</div><strong><?= e((string) $archivedCount) ?></strong><span>Archived Records</span></article>
                <article class="metric-box"><div class="metric-box__icon red">D</div><strong><?= e((string) $deletedCount) ?></strong><span>Deleted Records</span></article>
            </div>

            <div class="page-panel record-management__panel">
                <form method="get" class="records-filter-form" novalidate>
                    <div class="records-filter-grid">
                        <label class="filter-control-field"><span>Search</span><input type="search" name="search" placeholder="Search records by title, ID, description..." value="<?= e($filters['search']) ?>"></label>
                        <label class="filter-control-field"><span>Record Type</span><select name="record_type"><?php foreach ($typeOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['record_type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label class="filter-control-field"><span>Record State</span><select name="record_state"><?php foreach ($stateOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['record_state'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                        <label class="filter-control-field"><span>Status Keyword</span><input type="text" name="status" placeholder="Status keyword" value="<?= e($filters['status']) ?>"></label>
                        <?= date_input_field('date', request('date', ''), 'Date', $filterErrors, 'records', 'records-filter-field') ?>
                        <div class="records-filter-action"><span class="records-filter-action__spacer" aria-hidden="true"></span><button class="btn btn-primary" type="submit">Filter Records</button></div>
                    </div>
                    <div class="records-filter-actions"><a class="btn btn-ghost" href="<?= e(url('admin/records.php')) ?>">Clear Filters</a><a class="btn btn-ghost" href="<?= e(url('admin/auth-records.php')) ?>">View Authentication Records</a></div>
                </form>

                <div class="record-management__results-heading">
                    <div><h2><?= e($typeOptions[$filters['record_type']]) ?></h2><p><?= e((string) count($records)) ?> <?= count($records) === 1 ? 'record' : 'records' ?> in <?= e(strtolower($stateOptions[$filters['record_state']])) ?></p></div>
                </div>
                <div class="table-responsive record-management__table-wrap">
                    <table class="data-table record-management__table">
                        <caption class="sr-only">Filtered <?= e(strtolower($typeOptions[$filters['record_type']])) ?> in <?= e(strtolower($stateOptions[$filters['record_state']])) ?></caption>
                        <?php if ($isCustomerVerificationView): ?>
                            <thead><tr><th>Customer Full Name</th><th>Account Status</th><th>Approver Full Name</th><th>Approver Role</th><th>Approval Date</th><th>Approval Time</th><th>Actions</th></tr></thead>
                        <?php else: ?>
                            <thead><tr><th>Reference</th><th>Date</th><th>Type</th><th>Status</th><th>Description</th><th>Actions</th></tr></thead>
                        <?php endif; ?>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <tr<?= priority_item_attrs($record) ?>>
                                    <?php if ($isCustomerVerificationView): ?>
                                        <td data-label="Customer Full Name" data-record-primary><?= e((string) ($record['customer_name'] ?? $record['title'])) ?><?= priority_badge($record) ?></td>
                                        <td data-label="Account Status"><?= status_badge((string) $record['status']) ?></td>
                                        <td data-label="Approver Full Name"><?= e((string) (($record['approver_name'] ?? '') !== '' ? $record['approver_name'] : 'Pending approval')) ?></td>
                                        <td data-label="Approver Role"><?= e((string) (($record['approver_role'] ?? '') !== '' ? role_label((string) $record['approver_role']) : 'Pending')) ?></td>
                                        <td data-label="Approval Date"><?= e((string) (($record['approval_date'] ?? '') !== '' ? $record['approval_date'] : 'Pending')) ?></td>
                                        <td data-label="Approval Time"><?= e((string) (($record['approval_time'] ?? '') !== '' ? $record['approval_time'] : 'Pending')) ?></td>
                                    <?php else: ?>
                                        <td data-label="Reference" data-record-primary><?= e((string) $record['reference']) ?><br><small><?= e((string) $record['title']) ?></small><?= priority_badge($record) ?></td>
                                        <td data-label="Date"><?= e(format_display_datetime($record['created_at'] ?? null)) ?></td>
                                        <td data-label="Type"><?= e($typeOptions[$record['record_type']] ?? ucwords(str_replace('_', ' ', $record['record_type']))) ?></td>
                                        <td data-label="Status"><?= status_badge((string) $record['status']) ?></td>
                                        <td data-label="Description"><?= e((string) $record['description']) ?></td>
                                    <?php endif; ?>
                                    <td data-label="Actions" data-record-actions><div class="records-row-actions">
                                        <?php if ($filters['record_state'] === 'active'): ?>
                                            <form method="post" class="inline-form" data-confirm-message="Archive this record?" data-confirm-title="Archive Record" data-confirm-label="Archive" data-confirm-variant="warning"><?= csrf_field() ?><input type="hidden" name="action" value="archive_record"><input type="hidden" name="record_type" value="<?= e($record['record_type']) ?>"><input type="hidden" name="record_id" value="<?= e((string) $record['record_id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Archive</button></form>
                                        <?php else: ?>
                                            <form method="post" class="inline-form" data-confirm-message="Recover this record to Active Records?" data-confirm-title="Recover Record" data-confirm-label="Recover" data-confirm-variant="warning"><?= csrf_field() ?><input type="hidden" name="action" value="recover_record"><input type="hidden" name="record_type" value="<?= e($record['record_type']) ?>"><input type="hidden" name="record_id" value="<?= e((string) $record['record_id']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Recover</button></form>
                                        <?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$records): ?><tr class="record-management__empty-row"><td colspan="<?= $isCustomerVerificationView ? '7' : '6' ?>">No records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
