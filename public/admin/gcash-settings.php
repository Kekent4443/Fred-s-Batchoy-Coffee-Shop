<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$viewer = current_user();
$editId = max(0, (int) request('edit'));
$gcashFormErrors = [];

if (is_post()) {
    $action = (string) post_scalar('action', 'save_account');
    try {
        if ($action === 'delete_account') {
            delete_gcash_account($viewer ?? [], max(0, (int) post_scalar('account_id', 0)));
            flash('gcash_settings', 'GCash payment account deleted successfully.', 'success');
            redirect('admin/gcash-settings.php');
        }
        if ($action !== 'save_account') {
            throw new InvalidArgumentException('The requested GCash account action is not supported.');
        }

        $savedId = save_gcash_account(
            $viewer ?? [],
            [
                'id' => post_scalar('account_id', 0),
                'account_name' => post_scalar('account_name', ''),
                'account_number' => post_scalar('account_number', ''),
                'status' => post_scalar('status', 'active'),
                'remove_qr' => post_scalar('remove_qr', ''),
            ],
            $_FILES['qr_code'] ?? null
        );
        flash('gcash_settings', 'GCash payment account saved successfully.', 'success');
        redirect('admin/gcash-settings.php?edit=' . $savedId);
    } catch (InvalidArgumentException $exception) {
        $gcashFormErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        flash('gcash_settings', $exception->getMessage(), 'danger');
        if ($action === 'delete_account') {
            redirect('admin/gcash-settings.php');
        }
        $editId = max(0, (int) post_scalar('account_id', 0));
    } catch (Throwable $exception) {
        error_log('GCash account update failed: ' . $exception->getMessage());
        flash('gcash_settings', 'Unable to update the GCash payment settings.', 'danger');
        $gcashFormErrors['form'] = 'Unable to update the GCash payment settings.';
        if ($action === 'delete_account') {
            redirect('admin/gcash-settings.php');
        }
    }

    if ($action === 'save_account' && $gcashFormErrors !== [] && !empty($_FILES['qr_code']['name'])) {
        $gcashFormErrors['qr_code'] = 'For security, select the QR code image again before resubmitting.';
    }
}

$accounts = fetch_gcash_accounts();
$activeAccountCount = count(array_filter($accounts, static fn (array $account): bool => ($account['status'] ?? '') === 'active'));
$editAccount = $editId > 0 ? fetch_gcash_account($editId) : null;
$formAccount = [
    'id' => (int) ($editAccount['id'] ?? 0),
    'account_name' => (string) request('account_name', $editAccount['account_name'] ?? ''),
    'account_number' => (string) request('account_number', $editAccount['account_number'] ?? ''),
    'status' => (string) request('status', $editAccount['status'] ?? 'active'),
    'qr_code_path' => (string) ($editAccount['qr_code_path'] ?? ''),
];

