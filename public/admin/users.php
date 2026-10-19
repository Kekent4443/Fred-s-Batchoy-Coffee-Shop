<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$currentUser = current_user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$userStatusOptions = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'pending_verification' => 'Pending Verification',
    'rejected' => 'Rejected',
];
$userFormErrors = [];
$submittedUser = null;

if (is_post() && post_scalar('action') === 'add_user') {
    $submittedUser = [
        'full_name' => trim((string) post_scalar('full_name', '')),
        'email' => trim((string) post_scalar('email', '')),
        'phone' => trim((string) post_scalar('phone', '')),
        'address' => trim((string) post_scalar('address', '')),
        'role' => (string) post_scalar('role', ''),
        'status' => 'active',
    ];
    $password = (string) post_scalar('password', '');
    $confirmPassword = (string) post_scalar('confirm_password', '');
    if ($password !== $confirmPassword) {
        $userFormErrors['confirm_password'] = 'Passwords do not match.';
        flash('users', 'Passwords do not match.', 'danger');
    } else {
        try {
            create_managed_user($submittedUser + ['password' => $password]);
            flash('users', 'User account added successfully.', 'success');
            redirect('admin/users.php');
        } catch (InvalidArgumentException $exception) {
            $field = validation_error_field($exception->getMessage());
            $userFormErrors[$field] = $exception->getMessage();
            if (in_array($field, ['email', 'phone'], true)) {
                $submittedUser[$field] = '';
            }
            flash('users', $exception->getMessage(), 'danger');
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() === 1062) {
                $field = duplicate_error_field($exception, 'email');
                $message = $field === 'phone' ? 'This phone number is already registered.' : 'Email address is already registered.';
                $userFormErrors[$field] = $message;
                $submittedUser[$field] = '';
            } else {
                $message = 'Unable to add the user account right now.';
                $userFormErrors['form'] = $message;
            }
            flash('users', $message, 'danger');
        } catch (RuntimeException $exception) {
            $userFormErrors['form'] = 'Unable to add the user account right now.';
            flash('users', 'Unable to add the user account right now.', 'danger');
        }
    }
}

