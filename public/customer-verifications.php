<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_login(['administrator', 'manager', 'staff']);

$viewer = current_user();
$canManageVerifications = can_manage_customer_verifications($viewer);
$statusOptions = [
    'pending_verification' => 'Pending Verification',
    'active' => 'Approved',
    'rejected' => 'Rejected',
    'all' => 'All Verification Records',
];
$statusFilter = (string) request('status', 'pending_verification');
$statusFilter = array_key_exists($statusFilter, $statusOptions) ? $statusFilter : 'pending_verification';
$search = table_search_value();

if (is_post()) {
    $customerId = (int) request('customer_id');
    $decision = (string) request('decision');
    $notes = trim((string) request('verification_notes'));

    try {
        if (!$canManageVerifications) {
            throw new InvalidArgumentException('You do not have permission to review customer registrations.');
        }

        review_customer_registration($customerId, $decision, (int) $viewer['id'], $notes);
        flash('verifications', $decision === 'approve' ? 'Customer registration approved.' : 'Customer registration rejected.', 'success');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('verifications', $exception->getMessage(), 'danger');
    }

    $redirectParams = ['status' => $statusFilter];
    if ($search !== '') {
        $redirectParams['search'] = $search;
    }

    redirect('customer-verifications.php?' . http_build_query($redirectParams));
}

$verificationPriorityContext = [
    'role' => (string) ($viewer['role'] ?? ''),
    'user_id' => (int) ($viewer['id'] ?? 0),
    'can_act' => $canManageVerifications,
];
$customers = fetch_customer_verifications($statusFilter, $search, $verificationPriorityContext);
$pendingCount = count(fetch_customer_verifications('pending_verification', '', $verificationPriorityContext));
$approvedCount = count(fetch_customer_verifications('active', '', $verificationPriorityContext));
$rejectedCount = count(fetch_customer_verifications('rejected', '', $verificationPriorityContext));