$pageTitle = 'GCash Payment Settings';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('GCash Payment Settings', 'Manage the recipient account shown to customers during GCash checkout.', 'wallet', 'System Settings', $editAccount ? static function (): void { ?><a class="btn btn-secondary" href="<?= e(url('admin/gcash-settings.php')) ?>">Add Another Account</a><?php } : null); ?>

            <div class="gcash-settings-layout">
                <div class="page-panel" id="gcash-account-form">
                    <div class="card-head"><h2><?= $editAccount ? 'Edit GCash Account' : 'Add GCash Account' ?></h2></div>
                    <form method="post" enctype="multipart/form-data" class="stack-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_account">
                        <input type="hidden" name="account_id" value="<?= e((string) $formAccount['id']) ?>">
                        <label>GCash Account Name
                            <input type="text" name="account_name" maxlength="150" required value="<?= e($formAccount['account_name']) ?>" autocomplete="name"<?= form_error_attributes($gcashFormErrors, 'account_name', 'gcash') ?>>
                            <?= form_field_error($gcashFormErrors, 'account_name', 'gcash') ?>
                        </label>
                        <label>GCash Number
                            <input type="tel" name="account_number" maxlength="20" required value="<?= e($formAccount['account_number']) ?>" placeholder="09171234567" inputmode="tel"<?= form_error_attributes($gcashFormErrors, 'account_number', 'gcash') ?>>
                            <small>Use a Philippine mobile number such as 09171234567 or +639171234567.</small>
                            <?= form_field_error($gcashFormErrors, 'account_number', 'gcash') ?>
                        </label>
                        <?php if ($editAccount): ?>
                            <label>Status
                                <select name="status" required>
                                    <option value="active" <?= $formAccount['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $formAccount['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label>QR Code Image <small>(optional)</small>
                            <input type="file" name="qr_code" accept="image/png,image/jpeg,image/webp"<?= form_error_attributes($gcashFormErrors, 'qr_code', 'gcash') ?>>
                            <small>Optional JPG, PNG, or WEBP image, up to 5MB.</small>
                            <?= form_field_error($gcashFormErrors, 'qr_code', 'gcash') ?>
                        </label>
                        <?php if ($formAccount['qr_code_path'] !== ''): ?>
                            <div class="gcash-qr-preview">
                                <img src="<?= e(public_file_url($formAccount['qr_code_path'])) ?>" alt="Current GCash QR code">
                                <label class="toggle-row"><input type="checkbox" name="remove_qr" value="1"> Remove current QR code</label>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit"><?= $editAccount ? 'Update GCash Account' : 'Save GCash Account' ?></button>
                    </form>
                </div>

                <div class="page-panel gcash-accounts-panel">
                    <div class="card-head gcash-accounts-head">
                        <div>
                            <h2>Saved Accounts</h2>
                            <p class="gcash-availability-summary">
                                <?php if ($activeAccountCount > 0): ?>
                                    GCash checkout is available. <?= e((string) $activeAccountCount) ?> active account<?= $activeAccountCount === 1 ? '' : 's' ?> configured.
                                <?php else: ?>
                                    GCash checkout is unavailable until an account is activated.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table user-management-table gcash-settings-table">
                            <thead><tr><th>Account</th><th>Number</th><th>Status</th><th>QR Code</th><th>Updated</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <?php $deletionAssessment = gcash_account_deletion_assessment($account, $accounts); ?>
                                <tr>
                                    <td data-label="Account"><strong><?= e((string) $account['account_name']) ?></strong></td>
                                    <td data-label="Number"><span class="gcash-account-number"><?= e(format_gcash_number((string) $account['account_number'])) ?></span></td>
                                    <td data-label="Status"><span class="badge <?= $account['status'] === 'active' ? 'badge-success' : 'badge-muted' ?>"><?= e(ucfirst((string) $account['status'])) ?></span></td>
                                    <td data-label="QR Code"><?= !empty($account['qr_code_path']) ? '<span class="badge badge-success">Available</span>' : '<span class="badge badge-muted">None</span>' ?></td>
                                    <td data-label="Updated"><small class="gcash-updated-meta"><?= e(format_display_datetime($account['updated_at'] ?? null)) ?><span>by <?= e((string) $account['updated_by_name']) ?></span></small></td>
                                    <td data-label="Actions">
                                        <div class="table-actions user-actions-row gcash-account-actions">
                                            <span class="user-action-item user-action-item--edit">
                                                <a class="btn btn-icon btn-edit-icon" href="<?= e(url('admin/gcash-settings.php?edit=' . (int) $account['id'] . '#gcash-account-form')) ?>" aria-label="Edit GCash Account" title="Edit GCash Account">
                                                    <svg class="edit-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                                    </svg>
                                                    <span class="sr-only">Edit GCash Account</span>
                                                </a>
                                                <span class="user-action-label" aria-hidden="true">Edit</span>
                                            </span>
                                            <form method="post" class="inline-form inline-form--icon user-action-item user-action-item--delete" data-confirm-message="Delete this GCash account? This action cannot be undone." data-confirm-title="Delete GCash Account" data-confirm-label="Delete Permanently" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_account">
                                                <input type="hidden" name="account_id" value="<?= e((string) $account['id']) ?>">
                                                <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete GCash Account" title="<?= e($deletionAssessment['allowed'] ? 'Delete GCash Account' : (string) $deletionAssessment['reason']) ?>" <?= $deletionAssessment['allowed'] ? '' : 'disabled' ?>>
                                                    <svg class="trash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="M3 6h18"></path>
                                                        <path d="M8 6V4h8v2"></path>
                                                        <path d="M19 6l-1 14H6L5 6"></path>
                                                        <path d="M10 11v5M14 11v5"></path>
                                                    </svg>
                                                    <span class="sr-only"><?= e($deletionAssessment['allowed'] ? 'Delete GCash Account' : (string) $deletionAssessment['reason']) ?></span>
                                                </button>
                                                <span class="user-action-label" aria-hidden="true">Delete</span>
                                            </form>
                                        </div>
                                        <?php if (!$deletionAssessment['allowed']): ?>
                                            <small class="gcash-delete-reason"><?= e((string) $deletionAssessment['reason']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($accounts === []): ?><tr><td colspan="6">No GCash account has been configured yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
