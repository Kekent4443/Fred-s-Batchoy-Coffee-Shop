<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (is_logged_in()) {
    $viewer = current_user();
    redirect(role_home((string) ($viewer['role'] ?? 'customer')));
}

$email = '';
$remember = false;
$loginErrors = [];

if (is_post()) {
    $email = strtolower(trim(request_string('email')));
    $password = request_string('password');
    $remember = request_string('remember_me') === '1';

    if (login_user($email, $password, $remember)) {
        unset($_SESSION['email_verification_reminder_dismissed']);
        $account = current_user();
        $role = (string) ($account['role'] ?? 'customer');
        $destination = $role === 'customer'
            ? (string) ($_SESSION['intended_customer_path'] ?? role_home($role))
            : role_home($role);

        unset($_SESSION['intended_customer_path']);

        if ($role === 'customer' && !str_starts_with($destination, 'customer/')) {
            $destination = 'customer/catalog.php';
        }

        flash('auth', 'You are now signed in.', 'success');
        redirect($destination);
    } else {
        $failureMessage = match (login_failure_reason()) {
            'throttled' => 'Too many login attempts. Please wait 5 minutes before trying again.',
            'pending_verification' => 'Your account is still pending verification. Please wait for confirmation before signing in.',
            'rejected' => 'Your registration was rejected. Please submit valid information again or contact support.',
            'inactive' => 'Your account is inactive. Please contact support.',
            default => 'Invalid credentials or inactive account.',
        };
        $loginErrors['password'] = $failureMessage;
        flash('auth', $failureMessage, login_failure_reason() === 'pending_verification' ? 'warning' : 'danger');
    }
}

$pageTitle = 'Login';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <div class="auth-page__inner">
        <div class="auth-page__logo">
            <img src="<?= e(get_site_logo_url()) ?>" alt="Fred's Batchoy and Coffee Shop logo">
        </div>
        <div class="auth-card">
            <h1>Login</h1>
            <p>Sign in securely with your account email and password.</p>

            <form method="post">
                <?= csrf_field() ?>
                <label>Email Address
                    <input type="email" name="email" placeholder="you@example.com" value="<?= e($email) ?>" autocomplete="username" required autofocus>
                </label>
                <label>Password
                    <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required<?= form_error_attributes($loginErrors, 'password', 'login') ?>>
                    <?= form_field_error($loginErrors, 'password', 'login') ?>
                </label>
                <div class="auth-card__row">
                    <label class="check-row remember-me" for="remember_me">
                        <input type="checkbox" name="remember_me" id="remember_me" value="1" <?= $remember ? 'checked' : '' ?>>
                        <span>Remember me</span>
                    </label>
                    <a class="auth-link" href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
                </div>
                <button class="btn btn-primary" type="submit">Login</button>
            </form>

            <aside class="info-card" aria-label="Account Recovery Notice">
                <strong>Account Recovery Notice</strong>
                <p>Password recovery requires verification through your registered email address. Automatic recovery of forgotten email addresses is not supported. Please ensure that your registered email remains accessible.</p>
            </aside>

            <div class="auth-divider">New customer?</div>
            <div class="auth-footer">
                <p><a class="auth-link" href="<?= e(url('auth/customer-register.php')) ?>">Create a customer account</a></p>
                <a class="btn btn-ghost btn-icon topbar-nav-icon auth-back" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
                    <?= home_icon_svg() ?>
                    <span class="sr-only">Back to Home</span>
                </a>
            </div>
        </div>
        <div class="auth-footer">&copy; <?= e(current_year()) ?> Fred's. All rights reserved.</div>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