if (is_post() && post_scalar('action') === 'update_user') {
    $targetUserId = filter_var(post_scalar('user_id', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $originalUser = $targetUserId !== false ? fetch_user_by_id((int) $targetUserId) : null;
    $submittedUser = [
        'id' => $originalUser['id'] ?? 0,
        'full_name' => trim((string) post_scalar('full_name', '')),
        'email' => trim((string) post_scalar('email', '')),
        'phone' => trim((string) post_scalar('phone', '')),
        'address' => trim((string) post_scalar('address', '')),
        'role' => (string) post_scalar('role', ''),
        'status' => (string) post_scalar('status', ''),
        'password' => (string) post_scalar('password', ''),
    ];
    try {
        if (!$originalUser) {
            throw new InvalidArgumentException('User account does not exist.');
        }
        update_user_account((int) $submittedUser['id'], $submittedUser);
        flash('users', 'User account updated successfully.', 'success');
        redirect('admin/users.php');
    } catch (InvalidArgumentException $exception) {
        $field = validation_error_field($exception->getMessage());
        if ($field === 'password') {
            $field = 'form';
        }
        $userFormErrors[$field] = $exception->getMessage();
        if (in_array($field, ['email', 'phone'], true)) {
            $submittedUser[$field] = '';
        }
        flash('users', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() === 1062) {
            $field = duplicate_error_field($exception, 'email');
            $message = $field === 'phone' ? 'This phone number is already registered.' : 'Email address is already registered.';
            $userFormErrors[$field] = $message;
            $submittedUser[$field] = '';
        } else {
            $message = 'Unable to update the user account right now.';
            $userFormErrors['form'] = $message;
        }
        flash('users', $message, 'danger');
    } catch (RuntimeException $exception) {
        $userFormErrors['form'] = 'Unable to update the user account right now.';
        flash('users', 'Unable to update the user account right now.', 'danger');
    }
    unset($submittedUser['password']);
    if ($originalUser) {
        // A forged role/status must not turn the error form into a different account editor.
        $submittedUser['role'] = in_array($submittedUser['role'], assignable_account_roles(), true)
            && $originalUser['role'] !== 'administrator' ? $submittedUser['role'] : $originalUser['role'];
        $allowedFormStatuses = $submittedUser['role'] === 'customer'
            ? ['active', 'inactive', 'pending_verification', 'rejected']
            : ['active', 'inactive'];
        if (!in_array($submittedUser['status'], $allowedFormStatuses, true)) {
            $submittedUser['status'] = in_array($originalUser['status'], $allowedFormStatuses, true)
                ? $originalUser['status'] : 'inactive';
        }
    } else {
        $submittedUser = null;
    }
}

if (is_post() && post_scalar('action') === 'reset_password') {
    $targetUserId = filter_var(post_scalar('user_id', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if ($targetUserId === false) {
            throw new InvalidArgumentException('Please select a valid user account.');
        }
        $result = request_administrator_password_reset((int) $targetUserId, $currentUserId, true);
        $status = (string) ($result['status'] ?? 'email_failed');

        if ($status === 'sent') {
            flash('users', 'A five-minute, single-use password-reset link was sent to the account owner.', 'success');
        } elseif ($status === 'deferred') {
            flash('users', 'Brevo did not accept the reset email yet. It is queued for a bounded retry; do not tell the account owner that it was sent.', 'warning');
        } elseif ($status === 'rate_limited') {
            flash('users', 'Please wait before requesting another password reset for this account.', 'warning');
        } elseif ($status === 'unavailable') {
            flash('users', 'Password reset email is unavailable because transactional email is not configured.', 'danger');
        } else {
            flash('users', 'Unable to send the password reset email. Please try again later.', 'danger');
        }
    } catch (InvalidArgumentException $exception) {
        flash('users', $exception->getMessage(), 'warning');
    } catch (Throwable $exception) {
        error_log('Administrator password-reset request failed for account #' . (int) $targetUserId . ' (' . get_class($exception) . ').');
        flash('users', 'Unable to send the password reset email. Please try again later.', 'danger');
    }

    redirect('admin/users.php');
}

if (is_post() && request('action') === 'toggle_status') {
    $targetUser = fetch_user_by_id((int) request('user_id'));
    $status = (string) request('status');

    if (!in_array($status, ['active', 'inactive'], true)) {
        flash('users', 'Please select a valid account status.', 'danger');
    } elseif ($targetUser && !empty($targetUser['deleted_at'])) {
        flash('users', 'Use Restore Account to reactivate a soft-deleted user.', 'warning');
    } elseif ($targetUser && ($targetUser['role'] ?? '') === 'administrator' && $status !== 'active') {
        flash('users', 'The primary Administrator must remain active.', 'danger');
    } elseif ((int) request('user_id') === $currentUserId && $status === 'inactive') {
        flash('users', 'You cannot deactivate the account you are currently using.', 'danger');
    } elseif ($targetUser && ($targetUser['role'] ?? '') === 'customer' && in_array((string) ($targetUser['status'] ?? ''), ['pending_verification', 'rejected'], true) && $status === 'active') {
        flash('users', 'Please use the Customer Verification panel to approve this customer account.', 'warning');
    } elseif (!$targetUser) {
        flash('users', 'User account does not exist.', 'danger');
    } else {
        try {
            // Reuse Edit User's business checks and authentication revocation.
            update_user_account((int) $targetUser['id'], [
                'full_name' => $targetUser['full_name'],
                'email' => $targetUser['email'],
                'phone' => $targetUser['phone'],
                'address' => $targetUser['address'],
                'role' => $targetUser['role'],
                'status' => $status,
            ]);
            flash('users', 'Account status updated.', 'success');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            flash('users', $exception->getMessage(), 'danger');
        } catch (mysqli_sql_exception $exception) {
            flash('users', 'Unable to update this account status right now.', 'danger');
        }
    }

    redirect('admin/users.php');
}

if (is_post() && request('action') === 'delete_user') {
    try {
        $result = conditional_delete_user_account(
            (int) request('user_id'),
            $currentUserId,
            request('confirm_permanent') === '1'
        );
        flash(
            'users',
            $result['mode'] === 'permanent'
                ? 'User account permanently deleted.'
                : 'User account deactivated using soft deletion. All linked history was preserved and the account can be restored.',
            'success'
        );
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('users', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('users', 'Unable to delete this account because it is still linked to system records.', 'danger');
    }

    redirect('admin/users.php');
}

if (is_post() && request('action') === 'restore_user') {
    try {
        restore_soft_deleted_user((int) request('user_id'), $currentUserId);
        flash('users', 'User account restored successfully.', 'success');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('users', $exception->getMessage(), 'danger');
    } catch (mysqli_sql_exception $exception) {
        flash('users', 'Unable to restore this account.', 'danger');
    }

    redirect('admin/users.php');
}

$search = table_search_value();
$roleFilter = (string) request('role_filter', '');
$roleFilter = in_array($roleFilter, all_roles(), true) ? $roleFilter : '';
$statusFilter = (string) request('status_filter', '');
$statusFilter = array_key_exists($statusFilter, $userStatusOptions) ? $statusFilter : '';
$allUsers = fetch_users(null, $search);
$users = array_values(array_filter($allUsers, static function (array $user) use ($roleFilter, $statusFilter): bool {
    if ($roleFilter !== '' && ($user['role'] ?? '') !== $roleFilter) {
        return false;
    }

    return $statusFilter === '' || ($user['status'] ?? '') === $statusFilter;
}));
$editParam = (string) request('edit', '');
$editUser = ($editParam !== '' && $editParam !== 'new') ? fetch_user_by_id((int) $editParam) : null;
if ($submittedUser !== null) {
    $editUser = $submittedUser;
}
$isEditingUser = !empty($editUser['id']);
$activeUsers = count(array_filter($users, static fn ($user) => empty($user['deleted_at']) && $user['status'] === 'active'));
$inactiveUsers = count(array_filter($users, static fn ($user) => !empty($user['deleted_at']) || $user['status'] === 'inactive'));
$admins = count(array_filter($users, static fn ($user) => empty($user['deleted_at']) && $user['role'] === 'administrator'));
$deletionAssessments = [];
foreach ($users as $listedUser) {
    if ((int) $listedUser['id'] !== $currentUserId && empty($listedUser['deleted_at'])) {
        $deletionAssessments[(int) $listedUser['id']] = user_deletion_assessment((int) $listedUser['id']);
    }
}

$pageTitle = 'User Management';
$pageStyles = ['css/admin-users.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('User Management', 'Manage your system users and permissions.', 'users', 'User Management', static function (): void { ?><a class="btn btn-primary" href="<?= e(url('admin/users.php?edit=new#user-form')) ?>">Add User</a><?php }); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue" aria-hidden="true">👤</div><strong><?= e((string) count($users)) ?></strong><span>Users Shown</span></article>
                <article class="metric-box"><div class="metric-box__icon green">✓</div><strong><?= e((string) $activeUsers) ?></strong><span>Active Users</span></article>
                <article class="metric-box"><div class="metric-box__icon red">!</div><strong><?= e((string) $inactiveUsers) ?></strong><span>Inactive / Deleted</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">A</div><strong><?= e((string) $admins) ?></strong><span>Admins</span></article>
            </div>

            <div class="page-panel">
                <form method="get" class="toolbar user-management-filters" role="search" aria-label="Filter users">
                    <label class="user-management-filters__field"><span>Search</span><input type="search" name="search" placeholder="Search name, email, or phone..." value="<?= e($search) ?>"></label>
                    <label class="user-management-filters__field"><span>Role</span><select name="role_filter">
                        <option value="">All Roles</option>
                        <?php foreach (all_roles() as $role): ?>
                            <option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label class="user-management-filters__field"><span>Status</span><select name="status_filter">
                        <option value="">All Statuses</option>
                        <?php foreach ($userStatusOptions as $status => $label): ?>
                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <button class="btn btn-primary" type="submit">Filter Users</button>
                    <button class="btn btn-secondary" type="button" data-export-table="users">Export CSV</button>
                    <a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Reset</a>
                </form>
                <p class="user-management-results" role="status"><?= count($users) ?> <?= count($users) === 1 ? 'user' : 'users' ?> shown<?= ($search !== '' || $roleFilter !== '' || $statusFilter !== '') ? ' for the current filters' : '' ?>.</p>

                <div class="table-responsive">
                    <table class="data-table searchable-table user-management-table" data-export-name="users">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Join Date</th>
                                <th data-export-exclude>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $item): ?>
                                <?php
                                $isCurrentUser = (int) $item['id'] === $currentUserId;
                                $needsVerificationReview = ($item['role'] ?? '') === 'customer'
                                    && in_array((string) ($item['status'] ?? ''), ['pending_verification', 'rejected'], true);
                                $isSoftDeleted = !empty($item['deleted_at']);
                                $canResetPassword = in_array((string) $item['status'], ['active', 'pending_verification'], true)
                                    && (($item['role'] ?? '') !== 'customer' || (int) ($item['password_reset_eligible'] ?? 0) === 1);
                                $deletionAssessment = $deletionAssessments[(int) $item['id']] ?? ['has_related' => true, 'uncertain' => true, 'relations' => []];
                                $requiresSoftDelete = $deletionAssessment['has_related'] || $deletionAssessment['uncertain'];
                                ?>
                                <tr<?= priority_item_attrs($item) ?>>
                                    <td data-label="User"><?= e($item['full_name']) ?><?= priority_badge($item, 'user') ?></td>
                                    <td data-label="Contact"><?= e($item['email']) ?><br><small><?= e($item['phone']) ?></small></td>
                                    <td data-label="Role"><?= e(role_label($item['role'])) ?></td>
                                    <td data-label="Status"><?= $isSoftDeleted ? '<span class="badge badge-danger">Soft Deleted</span>' : status_badge($item['status']) ?></td>
                                    <td data-label="Join Date"><?= e(format_display_date($item['created_at'] ?? null)) ?></td>
                                    <td data-label="Actions" data-export-exclude>
                                        <div class="table-actions<?= !$isSoftDeleted ? ' user-actions-row' : '' ?>">
                                            <?php if (!$isSoftDeleted): ?>
                                                <span class="user-action-item user-action-item--edit">
                                                    <a class="btn btn-icon btn-edit-icon" href="<?= e(url('admin/users.php?edit=' . $item['id'] . '#user-form')) ?>" aria-label="Edit User" title="Edit User">
                                                        <svg class="edit-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                            <path d="M12 20h9"></path>
                                                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                                        </svg>
                                                        <span class="sr-only">Edit User</span>
                                                    </a>
                                                    <span class="user-action-label" aria-hidden="true">Edit</span>
                                                </span>
                                                <?php if ($canResetPassword): ?>
                                                    <form method="post" class="inline-form inline-form--icon user-action-item user-action-item--reset-password" data-confirm-message="<?= e(($item['role'] ?? '') === 'customer' ? 'Send a password reset link to this customer\'s verified email address?' : 'Send a five-minute, single-use password reset link to this user\'s registered email address?') ?>" data-confirm-title="Reset Password" data-confirm-label="Send Reset Link" data-confirm-variant="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="user_id" value="<?= e((string) $item['id']) ?>">
                                                        <button class="btn btn-icon btn-reset-password-icon" type="submit" aria-label="Reset Password" title="Reset Password">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                                                <circle cx="7.5" cy="15.5" r="3.5"></circle>
                                                                <path d="m10 13 8-8m-2 2 2 2m-5 1 2 2"></path>
                                                            </svg>
                                                            <span class="sr-only">Reset Password</span>
                                                        </button>
                                                        <span class="user-action-label" aria-hidden="true">Reset Password</span>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($isCurrentUser): ?>
                                                <span class="badge badge-muted">Current Account</span>
                                            <?php elseif ($isSoftDeleted): ?>
                                                <form method="post" class="inline-form" data-confirm-message="Restore this account and allow the user to log in again?" data-confirm-title="Restore Account" data-confirm-label="Restore Account" data-confirm-variant="warning">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="restore_user">
                                                    <input type="hidden" name="user_id" value="<?= e((string) $item['id']) ?>">
                                                    <button class="btn btn-secondary btn-sm" type="submit">Restore Account</button>
                                                </form>
                                            <?php elseif ($needsVerificationReview): ?>
                                                <a class="btn btn-ghost btn-sm" href="<?= e(url('customer-verifications.php?status=' . $item['status'] . '&search=' . urlencode((string) $item['email']))) ?>">Review</a>
                                            <?php else: ?>
                                                <form method="post" class="inline-form inline-form--icon user-action-item user-action-item--status" data-confirm-message="<?= e($item['status'] === 'active' ? 'Deactivate this account and prevent the user from logging in?' : 'Activate this account and update its status to Active?') ?>" data-confirm-title="<?= e($item['status'] === 'active' ? 'Deactivate Account' : 'Activate Account') ?>" data-confirm-label="<?= e($item['status'] === 'active' ? 'Deactivate' : 'Activate') ?>" data-confirm-variant="<?= $item['status'] === 'active' ? 'danger' : 'warning' ?>"<?= $item['status'] === 'active' ? ' data-confirm-sensitive="true"' : '' ?>>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= e((string) $item['id']) ?>">
                                                    <input type="hidden" name="status" value="<?= $item['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <?php if ($item['status'] === 'active'): ?>
                                                        <button class="btn btn-icon btn-deactivate-icon" type="submit" aria-label="Deactivate Account" title="Deactivate Account">
                                                            <svg class="eye-slash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                                <circle cx="12" cy="12" r="9"></circle>
                                                                <path d="m5.6 5.6 12.8 12.8"></path>
                                                            </svg>
                                                            <span class="sr-only">Deactivate Account</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-icon btn-activate-icon" type="submit" aria-label="Activate Account" title="Activate Account">
                                                            <?= eye_icon_svg() ?>
                                                            <span class="sr-only">Activate Account</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <span class="user-action-label" aria-hidden="true"><?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?></span>
                                                </form>
                                                <form method="post" class="inline-form inline-form--icon user-action-item user-action-item--delete" data-confirm-message="<?= e($requiresSoftDelete
                                                    ? 'This user has linked records. The account will be deactivated and all history will be preserved. Continue?'
                                                    : 'This user has no existing records. Are you sure you want to permanently delete this account? This action cannot be undone.') ?>" data-confirm-title="Delete Account" data-confirm-label="<?= $requiresSoftDelete ? 'Deactivate Account' : 'Delete Permanently' ?>" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= e((string) $item['id']) ?>">
                                                    <input type="hidden" name="confirm_permanent" value="<?= $requiresSoftDelete ? '0' : '1' ?>">
                                                    <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete Account" title="Delete Account">
                                                        <svg class="trash-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                            <path d="M3 6h18"></path>
                                                            <path d="M8 6V4h8v2"></path>
                                                            <path d="M19 6l-1 14H6L5 6"></path>
                                                            <path d="M10 11v5M14 11v5"></path>
                                                        </svg>
                                                        <span class="sr-only">Delete Account</span>
                                                    </button>
                                                    <span class="user-action-label" aria-hidden="true">Delete</span>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($users === []): ?>
                                <tr><td colspan="6">No users match these filters. Try another search or reset the filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="page-panel user-account-panel" id="user-form">
                <div class="user-account-panel__heading">
                    <h2><?= $isEditingUser ? 'Update User Account' : 'Add User Account' ?></h2>
                    <?php if (!$isEditingUser): ?>
                        <p>New accounts start Active. Customer email verification remains separate and optional.</p>
                    <?php else: ?>
                        <p>Update this account's details and status. Changing its email requires the account owner to verify the new address; use Reset Password for password changes.</p>
                    <?php endif; ?>
                </div>
                <?php if (isset($userFormErrors['form'])): ?><p class="user-account-panel__error" role="alert"><?= e($userFormErrors['form']) ?></p><?php endif; ?>
                <form method="post" class="form-grid user-account-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $isEditingUser ? 'update_user' : 'add_user' ?>">
                    <?php if ($isEditingUser): ?>
                        <input type="hidden" name="user_id" value="<?= e((string) $editUser['id']) ?>">
                    <?php endif; ?>
                    <label>Full Name<input type="text" name="full_name" maxlength="150" autocomplete="name" required value="<?= e($editUser['full_name'] ?? '') ?>"<?= form_error_attributes($userFormErrors, 'full_name', 'user') ?>><?= form_field_error($userFormErrors, 'full_name', 'user') ?></label>
                    <label>Email Address<input type="email" name="email" maxlength="150" autocomplete="email" required value="<?= e($editUser['email'] ?? '') ?>"<?= form_error_attributes($userFormErrors, 'email', 'user') ?>><?= form_field_error($userFormErrors, 'email', 'user') ?></label>
                    <label>Phone Number<input type="tel" name="phone" inputmode="tel" autocomplete="tel" minlength="11" maxlength="13" pattern="^(09[0-9]{9}|\+639[0-9]{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required value="<?= e($editUser['phone'] ?? '') ?>"<?= form_error_attributes($userFormErrors, 'phone', 'user') ?>><?= form_field_error($userFormErrors, 'phone', 'user') ?></label>
                    <?php if ($isEditingUser && ($editUser['role'] ?? '') === 'administrator'): ?>
                        <label>Role
                            <input type="text" value="Administrator" readonly aria-describedby="administrator-role-help">
                            <input type="hidden" name="role" value="administrator">
                            <small id="administrator-role-help">The primary Administrator role is fixed.</small>
                        </label>
                    <?php else: ?>
                        <label>Role
                            <select name="role" required<?= form_error_attributes($userFormErrors, 'role', 'user') ?>>
                                <?php if (!$isEditingUser): ?><option value="" disabled <?= ($editUser['role'] ?? '') === '' ? 'selected' : '' ?>>Select account role</option><?php endif; ?>
                                <?php foreach (assignable_account_roles() as $role): ?>
                                    <option value="<?= e($role) ?>" <?= ($editUser['role'] ?? '') === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= form_field_error($userFormErrors, 'role', 'user') ?>
                        </label>
                    <?php endif; ?>
                    <?php if ($isEditingUser): ?>
                        <?php if (($editUser['role'] ?? '') === 'administrator'): ?>
                            <label>Status
                                <input type="text" value="Active" readonly aria-describedby="administrator-status-help">
                                <input type="hidden" name="status" value="active">
                                <small id="administrator-status-help">The primary Administrator must remain active.</small>
                            </label>
                        <?php else: ?>
                            <label>Status
                                <select name="status" required<?= form_error_attributes($userFormErrors, 'status', 'user') ?>>
                                    <?php foreach ($userStatusOptions as $status => $label): ?>
                                        <?php if (($editUser['role'] ?? '') !== 'customer' && !in_array($status, ['active', 'inactive'], true)) continue; ?>
                                        <option value="<?= e($status) ?>" <?= ($editUser['status'] ?? 'active') === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= form_field_error($userFormErrors, 'status', 'user') ?>
                            </label>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$isEditingUser): ?>
                        <label>Password<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required<?= form_error_attributes($userFormErrors, 'password', 'user') ?>><small>8–72 characters, including at least one letter and one number.</small><?= form_field_error($userFormErrors, 'password', 'user') ?></label>
                        <label>Confirm Password<input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required<?= form_error_attributes($userFormErrors, 'confirm_password', 'user') ?>><?= form_field_error($userFormErrors, 'confirm_password', 'user') ?></label>
                    <?php endif; ?>
                    <label class="form-grid__full">Address<textarea name="address" rows="3" maxlength="1000" autocomplete="street-address" required<?= form_error_attributes($userFormErrors, 'address', 'user') ?>><?= e($editUser['address'] ?? '') ?></textarea><?php if (($editUser['role'] ?? '') === 'customer'): ?><small>Provide a complete delivery address of at least 20 characters.</small><?php endif; ?><?= form_field_error($userFormErrors, 'address', 'user') ?></label>
                    <div class="form-grid__full user-account-form__actions"><button class="btn btn-primary" type="submit"><?= $isEditingUser ? 'Update User Account' : 'Create User Account' ?></button><?php if ($isEditingUser): ?><a class="btn btn-secondary" href="<?= e(url('admin/users.php#user-form')) ?>">Cancel Edit</a><?php endif; ?></div>
                </form>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