$pageTitle = 'Customer Verification';
$bodyClass = 'verification-page';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Customer Registration Verification', 'Review newly registered customers, inspect their uploaded valid ID/photo, and approve or reject account access.', 'shield-check', 'Account Verification'); ?>

            <div class="metric-grid verification-metrics">
                <article class="metric-box"><div class="metric-box__icon orange">P</div><strong><?= e((string) $pendingCount) ?></strong><span>Pending Verification</span></article>
                <article class="metric-box"><div class="metric-box__icon green">A</div><strong><?= e((string) $approvedCount) ?></strong><span>Approved Customers</span></article>
                <article class="metric-box"><div class="metric-box__icon red">R</div><strong><?= e((string) $rejectedCount) ?></strong><span>Rejected Registrations</span></article>
            </div>

            <div class="page-panel verification-records-panel">
                <form method="get" class="toolbar verification-filters" aria-label="Filter customer verification records">
                    <label class="verification-filter-field verification-filter-field--search">
                        <span>Search customers</span>
                        <input type="search" name="search" placeholder="Name, email, phone, or address" value="<?= e($search) ?>">
                    </label>
                    <label class="verification-filter-field">
                        <span>Status</span>
                        <select name="status">
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">Filter Verification Records</button>
                    <a class="btn btn-ghost" href="<?= e(url('customer-verifications.php')) ?>">Reset</a>
                </form>

                <div class="verification-results-heading">
                    <strong>Verification records</strong>
                    <span><?= e((string) count($customers)) ?> <?= count($customers) === 1 ? 'result' : 'results' ?></span>
                </div>

                <div class="table-responsive verification-table-scroll">
                    <table class="data-table verification-table">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Contact</th>
                                <th scope="col">Address</th>
                                <th scope="col">Valid ID</th>
                                <th scope="col">Status</th>
                                <th scope="col">Verifier</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $validIdUrl = !empty($customer['valid_id_path']) ? protected_upload_url('valid_id', (int) $customer['id']) : '';
                                $extension = strtolower(pathinfo((string) ($customer['valid_id_path'] ?? ''), PATHINFO_EXTENSION));
                                $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                                $isPendingReview = ($customer['status'] ?? '') === 'pending_verification'
                                    && ($customer['verification_status'] ?? '') === 'pending';
                                $canDecide = $canManageVerifications && $isPendingReview && $validIdUrl !== '';
                                $registeredAtLabel = format_display_datetime($customer['created_at'] ?? null, '', 'g:i A');
                                $updatedAtLabel = format_display_datetime($customer['updated_at'] ?? $customer['created_at'] ?? null, '', 'g:i A');
                                $reviewedAtLabel = !empty($customer['verification_reviewed_at'])
                                    ? format_display_datetime($customer['verification_reviewed_at'], '', 'g:i A')
                                    : 'Not reviewed';
                                $reviewerName = !empty($customer['verification_reviewed_name'])
                                    ? (string) $customer['verification_reviewed_name']
                                    : 'Not reviewed';
                                $reviewerRole = !empty($customer['verification_reviewed_role'])
                                    ? role_label((string) $customer['verification_reviewed_role'])
                                    : 'Not assigned';
                                ?>
                                <tr<?= priority_item_attrs($customer) ?>>
                                    <td data-label="Customer">
                                        <div class="verification-customer-cell">
                                            <strong class="verification-customer-name"><?= e($customer['full_name']) ?></strong>
                                            <?= priority_badge($customer, 'customer_verification') ?>
                                            <span class="verification-meta">Registered <?= e($registeredAtLabel) ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Contact">
                                        <div class="verification-contact-cell">
                                            <span class="verification-email"><?= e($customer['email']) ?></span>
                                            <span class="verification-meta"><?= e($customer['phone']) ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Address"><p class="verification-address"><?= e($customer['address']) ?></p></td>
                                    <td data-label="Valid ID">
                                        <?php if ($validIdUrl !== ''): ?>
                                            <a class="verification-id-link" href="<?= e($validIdUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                <?php if ($isImage): ?>
                                                    <img class="verification-id-preview" src="<?= e($validIdUrl) ?>" alt="Uploaded valid ID for <?= e($customer['full_name']) ?>">
                                                <?php endif; ?>
                                                <span>View Valid ID</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Missing ID</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status"><?= status_badge((string) $customer['status'] === 'active' ? 'approved' : (string) $customer['status']) ?></td>
                                    <td data-label="Verifier">
                                        <div class="verification-verifier-cell">
                                            <?php if (!empty($customer['verification_reviewed_at'])): ?>
                                                <strong><?= e((string) ($customer['verification_reviewed_name'] ?? 'Unknown')) ?></strong>
                                                <span class="verification-meta"><?= e(role_label((string) ($customer['verification_reviewed_role'] ?? ''))) ?></span>
                                                <span class="verification-meta">Reviewed <?= e(format_display_datetime($customer['verification_reviewed_at'], '', 'g:i A')) ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-muted">Not reviewed</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="verification-actions verification-row-actions">
                                            <div class="verification-action-controls <?= $canDecide ? '' : 'verification-action-controls--single' ?>">
                                                <button
                                                    class="btn btn-secondary btn-sm"
                                                    type="button"
                                                    data-verification-details-open
                                                    data-customer-id="<?= e((string) $customer['id']) ?>"
                                                    data-customer-name="<?= e($customer['full_name']) ?>"
                                                    data-customer-email="<?= e($customer['email']) ?>"
                                                    data-customer-phone="<?= e($customer['phone']) ?>"
                                                    data-customer-address="<?= e($customer['address']) ?>"
                                                    data-account-status="<?= e(customer_verification_status_label((string) $customer['status'])) ?>"
                                                    data-verification-status="<?= e(ucwords(str_replace('_', ' ', (string) ($customer['verification_status'] ?? 'pending')))) ?>"
                                                    data-registered-at="<?= e($registeredAtLabel) ?>"
                                                    data-updated-at="<?= e($updatedAtLabel) ?>"
                                                    data-reviewer-name="<?= e($reviewerName) ?>"
                                                    data-reviewer-role="<?= e($reviewerRole) ?>"
                                                    data-reviewed-at="<?= e($reviewedAtLabel) ?>"
                                                    data-verification-notes="<?= e((string) ($customer['verification_notes'] ?? '')) ?>"
                                                    data-valid-id-url="<?= e($validIdUrl) ?>"
                                                    data-valid-id-is-image="<?= $isImage ? 'true' : 'false' ?>"
                                                    data-can-decide="<?= $canDecide ? 'true' : 'false' ?>"
                                                >View Details</button>

                                                <?php if ($canDecide): ?>
                                                <form method="post" class="inline-form" data-confirm-message="Approve this customer registration and activate the account?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="customer_id" value="<?= e((string) $customer['id']) ?>">
                                                    <input type="hidden" name="decision" value="approve">
                                                    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                                    <input type="hidden" name="search" value="<?= e($search) ?>">
                                                    <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($canDecide): ?>
                                                <form method="post" class="stack-form verification-reject-form" data-confirm-message="Reject this customer registration?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="customer_id" value="<?= e((string) $customer['id']) ?>">
                                                    <input type="hidden" name="decision" value="reject">
                                                    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                                    <input type="hidden" name="search" value="<?= e($search) ?>">
                                                    <label class="sr-only" for="verification-notes-<?= e((string) $customer['id']) ?>">Reason for rejecting <?= e($customer['full_name']) ?></label>
                                                    <input id="verification-notes-<?= e((string) $customer['id']) ?>" type="text" name="verification_notes" placeholder="Reason for rejection" minlength="3" maxlength="1000" required>
                                                    <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                                </form>
                                            <?php elseif ($isPendingReview && $validIdUrl === ''): ?>
                                                <span class="badge badge-danger">Valid ID required</span>
                                            <?php elseif (!$canManageVerifications || $validIdUrl === ''): ?>
                                                <span class="badge badge-muted">No action available</span>
                                            <?php endif; ?>

                                            <?php if (!empty($customer['verification_notes'])): ?>
                                                <span class="verification-action-note"><?= e((string) $customer['verification_notes']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($customers === []): ?>
                                <tr class="verification-empty-row"><td colspan="7">No customer verification records found. Adjust the search or status filter and try again.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <dialog class="notification-detail-modal verification-detail-modal" data-verification-details-dialog aria-labelledby="verification-detail-title">
            <div class="notification-detail-modal__card verification-detail-modal__card">
                <button class="notification-detail-modal__close" type="button" data-verification-details-close aria-label="Close customer verification details">&times;</button>
                <span class="eyebrow">Customer Verification</span>
                <h2 id="verification-detail-title" data-verification-detail-name>Customer Details</h2>

                <div class="verification-detail-sections">
                    <section class="verification-detail-section">
                        <h3>Customer Information</h3>
                        <dl class="notification-detail-modal__meta">
                            <div><dt>Full Name</dt><dd data-verification-detail-full-name>-</dd></div>
                            <div><dt>Registration Date</dt><dd data-verification-detail-registered>-</dd></div>
                            <div><dt>Account Status</dt><dd data-verification-detail-account-status>-</dd></div>
                            <div><dt>Record Updated</dt><dd data-verification-detail-updated>-</dd></div>
                        </dl>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Contact Information</h3>
                        <dl class="notification-detail-modal__meta">
                            <div><dt>Email</dt><dd data-verification-detail-email>-</dd></div>
                            <div><dt>Phone</dt><dd data-verification-detail-phone>-</dd></div>
                        </dl>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Address</h3>
                        <p data-verification-detail-address>-</p>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Identity Verification Status</h3>
                        <dl class="notification-detail-modal__meta">
                            <div><dt>Current Status</dt><dd><span class="badge badge-muted" data-verification-detail-status>-</span></dd></div>
                            <div class="verification-detail-wide"><dt>Verification Notes</dt><dd data-verification-detail-notes>No notes recorded.</dd></div>
                        </dl>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Reviewer Information</h3>
                        <dl class="notification-detail-modal__meta">
                            <div><dt>Reviewer</dt><dd data-verification-detail-reviewer>-</dd></div>
                            <div><dt>Reviewer Role</dt><dd data-verification-detail-reviewer-role>-</dd></div>
                            <div class="verification-detail-wide"><dt>Decision Date</dt><dd data-verification-detail-reviewed-at>-</dd></div>
                        </dl>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Valid ID</h3>
                        <div class="verification-detail-id">
                            <img data-verification-detail-id-image alt="" hidden>
                            <a class="btn btn-ghost btn-sm" data-verification-detail-id-link href="#" target="_blank" rel="noopener noreferrer" hidden>View Valid ID</a>
                            <span class="badge badge-danger" data-verification-detail-id-missing hidden>Missing ID</span>
                        </div>
                    </section>

                    <section class="verification-detail-section">
                        <h3>Review Actions</h3>
                        <div class="verification-actions verification-detail-actions">
                            <form method="post" class="inline-form" data-verification-detail-approve data-confirm-message="Approve this customer registration and activate the account?" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="customer_id" value="">
                                <input type="hidden" name="decision" value="approve">
                                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                <input type="hidden" name="search" value="<?= e($search) ?>">
                                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                            </form>
                            <form method="post" class="stack-form verification-reject-form" data-verification-detail-reject data-confirm-message="Reject this customer registration?" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="customer_id" value="">
                                <input type="hidden" name="decision" value="reject">
                                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                <input type="hidden" name="search" value="<?= e($search) ?>">
                                <label class="sr-only" for="verification-detail-rejection-notes">Reason for rejection</label>
                                <input id="verification-detail-rejection-notes" type="text" name="verification_notes" placeholder="Reason for rejection" minlength="3" maxlength="1000" required>
                                <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                            </form>
                            <span class="badge badge-muted" data-verification-detail-no-action hidden>No action available</span>
                        </div>
                    </section>
                </div>
            </div>
        </dialog>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
