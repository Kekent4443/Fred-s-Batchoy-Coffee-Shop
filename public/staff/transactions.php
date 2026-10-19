<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

$staff = current_user();
$useQzReceipt = true;
$search = table_search_value();
$transactionStatusOptions = ['pending', 'accepted', 'completed', 'failed', 'cancelled'];
$statusFilter = (string) request('transaction_status', '');
$statusFilter = in_array($statusFilter, $transactionStatusOptions, true) ? $statusFilter : '';
$methodFilter = (string) request('payment_method', '');
$methodFilter = in_array($methodFilter, ['cash', 'gcash'], true) ? $methodFilter : '';
$proofFilter = (string) request('proof_status', '');
$proofFilter = in_array($proofFilter, ['with_proof', 'without_proof'], true) ? $proofFilter : '';

if (is_post() && request('action') === 'accept_payment') {
    try {
        accept_staff_payment((int) request('transaction_id'), (int) $staff['id']);
        flash('transactions', 'GCash payment verified and marked Paid.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('transactions', $exception->getMessage(), 'danger');
    }

    redirect('staff/transactions.php');
}

if (is_post() && request('action') === 'reject_payment') {
    try {
        review_gcash_payment((int) request('transaction_id'), 'reject', (int) $staff['id'], (string) request('rejection_reason'));
        flash('transactions', 'GCash payment rejected and the customer was notified.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('transactions', $exception->getMessage(), 'danger');
    }
    redirect('staff/transactions.php');
}

$baseTransactions = fetch_transactions_filtered('staff', (int) $staff['id'], [
    'search' => $search,
    'transaction_status' => $statusFilter,
]);
$transactions = array_values(array_filter($baseTransactions, static function (array $transaction) use ($methodFilter, $proofFilter): bool {
    if ($methodFilter !== '' && ($transaction['payment_method'] ?? '') !== $methodFilter) {
        return false;
    }

    $hasProof = !empty($transaction['proof_of_payment_path']);

    if ($proofFilter === 'with_proof' && !$hasProof) {
        return false;
    }

    if ($proofFilter === 'without_proof' && $hasProof) {
        return false;
    }

    return true;
}));
$pendingCount = count(array_filter($baseTransactions, static fn (array $transaction): bool => ($transaction['transaction_status'] ?? '') === 'pending'));
$paidCount = count(array_filter($baseTransactions, static fn (array $transaction): bool => ($transaction['transaction_status'] ?? '') === 'completed'));
$withProofCount = count(array_filter($baseTransactions, static fn (array $transaction): bool => !empty($transaction['proof_of_payment_path'])));
$gcashCount = count(array_filter($baseTransactions, static fn (array $transaction): bool => ($transaction['payment_method'] ?? '') === 'gcash'));
$viewTransaction = null;

if (request('view')) {
    $requestedId = (int) request('view');

    foreach ($transactions as $transaction) {
        if ((int) $transaction['id'] === $requestedId) {
            $viewTransaction = fetch_transaction_by_id($requestedId);
            break;
        }
    }
}

$viewHasProof = $viewTransaction && !empty($viewTransaction['proof_of_payment_path']);
$viewProofUrl = $viewHasProof ? protected_upload_url('payment_proof', (int) $viewTransaction['id']) : '';
$viewCanAccept = $viewTransaction
    && ($viewTransaction['transaction_status'] ?? '') === 'pending'
    && ($viewTransaction['order_status'] ?? '') !== 'cancelled'
    && ($viewTransaction['payment_method'] ?? '') !== 'cash'
    && (($viewTransaction['payment_method'] ?? '') !== 'gcash' || ($viewHasProof && !empty($viewTransaction['gcash_reference'])));

$pageTitle = 'Staff Transactions';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Payment Validation', 'Review GCash references and proof, then verify as Paid or reject with a reason.', 'transactions', 'Transaction Management'); ?>

            <form method="get" class="toolbar">
                <input type="search" name="search" aria-label="Search payments" placeholder="Search payment by reference, customer, method, or status" value="<?= e($search) ?>">
                <select name="transaction_status" aria-label="Payment status">
                    <option value="">All Payment Statuses</option>
                    <?php foreach ($transactionStatusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucwords($status)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="payment_method" aria-label="Payment method">
                    <option value="">All Methods</option>
                    <option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="gcash" <?= $methodFilter === 'gcash' ? 'selected' : '' ?>>GCash</option>
                </select>
                <select name="proof_status" aria-label="Proof status">
                    <option value="">All Proof Statuses</option>
                    <option value="with_proof" <?= $proofFilter === 'with_proof' ? 'selected' : '' ?>>With Proof</option>
                    <option value="without_proof" <?= $proofFilter === 'without_proof' ? 'selected' : '' ?>>Without Proof</option>
                </select>
                <button class="btn btn-primary" type="submit">Filter Payments</button>
                <a class="btn btn-ghost" href="<?= e(url('staff/transactions.php')) ?>">Reset</a>
            </form>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon orange">P</div><strong><?= e((string) $pendingCount) ?></strong><span>Pending Review</span></article>
                <article class="metric-box"><div class="metric-box__icon green">P</div><strong><?= e((string) $paidCount) ?></strong><span>Paid</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">U</div><strong><?= e((string) $withProofCount) ?></strong><span>With Upload</span></article>
                <article class="metric-box"><div class="metric-box__icon blue">G</div><strong><?= e((string) $gcashCount) ?></strong><span>GCash Payments</span></article>
            </div>

            <?php if ($viewTransaction): ?>
                <div class="page-panel" id="payment-info">
                    <div class="card-head">
                        <h2>Customer Payment Information</h2>
                        <div class="table-actions payment-validation-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('staff/transactions.php')) ?>">Close</a>
                            <?php if (($viewTransaction['transaction_status'] ?? '') === 'completed'): ?>
                                <?php $receiptOrderId = (int) $viewTransaction['order_id']; require APP_ROOT . '/app/includes/staff-receipt-print-action.php'; ?>
                            <?php endif; ?>
                            <?php if ($viewCanAccept): ?>
                                <form method="post" class="inline-form" data-confirm-message="Accept this customer payment after reviewing the proof?" data-confirm-title="Verify Payment" data-confirm-label="Verify Payment" data-confirm-variant="warning">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="accept_payment">
                                    <input type="hidden" name="transaction_id" value="<?= e((string) $viewTransaction['id']) ?>">
                                    <button class="btn btn-primary btn-sm" type="submit">Verify Payment</button>
                                </form>
                                <form method="post" class="inline-form" data-confirm-message="Reject this customer payment and notify the customer?" data-confirm-title="Reject Payment" data-confirm-label="Reject Payment" data-confirm-variant="danger" data-confirm-sensitive="true">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_payment">
                                    <input type="hidden" name="transaction_id" value="<?= e((string) $viewTransaction['id']) ?>">
                                    <input type="text" name="rejection_reason" aria-label="Rejection reason for <?= e((string) $viewTransaction['reference_no']) ?>" placeholder="Rejection reason" required>
                                    <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                </form>
                            <?php elseif (($viewTransaction['transaction_status'] ?? '') === 'completed'): ?>
                                <span class="badge badge-success">Paid</span>
                            <?php elseif (($viewTransaction['transaction_status'] ?? '') === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelled</span>
                            <?php elseif (($viewTransaction['transaction_status'] ?? '') === 'pending' && ($viewTransaction['payment_method'] ?? '') === 'cash'): ?>
                                <span class="badge badge-warning">Complete on delivery</span>
                            <?php elseif (($viewTransaction['transaction_status'] ?? '') === 'pending'): ?>
                                <span class="badge badge-warning">Waiting for proof and reference</span>
                            <?php else: ?>
                                <span class="badge badge-muted">No action</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="metric-grid">
                        <article class="metric-box"><div class="metric-box__icon blue">O</div><strong><?= e($viewTransaction['reference_no']) ?></strong><span>Order Reference</span></article>
                        <article class="metric-box"><div class="metric-box__icon green">P</div><strong><?= e(currency((float) $viewTransaction['amount'])) ?></strong><span>Payment Amount</span></article>
                        <article class="metric-box"><div class="metric-box__icon purple">M</div><strong><?= e(ucfirst($viewTransaction['payment_method'])) ?></strong><span>Payment Method</span></article>
                        <article class="metric-box"><div class="metric-box__icon orange">S</div><strong><?= status_badge($viewTransaction['transaction_status']) ?></strong><span>Transaction Status</span></article>
                    </div>

                    <div class="content-grid" style="margin-top: 1rem;">
                        <ul class="summary-list">
                            <li><span>Customer :</span><strong><?= e($viewTransaction['customer_name']) ?></strong></li>
                            <li><span>Email :</span><strong><?= e($viewTransaction['customer_email']) ?></strong></li>
                            <li><span>Phone :</span><strong><?= e($viewTransaction['customer_phone'] ?? 'N/A') ?></strong></li>
                            <li><span>Order Status</span><strong><?= e(ucwords(str_replace('_', ' ', $viewTransaction['order_status']))) ?></strong></li>
                            <li><span>Payment Status</span><strong><?= e(ucwords(str_replace('_', ' ', $viewTransaction['payment_status']))) ?></strong></li>
                            <li><span>Delivery Status</span><strong><?= e(ucwords(str_replace('_', ' ', $viewTransaction['delivery_status']))) ?></strong></li>
                        </ul>

                        <ul class="summary-list">
                            <li><span>GCash Reference :</span><strong><?= e($viewTransaction['gcash_reference'] ?: 'N/A') ?></strong></li>
                            <li><span>Proof Status :</span><strong><?= $viewHasProof ? 'Uploaded' : 'No proof uploaded' ?></strong></li>
                            <li><span>Order Date :</span><strong><?= e(format_display_datetime($viewTransaction['order_created_at'] ?? null, 'N/A')) ?></strong></li>
                            <li><span>Transaction Date :</span><strong><?= e(format_display_datetime($viewTransaction['created_at'] ?? null, 'N/A')) ?></strong></li>
                            <li><span>Last Updated :</span><strong><?= e(format_display_datetime($viewTransaction['updated_at'] ?? null, 'N/A')) ?></strong></li>
                            <li><span>Uploaded Proof :</span><strong><?= $viewHasProof ? '<a class="auth-link" href="' . e($viewProofUrl) . '" target="_blank" rel="noopener noreferrer">Open picture or screenshot</a>' : 'Required for GCash payments' ?></strong></li>
                        </ul>
                    </div>

                    <?php if ($viewHasProof): ?>
                        <div class="info-card" style="margin-top: 1rem;">
                            <strong>Proof of Payment Preview</strong>
                            <a class="auth-link" href="<?= e($viewProofUrl) ?>" target="_blank" rel="noopener noreferrer">Open full image</a>
                            <img src="<?= e($viewProofUrl) ?>" alt="Uploaded proof of payment" style="display: block; width: min(100%, 520px); max-height: 420px; object-fit: contain; margin-top: 1rem; border: 1px solid #eef2f8; border-radius: 12px;">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="page-panel page-panel--spaced">
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
                                <th>Actions</th>
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
                                    <td data-label="Actions">
                                        <div class="table-actions payment-validation-actions">
                                            <a class="btn btn-ghost btn-sm" href="<?= e(url('staff/transactions.php?view=' . $transaction['id'] . '#payment-info')) ?>">View</a>
                            <?php if (($transaction['transaction_status'] ?? '') === 'completed'): ?>
                                <?php $receiptOrderId = (int) $transaction['order_id']; require APP_ROOT . '/app/includes/staff-receipt-print-action.php'; ?>
                            <?php endif; ?>
                            <?php if (($transaction['transaction_status'] ?? '') === 'pending'): ?>
                                <?php if (($transaction['payment_method'] ?? '') === 'cash'): ?>
                                    <span class="badge badge-warning">Complete on delivery</span>
                                <?php elseif (($transaction['payment_method'] ?? '') === 'gcash' && (empty($transaction['proof_of_payment_path']) || empty($transaction['gcash_reference']))): ?>
                                    <span class="badge badge-warning">Waiting for proof and reference</span>
                                <?php else: ?>
                                                    <form method="post" class="inline-form" data-confirm-message="Accept this customer payment after reviewing the proof?" data-confirm-title="Verify Payment" data-confirm-label="Verify Payment" data-confirm-variant="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="accept_payment">
                                                        <input type="hidden" name="transaction_id" value="<?= e((string) $transaction['id']) ?>">
                                                        <button class="btn btn-primary btn-sm" type="submit">Verify Payment</button>
                                    </form>
                                                    <form method="post" class="stack-form" data-confirm-message="Reject this customer payment and notify the customer?" data-confirm-title="Reject Payment" data-confirm-label="Reject Payment" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="reject_payment">
                                                        <input type="hidden" name="transaction_id" value="<?= e((string) $transaction['id']) ?>">
                                    <input type="text" name="rejection_reason" aria-label="Rejection reason for <?= e((string) $transaction['reference_no']) ?>" placeholder="Rejection reason" required>
                                                        <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                                    </form>
                                <?php endif; ?>
                            <?php elseif (($transaction['transaction_status'] ?? '') === 'completed'): ?>
                                <span class="badge badge-success">Paid</span>
                            <?php else: ?>
                                <span class="badge badge-muted">No action</span>
                            <?php endif; ?>
                        </div>
                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($transactions === []): ?>
                                <tr><td colspan="7">No payment transactions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
