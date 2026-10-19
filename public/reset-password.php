<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$pageTitle = 'Reset Password';
$bodyClass = 'guest-body';
$metaReferrer = 'no-referrer';
$pageScripts = ['js/forgot-password.js'];

function password_reset_response(
    string $message,
    string $type,
    string $redirectPath,
    int $status = 200
): never {
    flash($type === 'success' ? 'auth' : 'reset', $message, $type);

    if (request_expects_json()) {
        prepare_json_response_output();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $success = $type === 'success';
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'redirect' => $success ? url($redirectPath) : null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    redirect($redirectPath);
}

if (!is_post()) {
    $incomingToken = strtolower(trim((string) query_scalar('token', '')));
    if ($incomingToken !== '') {
        $linkVerification = verify_password_reset_link($incomingToken);

        if (($linkVerification['status'] ?? '') === 'verified') {
            $_SESSION['password_reset_grant'] = [
                'reset_id' => (int) $linkVerification['reset_id'],
                'verification_token' => (string) $linkVerification['verification_token'],
                'purpose' => (string) $linkVerification['purpose'],
                'method' => 'link',
            ];
            unset($_SESSION['password_reset_pending']);
            redirect('reset-password.php');
        }

        unset($_SESSION['password_reset_grant']);
        flash('reset', 'This password-reset link is invalid, expired, or has already been used.', 'danger');
        redirect('forgot-password.php');
    }
}

$grant = $_SESSION['password_reset_grant'] ?? null;
$resetId = is_array($grant) ? (int) ($grant['reset_id'] ?? 0) : 0;
$verificationToken = is_array($grant) ? (string) ($grant['verification_token'] ?? '') : '';
$resetPurpose = is_array($grant) ? (string) ($grant['purpose'] ?? '') : '';
$resetMethod = is_array($grant) ? (string) ($grant['method'] ?? '') : '';
$inspection = inspect_verified_password_reset($resetId, $verificationToken, $resetPurpose, $resetMethod);

if ($inspection['status'] !== 'valid') {
    unset($_SESSION['password_reset_grant']);
    flash('reset', 'Verify the password-reset request sent to your registered email before creating a new password.', 'danger');
    redirect('forgot-password.php');
}

$resetAccountEmail = (string) (($inspection['reset']['user_email'] ?? null) ?: ($inspection['reset']['email'] ?? ''));

if (is_post()) {
    $password = (string) post_scalar('password', '');
    $confirmPassword = (string) post_scalar('confirm_password', '');

    if ($password !== $confirmPassword) {
        password_reset_response('Passwords do not match.', 'danger', 'reset-password.php', 422);
    } elseif (strlen($password) < 8 || strlen($password) > 72
        || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        password_reset_response('Password must be 8 to 72 characters and include at least one letter and one number.', 'danger', 'reset-password.php', 422);
    } elseif (!rate_limit_allow('password_reset_change', (string) $resetId, 5, 15 * 60)) {
        password_reset_response('Too many password reset attempts. Please wait 15 minutes and try again.', 'danger', 'reset-password.php', 429);
    } else {
        $status = $resetMethod === 'link'
            ? reset_password_with_verified_link($resetId, $verificationToken, $password, $resetPurpose)
            : reset_password_with_verified_code($resetId, $verificationToken, $password);

        if ($status === 'success') {
            rate_limit_clear('password_reset_change', (string) $resetId);
            unset($_SESSION['password_reset_grant'], $_SESSION['password_reset_pending']);
            session_regenerate_id(true);
            password_reset_response('Password reset successful. You can now log in using your new password.', 'success', 'auth/login.php');
        }

        unset($_SESSION['password_reset_grant']);
        password_reset_response($status === 'expired'
            ? 'Password-reset authorization expired after ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes. Please request a new one.'
            : 'Your password-reset authorization is no longer valid. Please request a new one.', 'danger', 'forgot-password.php', 410);
    }
}

require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    <a class="btn btn-ghost btn-icon topbar-nav-icon topbar-home-link auth-home-button" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
        <?= home_icon_svg() ?>
        <span class="sr-only">Back to Home</span>
    </a>
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <div class="auth-page__inner">
        <div class="auth-card">
            <h1>Create New Password</h1>
            <p>Your password-reset request has been verified. Choose a new password for your account.</p>
            <p class="field-error" data-password-recovery-feedback role="alert" aria-live="assertive" hidden></p>

            <form method="post" data-password-recovery-form>
                <?= csrf_field() ?>
                <label>Account Email
                    <input type="email" value="<?= e($resetAccountEmail) ?>" readonly aria-readonly="true">
                </label>
                <label>New Password
                    <input type="password" name="password" minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" title="Use 8 to 72 characters with at least one letter and one number." autocomplete="new-password" required autofocus>
                </label>
                <label>Confirm New Password
                    <input type="password" name="confirm_password" minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" title="Use 8 to 72 characters with at least one letter and one number." autocomplete="new-password" required>
                </label>
                <button class="btn btn-primary" type="submit" data-loading-label="Updating...">Reset Password</button>
            </form>

            <div class="info-card">
                <strong>Security note:</strong>
                <p>Use 8 to 72 characters with at least one letter and one number. After this password is saved, every active reset code and signed-in session for your account will be invalidated.</p>
            </div>
        </div>
        <div class="auth-footer">&copy; <?= e(current_year()) ?> Fred's. All rights reserved.</div>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
