<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

if (is_post() && in_array((string) request('action'), ['verify_payment', 'reject_payment'], true)) {
    try {
        $isApproval = request('action') === 'verify_payment';
        review_gcash_payment(
            (int) request('transaction_id'),
            $isApproval ? 'verify' : 'reject',
            (int) current_user()['id'],
            (string) request('rejection_reason')
        );
        flash('transactions', $isApproval
            ? 'GCash payment approved and marked Paid. The order can now continue.'
            : 'GCash payment rejected and the customer was notified.', 'success');
    } catch (InvalidArgumentException $exception) {
        flash('transactions', $exception->getMessage(), 'danger');
    }

    redirect('manager/transactions.php');
}

$search = table_search_value();
$quickFilter = (string) request('filter', 'pending');
$quickFilters = [
    'pending' => 'Pending Verification',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'all' => 'All Transactions',
];
$quickFilter = array_key_exists($quickFilter, $quickFilters) ? $quickFilter : 'pending';

$allTransactions = fetch_transactions_filtered('manager', (int) current_user()['id'], ['search' => $search]);

$pendingCount = (int) (db_find(
    'SELECT COUNT(*) AS total FROM transactions WHERE payment_method = "gcash" AND transaction_status = "pending"'
)['total'] ?? 0);

$filteredTransactions = array_values(array_filter($allTransactions, static function (array $transaction) use ($quickFilter): bool {
    return match ($quickFilter) {
        'pending' => ($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'pending',
        'approved' => ($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'completed',
        'rejected' => ($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'failed',
        'cash' => ($transaction['payment_method'] ?? '') === 'cash',
        'gcash' => ($transaction['payment_method'] ?? '') === 'gcash',
        default => true,
    };
}));

$reviewQueue = array_values(array_filter($filteredTransactions, static fn (array $transaction): bool =>
    ($transaction['payment_method'] ?? '') === 'gcash'
    && ($transaction['transaction_status'] ?? '') === 'pending'
));
$historySource = $quickFilter === 'pending' ? $allTransactions : $filteredTransactions;
$historyTransactions = array_values(array_filter($historySource, static fn (array $transaction): bool =>
    !(($transaction['payment_method'] ?? '') === 'gcash' && ($transaction['transaction_status'] ?? '') === 'pending')
));

$pageTitle = 'GCash Payment Review';
$pageScripts = ['js/manager-transactions.js'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<style>
.payment-review-header { align-items: center; }
.pending-review-count { min-width: 170px; padding: 1rem 1.25rem; border-radius: 16px; background: color-mix(in srgb, var(--warning) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--warning) 38%, var(--border)); }
.pending-review-count strong { display: block; color: var(--text); font-size: 2rem; line-height: 1; }
.pending-review-count span { color: var(--text-muted); font-size: .86rem; }
.transaction-filter-tabs { display: flex; flex-wrap: wrap; gap: .55rem; margin: 1rem 0; }
.transaction-filter-tabs a { padding: .65rem .9rem; border: 1px solid var(--border); border-radius: 999px; color: var(--text); text-decoration: none; background: var(--surface); font-weight: 700; font-size: .86rem; }
.transaction-filter-tabs a.is-active { color: #fff; border-color: var(--primary); background: var(--primary); }
.transaction-filter-tabs a:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.payment-review-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 390px), 1fr)); gap: 1rem; }
.payment-review-card { overflow: hidden; min-width: 0; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); box-shadow: var(--shadow-soft); }
.payment-review-card__head { display: flex; justify-content: space-between; gap: 1rem; padding: 1rem 1.1rem; background: var(--surface-accent); border-bottom: 1px solid var(--border); }
.payment-review-card__head h2 { margin: .15rem 0 0; font-size: 1.08rem; }
.payment-review-card__body { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 1rem; padding: 1.1rem; }
.payment-proof-thumb { width: 150px; height: 150px; padding: 0; overflow: hidden; border: 1px solid var(--border); border-radius: 13px; background: var(--surface-muted); cursor: zoom-in; }
.payment-proof-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.payment-review-meta { display: grid; gap: .5rem; margin: 0; }
.payment-review-meta div { display: grid; gap: .1rem; }
.payment-review-meta dt { color: var(--text-muted); font-size: .73rem; text-transform: uppercase; letter-spacing: .04em; }
.payment-review-meta dd { margin: 0; color: var(--text); font-weight: 700; overflow-wrap: anywhere; }
.payment-review-card__actions { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; padding: 0 1.1rem 1.1rem; }
.payment-review-card__actions .btn { min-height: 46px; justify-content: center; }
.payment-review-card__actions .proof-action { grid-column: 1 / -1; }
.transaction-history { margin-top: 1.25rem; }
.proof-review-dialog, .rejection-dialog { width: min(94vw, 880px); max-height: 90vh; padding: 0; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); color: var(--text); box-shadow: 0 24px 80px rgba(14, 24, 38, .3); }
.rejection-dialog { width: min(94vw, 520px); }
.proof-review-dialog::backdrop, .rejection-dialog::backdrop { background: rgba(15, 24, 37, .7); }
.review-dialog__head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.15rem; border-bottom: 1px solid var(--border); }
.review-dialog__head h2 { margin: 0; font-size: 1.1rem; }
.review-dialog__close { border: 0; background: transparent; color: var(--text); font-size: 1.8rem; cursor: pointer; }
.proof-review-dialog img { display: block; width: 100%; max-height: 72vh; padding: 1rem; object-fit: contain; background: var(--surface-muted); }
.rejection-dialog form { display: grid; gap: 1rem; padding: 1.15rem; }
.rejection-dialog textarea { min-height: 110px; resize: vertical; }
.rejection-dialog__actions { display: flex; justify-content: flex-end; gap: .65rem; }
.rejection-dialog__actions .btn { min-width: 0; }
@media (max-width: 620px) {
    .payment-review-header { align-items: stretch; }
    .pending-review-count { width: 100%; }
    .payment-review-card__body { grid-template-columns: 1fr; }
    .payment-proof-thumb { width: 100%; height: 210px; }
    .payment-review-card__actions { grid-template-columns: 1fr; }
    .payment-review-card__actions .proof-action { grid-column: auto; }
    .payment-review-card__head { flex-wrap: wrap; }
    .payment-review-card__actions form, .payment-review-card__actions .btn { width: 100%; min-width: 0; }
    .rejection-dialog__actions { flex-wrap: wrap; }
    .rejection-dialog__actions .btn { flex: 1 1 9rem; }
}
</style>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('GCash Payment Review', 'Pending submissions appear first so payment checks can be completed quickly.', 'transactions', 'Transaction Management', static function () use ($pendingCount): void { ?><div class="pending-review-count" aria-label="<?= e((string) $pendingCount) ?> pending GCash payments"><strong><?= e((string) $pendingCount) ?></strong><span>Pending Verification</span></div><?php }, 'payment-review-header'); ?>

            <div class="page-panel">
                <form method="get" class="toolbar">
                    <input type="hidden" name="filter" value="<?= e($quickFilter) ?>">
                    <input type="search" name="search" aria-label="Search transactions" placeholder="Search order, customer, or GCash reference" value="<?= e($search) ?>">
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="btn btn-ghost" href="<?= e(url('manager/transactions.php?filter=' . rawurlencode($quickFilter))) ?>">Clear</a>
                </form>
                <nav class="transaction-filter-tabs" aria-label="Quick transaction filters">
                    <?php foreach ($quickFilters as $filter => $label): ?>
                        <?php $filterQuery = http_build_query(array_filter(['filter' => $filter, 'search' => $search])); ?>
                        <a class="<?= $quickFilter === $filter ? 'is-active' : '' ?>" href="<?= e(url('manager/transactions.php?' . $filterQuery)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <?php if ($reviewQueue !== []): ?>
                <div class="section__heading"><div><h2>Pending Review Queue</h2><p>Check the reference and full proof before deciding.</p></div></div>
                <div class="payment-review-grid">
                    <?php foreach ($reviewQueue as $transaction): ?>
                        <?php $proofUrl = !empty($transaction['proof_of_payment_path']) ? protected_upload_url('payment_proof', (int) $transaction['id']) : ''; ?>
                        <article class="payment-review-card <?= priority_item_class($transaction) ?>" <?= priority_data_attr($transaction) ?>>
                            <header class="payment-review-card__head">
                                <div><span class="eyebrow">Order Reference</span><h2><?= e($transaction['reference_no']) ?></h2></div>
                                <div class="priority-stack"><?= priority_badge($transaction, 'transaction') ?><?= transaction_payment_badge($transaction) ?></div>
                            </header>
                            <div class="payment-review-card__body">
                                <?php if ($proofUrl !== ''): ?>
                                    <button type="button" class="payment-proof-thumb" data-proof-open data-proof-url="<?= e($proofUrl) ?>" data-proof-title="<?= e('Payment proof · ' . $transaction['reference_no']) ?>" aria-label="Open full payment proof for <?= e($transaction['reference_no']) ?>">
                                        <img src="<?= e($proofUrl) ?>" alt="Payment proof thumbnail for <?= e($transaction['reference_no']) ?>">
                                    </button>
                                <?php else: ?>
                                    <div class="payment-proof-thumb"><span class="badge badge-danger">Proof missing</span></div>
                                <?php endif; ?>
                                <dl class="payment-review-meta">
                                    <div><dt>Customer</dt><dd><?= e($transaction['customer_name']) ?></dd></div>
                                    <div><dt>Amount</dt><dd><?= e(currency((float) $transaction['amount'])) ?></dd></div>
                                    <div><dt>GCash Reference</dt><dd><?= e($transaction['gcash_reference'] ?: 'Not provided') ?></dd></div>
                                    <div><dt>Submitted</dt><dd><?= e(format_display_datetime($transaction['created_at'] ?? null)) ?></dd></div>
                                </dl>
                            </div>
                            <div class="payment-review-card__actions">
                                <?php if ($proofUrl !== '' && !empty($transaction['gcash_reference'])): ?>
                                <form method="post" data-approve-form data-confirm-message="Approve this GCash payment and mark it as Paid?" data-confirm-title="Approve Payment" data-confirm-label="Approve Payment" data-confirm-variant="warning">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="verify_payment">
                                    <input type="hidden" name="transaction_id" value="<?= e((string) $transaction['id']) ?>">
                                    <button class="btn btn-primary" type="submit">Approve Payment</button>
                                </form>
                                <button class="btn btn-danger" type="button" data-reject-open data-transaction-id="<?= e((string) $transaction['id']) ?>" data-order-reference="<?= e($transaction['reference_no']) ?>">Reject Payment</button>
                                <?php else: ?>
                                    <span class="badge badge-warning">Waiting for proof and reference</span>
                                <?php endif; ?>
                                <?php if ($proofUrl !== ''): ?>
                                    <button class="btn btn-secondary proof-action" type="button" data-proof-open data-proof-url="<?= e($proofUrl) ?>" data-proof-title="<?= e('Payment proof · ' . $transaction['reference_no']) ?>">View Full Proof</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($quickFilter === 'pending'): ?>
                <div class="page-panel"><div class="empty-state"><h2>No pending GCash payments</h2><p>New payment submissions will appear here automatically.</p></div></div>
            <?php endif; ?>

            <div class="page-panel transaction-history">
                <div class="card-head"><div><h2>Transaction History</h2><p>Approved, rejected, cash, and other completed reviews.</p></div></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Reference</th><th>Customer</th><th>Method</th><th>Amount</th><th>GCash Reference</th><th>Status</th><th>Reviewed By</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($historyTransactions as $transaction): ?>
                            <tr<?= priority_item_attrs($transaction) ?>>
                                <td data-label="Reference"><?= e($transaction['reference_no']) ?><?= priority_badge($transaction, 'transaction') ?></td>
                                <td data-label="Customer"><?= e($transaction['customer_name']) ?></td>
                                <td data-label="Method"><?= e($transaction['payment_method'] === 'gcash' ? 'GCash' : 'Cash') ?></td>
                                <td data-label="Amount"><?= e(currency((float) $transaction['amount'])) ?></td>
                                <td data-label="GCash Reference"><?= e($transaction['gcash_reference'] ?: '—') ?></td>
                                <td data-label="Status"><?= transaction_payment_badge($transaction) ?><?php if (!empty($transaction['rejection_reason'])): ?><br><small><?= e($transaction['rejection_reason']) ?></small><?php endif; ?></td>
                                <td data-label="Reviewed By"><?= e($transaction['verifier_name'] ?? '—') ?></td>
                                <td data-label="Date"><?= e(format_display_datetime($transaction['reviewed_at'] ?: $transaction['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($historyTransactions === []): ?><tr><td colspan="8">No transactions match this filter.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<dialog class="proof-review-dialog" data-proof-dialog aria-labelledby="proof-dialog-title">
    <div class="review-dialog__head"><h2 id="proof-dialog-title" data-proof-dialog-title>Payment Proof</h2><button class="review-dialog__close" type="button" data-proof-close aria-label="Close proof">&times;</button></div>
    <img src="" alt="Full GCash payment proof" data-proof-dialog-image>
</dialog>

<dialog class="rejection-dialog" data-rejection-dialog aria-labelledby="rejection-dialog-title">
    <div class="review-dialog__head"><h2 id="rejection-dialog-title">Reject GCash Payment</h2><button class="review-dialog__close" type="button" data-reject-close aria-label="Close rejection form">&times;</button></div>
    <form method="post" data-rejection-form data-confirm-message="Reject this GCash payment and notify the customer?" data-confirm-title="Reject Payment" data-confirm-label="Reject Payment" data-confirm-variant="danger" data-confirm-sensitive="true">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_payment">
        <input type="hidden" name="transaction_id" value="" data-rejection-transaction-id>
        <p>Reject payment for <strong data-rejection-reference></strong>? The customer will be notified.</p>
        <label>Rejection Reason<textarea name="rejection_reason" required maxlength="1000" placeholder="Explain why the reference or proof could not be accepted"></textarea></label>
        <div class="rejection-dialog__actions"><button class="btn btn-ghost" type="button" data-reject-close>Cancel</button><button class="btn btn-danger" type="submit">Confirm Rejection</button></div>
    </form>
</dialog>

<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
