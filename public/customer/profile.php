<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$user = current_user();
$profileErrors = [];

if (is_post()) {
    $profileInput = ['full_name' => trim((string) request('full_name')), 'email' => trim((string) request('email')), 'phone' => trim((string) request('phone')), 'address' => trim((string) request('address'))];
    try {
        update_user_profile((int) $user['id'], $profileInput + [
            'current_password' => (string) request('current_password'),
        ]);

        flash('profile', 'Profile updated successfully.', 'success');
        redirect('customer/profile.php');
    } catch (InvalidArgumentException|mysqli_sql_exception $exception) {
        $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to update this account. Please check the entered details.';
        $profileErrors[validation_error_field($message)] = $message;
        $user = array_replace($user, $profileInput);
        flash('profile', $message, 'danger');
    }
}

$user = $profileErrors === [] ? current_user() : $user;
$emailVerified = customer_registration_email_is_verified((int) $user['id']);
$pageTitle = 'Customer Profile';
$bodyClass = 'customer-body';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <section class="section narrow-section">
        <?php render_page_header('Customer Profile', 'Review and update your account information.', 'profile', 'Account Management'); ?>
        <form method="post" class="form-card">
            <?= csrf_field() ?>
            <label>Full Name
                <input type="text" name="full_name" required value="<?= e($user['full_name']) ?>"<?= form_error_attributes($profileErrors, 'full_name', 'profile') ?>>
                <?= form_field_error($profileErrors, 'full_name', 'profile') ?>
            </label>
            <label>Email Address
                <input type="email" name="email" required value="<?= e($user['email']) ?>"<?= form_error_attributes($profileErrors, 'email', 'profile') ?>>
                <?= form_field_error($profileErrors, 'email', 'profile') ?>
                <small>Enter your current password below if you change this address.</small>
            </label>
            <p>Email status: <strong><?= $emailVerified ? 'Verified' : 'Not verified' ?></strong>
                <?php if (!$emailVerified): ?>
                    <a href="<?= e(url('auth/verify-registration.php')) ?>">Verify Email</a>
                <?php endif; ?>
            </p>
            <label>Current Password (required for email changes)
                <input type="password" name="current_password" autocomplete="current-password"<?= form_error_attributes($profileErrors, 'current_password', 'profile') ?>>
                <?= form_field_error($profileErrors, 'current_password', 'profile') ?>
            </label>
            <label>Phone Number
                <input type="text" name="phone" required value="<?= e($user['phone']) ?>"<?= form_error_attributes($profileErrors, 'phone', 'profile') ?>>
                <?= form_field_error($profileErrors, 'phone', 'profile') ?>
            </label>
            <label>Address
                <textarea name="address" rows="3" required<?= form_error_attributes($profileErrors, 'address', 'profile') ?>><?= e($user['address']) ?></textarea>
                <?= form_field_error($profileErrors, 'address', 'profile') ?>
            </label>
            <button class="btn btn-primary" type="submit">Update Profile</button>
        </form>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
