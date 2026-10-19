<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

if (is_post() && in_array((string) request('action'), ['verify_payment', 'reject_payment'], true)) {
    try {
        review_gcash_payment(
            (int) request('transaction_id'),
            request('action') === 'verify_payment' ? 'verify' : 'reject',
            (int) current_user()['id'],
            (string) request('rejection_reason')
        );
        flash('transactions', request('action') === 'verify_payment' ? 'GCash payment verified and marked Paid.' : 'GCash payment rejected and customer notified.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('transactions', $exception->getMessage(), 'danger');
    }
    redirect('admin/transactions.php');
}

$transactionStatusOptions = ['pending', 'accepted', 'completed', 'failed', 'cancelled'];
$statusFilter = in_array((string) request('transaction_status'), $transactionStatusOptions, true) ? (string) request('transaction_status') : '';
$filters = [
    'search' => table_search_value(),
    'transaction_status' => $statusFilter,
];
$transactions = fetch_transactions_filtered('administrator', (int) current_user()['id'], $filters);
$viewTransaction = request('view') ? fetch_transaction_by_id((int) request('view')) : null;

if (request('export') === '1') {
    $rows = array_map(static fn ($row) => [
        $row['reference_no'],
        $row['customer_name'],
        $row['payment_method'],
        $row['amount'],
        $row['transaction_status'],
        $row['gcash_reference'],
        $row['proof_of_payment_path'] ?? '',
        format_display_datetime($row['created_at'] ?? null),
    ], $transactions);
    export_csv('transactions.csv', ['Reference', 'Customer', 'Method', 'Amount', 'Status', 'GCash Reference', 'Proof of Payment', 'Created At'], $rows);
}

$exportQuery = http_build_query(array_merge(array_filter($filters, static fn ($value) => $value !== ''), ['export' => '1']));

$pageTitle = 'Transaction Management';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Transaction Records', 'Review GCash evidence, verify payments as Paid, reject invalid submissions, and export transaction reports.', 'transactions', 'Transaction Management', static function () use ($exportQuery): void { ?><a class="btn btn-secondary" href="<?= e(url('admin/transactions.php?' . $exportQuery)) ?>">Export Transaction Report</a><?php }); ?>
            <form method="get" class="toolbar">
                <input type="search" name="search" aria-label="Search transactions" placeholder="Search transaction" value="<?= e($filters['search']) ?>">
                <select name="transaction_status" aria-label="Transaction status">
                    <option value="">All Transaction Statuses</option>
                    <?php foreach ($transactionStatusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords($status)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Search Transaction by Status</button>
                <a class="btn btn-ghost" href="<?= e(url('admin/transactions.php')) ?>">Reset</a>
            </form>
            <?php if ($viewTransaction): ?>
                <div class="page-panel">
                    <div class="card-head"><h2>View Transaction</h2></div>
                    <ul class="summary-list">
                        <li><span>Reference</span><strong><?= e($viewTransaction['reference_no']) ?></strong></li>
                        <li><span>Customer</span><strong><?= e($viewTransaction['customer_name']) ?></strong></li>
                        <li><span>Method</span><strong><?= e(ucfirst($viewTransaction['payment_method'])) ?></strong></li>
                        <li><span>Amount</span><strong><?= e(currency((float) $viewTransaction['amount'])) ?></strong></li>
                        <li><span>Status</span><strong><?= e(ucwords($viewTransaction['transaction_status'])) ?></strong></li>
                        <?php if (!empty($viewTransaction['gcash_reference'])): ?>
                            <li><span>GCash Reference</span><strong><?= e($viewTransaction['gcash_reference']) ?></strong></li>
                        <?php endif; ?>
                        <?php if (!empty($viewTransaction['proof_of_payment_path'])): ?>
                            <li><span>Proof of Payment</span><strong><a class="auth-link" href="<?= e(protected_upload_url('payment_proof', (int) $viewTransaction['id'])) ?>" target="_blank" rel="noopener noreferrer">Open proof</a></strong></li>
                        <?php endif; ?>
                        <?php if (!empty($viewTransaction['recorded_by'])): ?>
                            <li><span>Verifier</span><strong><?= e(($viewTransaction['verifier_name'] ?? 'Unknown') . ' (' . role_label((string) ($viewTransaction['verifier_role'] ?? '')) . ')') ?></strong></li>
                            <li><span>Reviewed At</span><strong><?= e(format_display_datetime($viewTransaction['reviewed_at'] ?? null, 'Not recorded')) ?></strong></li>
                        <?php endif; ?>
                        <?php if (!empty($viewTransaction['rejection_reason'])): ?><li><span>Rejection Reason</span><strong><?= e($viewTransaction['rejection_reason']) ?></strong></li><?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Proof</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr<?= priority_item_attrs($transaction) ?>>
                                <td data-label="Reference"><?= e($transaction['reference_no']) ?><?= priority_badge($transaction, 'transaction') ?></td>
                                <td data-label="Customer"><?= e($transaction['customer_name']) ?></td>
                                <td data-label="Method"><?= e(ucfirst($transaction['payment_method'])) ?></td>
                                <td data-label="Amount"><?= e(currency((float) $transaction['amount'])) ?></td>
                                <td data-label="Status"><?= transaction_payment_badge($transaction) ?></td>
                                <td data-label="Proof">
                                    <?php if (!empty($transaction['proof_of_payment_path'])): ?>
                                        <a class="auth-link" href="<?= e(protected_upload_url('payment_proof', (int) $transaction['id'])) ?>" target="_blank" rel="noopener noreferrer">View Proof</a>
                                    <?php else: ?>
                                        <span class="badge badge-muted">No proof</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/transactions.php?view=' . $transaction['id'])) ?>">View</a>
                                    <?php if (($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'pending' && !empty($transaction['gcash_reference']) && !empty($transaction['proof_of_payment_path'])): ?>
                                        <form method="post" class="inline-form" data-confirm-message="Verify this GCash payment and mark it as Paid?" data-confirm-title="Verify Payment" data-confirm-label="Verify Payment" data-confirm-variant="warning"><?= csrf_field() ?><input type="hidden" name="action" value="verify_payment"><input type="hidden" name="transaction_id" value="<?= e((string) $transaction['id']) ?>"><button class="btn btn-primary btn-sm" type="submit">Verify</button></form>
                                        <form method="post" class="stack-form" data-confirm-message="Reject this GCash payment and notify the customer?" data-confirm-title="Reject Payment" data-confirm-label="Reject Payment" data-confirm-variant="danger" data-confirm-sensitive="true"><?= csrf_field() ?><input type="hidden" name="action" value="reject_payment"><input type="hidden" name="transaction_id" value="<?= e((string) $transaction['id']) ?>"><input type="text" name="rejection_reason" aria-label="Rejection reason for <?= e((string) $transaction['reference_no']) ?>" placeholder="Rejection reason" required><button class="btn btn-danger btn-sm" type="submit">Reject</button></form>
                                    <?php elseif (($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'pending'): ?>
                                        <span class="badge badge-warning">Waiting for proof and reference</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($transactions === []): ?>
                            <tr><td colspan="7">No transactions match this filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
