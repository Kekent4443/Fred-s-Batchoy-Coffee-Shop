<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

$staffFormErrors = [];
$submittedStaff = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'role' => '',
];

if (is_post() && post_scalar('action') === 'add_staff') {
    $password = (string) post_scalar('password', '');
    $confirmPassword = (string) post_scalar('confirm_password', '');
    $submittedStaff = [
        'full_name' => trim((string) post_scalar('full_name', '')),
        'email' => trim((string) post_scalar('email', '')),
        'phone' => trim((string) post_scalar('phone', '')),
        'address' => trim((string) post_scalar('address', '')),
        'role' => (string) post_scalar('role', ''),
    ];

    if ($password !== $confirmPassword) {
        $staffFormErrors['confirm_password'] = 'Passwords do not match.';
        flash('staff', 'Passwords do not match.', 'danger');
    } else {
        try {
            $accountRole = require_allowed_value(
                $submittedStaff['role'],
                ['staff', 'rider'],
                'Please select Staff or Rider as the account role.'
            );
            $accountId = create_managed_user([
            'full_name' => $submittedStaff['full_name'],
            'email' => $submittedStaff['email'],
            'password' => $password,
            'phone' => $submittedStaff['phone'],
            'address' => $submittedStaff['address'],
                'role' => $accountRole,
            ]);

            log_audit(
                (int) current_user()['id'],
                $accountRole . '_created',
                'Manager created ' . $accountRole . ' account #' . $accountId
            );
            flash('staff', role_label($accountRole) . ' account added successfully.', 'success');
            redirect('manager/staff.php');
        } catch (InvalidArgumentException $exception) {
            $field = validation_error_field($exception->getMessage());
            $staffFormErrors[$field] = $exception->getMessage();
            if (in_array($field, ['email', 'phone'], true)) {
                $submittedStaff[$field] = '';
            }
            flash('staff', $exception->getMessage(), 'danger');
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() === 1062) {
                $field = duplicate_error_field($exception, 'email');
                $message = $field === 'phone' ? 'This phone number is already registered.' : 'Email address is already registered.';
                $staffFormErrors[$field] = $message;
                $submittedStaff[$field] = '';
            } else {
                $message = 'Unable to add the staff account right now.';
                $staffFormErrors['form'] = $message;
            }
            flash('staff', $message, 'danger');
        } catch (RuntimeException $exception) {
            $message = 'Unable to add the staff account right now. Please try again.';
            $staffFormErrors['form'] = $message;
            flash('staff', $message, 'danger');
        }
    }
}

$search = table_search_value();
$staffAccounts = array_merge(fetch_users('staff', $search), fetch_users('rider', $search));
usort($staffAccounts, static fn (array $left, array $right): int => strcmp(
    (string) ($right['created_at'] ?? ''),
    (string) ($left['created_at'] ?? '')
));
$pageTitle = 'Staff and Rider Accounts';
$pageStyles = ['css/admin-users.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Staff Account Management', 'Create and monitor Staff and Rider accounts.', 'users', 'Account Management', static function (): void { ?><a class="btn btn-primary" href="#add-staff">Add Staff or Rider</a><?php }); ?>

            <div class="page-panel user-account-panel staff-account-panel" id="add-staff">
                <div class="user-account-panel__heading">
                    <h2>Add Staff or Rider Account</h2>
                    <p>Create a Staff or Rider login with verified contact details. New accounts always start Active; account status remains managed through the authorized account workflow.</p>
                </div>
                <?php if (isset($staffFormErrors['form'])): ?><p class="user-account-panel__error" role="alert"><?= e($staffFormErrors['form']) ?></p><?php endif; ?>
                <form method="post" class="form-grid user-account-form staff-account-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_staff">
                    <label>Full Name<input type="text" name="full_name" maxlength="150" autocomplete="name" required value="<?= e($submittedStaff['full_name']) ?>"<?= form_error_attributes($staffFormErrors, 'full_name', 'staff') ?>><?= form_field_error($staffFormErrors, 'full_name', 'staff') ?></label>
                    <label>Email Address<input type="email" name="email" maxlength="150" autocomplete="email" required value="<?= e($submittedStaff['email']) ?>"<?= form_error_attributes($staffFormErrors, 'email', 'staff') ?>><?= form_field_error($staffFormErrors, 'email', 'staff') ?></label>
                    <label>Phone Number<input type="tel" name="phone" inputmode="tel" autocomplete="tel" minlength="11" maxlength="13" pattern="^(09[0-9]{9}|\+639[0-9]{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required value="<?= e($submittedStaff['phone']) ?>"<?= form_error_attributes($staffFormErrors, 'phone', 'staff') ?>><?= form_field_error($staffFormErrors, 'phone', 'staff') ?></label>
                    <label>Select Account Role
                        <select name="role" required<?= form_error_attributes($staffFormErrors, 'role', 'staff') ?>>
                            <option value="" disabled <?= $submittedStaff['role'] === '' ? 'selected' : '' ?>>Select Staff or Rider</option>
                            <option value="staff" <?= $submittedStaff['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="rider" <?= $submittedStaff['role'] === 'rider' ? 'selected' : '' ?>>Rider</option>
                        </select>
                        <?= form_field_error($staffFormErrors, 'role', 'staff') ?>
                    </label>
                    <label>Password<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required<?= form_error_attributes($staffFormErrors, 'password', 'staff') ?>><small>8–72 characters, including at least one letter and one number.</small><?= form_field_error($staffFormErrors, 'password', 'staff') ?></label>
                    <label>Confirm Password<input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required<?= form_error_attributes($staffFormErrors, 'confirm_password', 'staff') ?>><?= form_field_error($staffFormErrors, 'confirm_password', 'staff') ?></label>
                    <label class="form-grid__full">Address<textarea name="address" rows="3" maxlength="1000" autocomplete="street-address" required<?= form_error_attributes($staffFormErrors, 'address', 'staff') ?>><?= e($submittedStaff['address']) ?></textarea><?= form_field_error($staffFormErrors, 'address', 'staff') ?></label>
                    <div class="form-grid__full user-account-form__actions"><button class="btn btn-primary" type="submit">Create Staff or Rider Account</button></div>
                </form>
            </div>

            <div class="page-panel page-panel--spaced">
                <form method="get" class="toolbar">
                    <input type="search" name="search" placeholder="Search Staff or Rider account" value="<?= e($search) ?>">
                    <button class="btn btn-secondary" type="submit">Search Accounts</button>
                </form>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staffAccounts as $staff): ?>
                                <tr<?= priority_item_attrs($staff) ?>>
                                    <td><?= e($staff['full_name']) ?><?= priority_badge($staff, 'user') ?></td>
                                    <td><?= e($staff['email']) ?></td>
                                    <td><?= e($staff['phone']) ?></td>
                                    <td><?= e(role_label((string) $staff['role'])) ?></td>
                                    <td><?= status_badge($staff['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($staffAccounts === []): ?>
                                <tr><td colspan="5">No Staff or Rider accounts match your search.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
