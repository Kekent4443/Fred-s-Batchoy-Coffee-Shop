<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section narrow-section">
            <div class="form-card">
                <div class="card-head">
                    <h2>View and Update Own Account</h2>
                </div>
                <form method="post">
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
                    <button class="btn btn-primary" type="submit">Update Account</button>
                </form>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
