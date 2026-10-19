<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

// This route delivers its own password-reset email before responding. Skip the
// generic shutdown worker so SMTP cannot delay the redirect a second time.
$GLOBALS['freds_skip_web_email_queue'] = true;

$pageTitle = 'Forgot Password';
$bodyClass = 'guest-body forgot-password-page';

/** Build the code step only for a real, unexpired reset credential. */
function password_recovery_pending_state(array $result, string $email): array
{
    $now = time();
    $expiresAt = (string) ($result['expires_at'] ?? '');
    $expiresAtEpoch = strtotime($expiresAt);
    if ((int) ($result['reset_id'] ?? 0) < 1 || $expiresAtEpoch === false || $expiresAtEpoch <= $now) {
        throw new RuntimeException('Password recovery returned an unusable code record.');
    }

    return [
        'reset_id' => (int) $result['reset_id'],
        'purpose' => 'password_reset',
        'email' => $email,
        'destination' => 'your registered email address',
        'expires_at' => $expiresAt,
        'expires_at_epoch' => $expiresAtEpoch,
        'resend_available_at' => $now + max(0, (int) ($result['retry_after'] ?? PASSWORD_RESET_COOLDOWN_SECONDS)),
    ];
}

function password_recovery_has_sent_code(array $result): bool
{
    $status = (string) ($result['status'] ?? '');

    return (int) ($result['reset_id'] ?? 0) > 0
        && ($status === 'cooldown' || ($status === 'queued' && ($result['delivery_status'] ?? '') === 'sent'));
}

function password_recovery_delivery_failed(array $result): bool
{
    $status = (string) ($result['status'] ?? 'unavailable');

    return in_array($status, ['unavailable', 'delivery_failed'], true)
        || ($status === 'queued' && (string) ($result['delivery_status'] ?? '') !== 'sent');
}

function password_recovery_code_error_message(): string
{
    return 'Invalid or expired verification code. Request a new code if needed.';
}

function password_recovery_response(
    string $message,
    string $type,
    string $redirectPath = 'forgot-password.php'
): never {
    flash('reset', $message, $type);
    redirect($redirectPath);
}

if (is_post()) {
    $action = (string) post_scalar('action', 'request_code');
    try {

    if (!in_array($action, ['restart', 'request_code', 'resend_code', 'verify_code'], true)) {
        password_recovery_response('Invalid password recovery request.', 'danger');
    }

    if ($action === 'restart') {
        unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
        password_recovery_response('Password recovery restarted.', 'success');
    }

    if ($action === 'request_code') {
        $email = mb_substr(strtolower(trim((string) post_scalar('email', ''))), 0, 150);

        // The service looks up the registered address without requiring
        // registration-email verification.
        $result = save_password_reset_request($email, false, true);
        if (password_recovery_delivery_failed($result)) {
            unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
            password_recovery_response('We could not send a verification code right now. Please try again later.', 'danger');
        }
        if (!password_recovery_has_sent_code($result)) {
            unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
            password_recovery_response('We could not start recovery for this address. Check it and try again later.', 'danger');
        }
        $_SESSION['password_reset_pending'] = password_recovery_pending_state($result, $email);
        password_recovery_response('If the address is registered and eligible, check your inbox and spam folder for the latest code. If you recently requested one, wait before trying again.', 'success');
    }

    if ($action === 'resend_code') {
        $pending = $_SESSION['password_reset_pending'] ?? null;

        if (!is_array($pending) || empty($pending['email'])) {
            password_recovery_response('Enter your registered email address first.', 'danger');
        } else {
            $email = (string) $pending['email'];

            $result = save_password_reset_request($email, true, true);
            if (password_recovery_delivery_failed($result)) {
                unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
                password_recovery_response('We could not send a verification code right now. Please try again later.', 'danger');
            }
            if (!password_recovery_has_sent_code($result)) {
                password_recovery_response('No new code was sent. Use your latest code or try again later.', 'danger');
            }
            $_SESSION['password_reset_pending'] = password_recovery_pending_state($result, $email);

            password_recovery_response('If the address is registered and eligible, use the latest code sent to your inbox. You can request another after the timer ends.', 'success');
        }
    }

    if ($action === 'verify_code') {
        $pending = $_SESSION['password_reset_pending'] ?? null;
        $code = trim((string) post_scalar('verification_code', ''));

        if (!is_array($pending) || (int) ($pending['reset_id'] ?? 0) < 1 || empty($pending['email'])) {
            password_recovery_response('Request a verification code before continuing.', 'danger');
        }

        if ((int) ($pending['expires_at_epoch'] ?? 0) > 0 && (int) $pending['expires_at_epoch'] <= time()) {
            unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
            password_recovery_response('The verification session expired. Request a new code.', 'danger');
        }

        $limitIdentifier = hash('sha256', strtolower((string) $pending['email']) . ':' . (string) ($pending['purpose'] ?? 'password_reset'));
        if (!rate_limit_allow('password_reset_verify', $limitIdentifier, PASSWORD_RESET_MAX_ATTEMPTS + 2, 15 * 60)) {
            password_recovery_response('Too many verification attempts. Request a new code.', 'danger');
        }

        $result = verify_password_reset_code(
            (int) $pending['reset_id'],
            (string) $pending['email'],
            $code,
            'password_reset'
        );
        $status = (string) ($result['status'] ?? 'incorrect');

        if ($status === 'verified') {
            rate_limit_clear('password_reset_verify', $limitIdentifier);
            $_SESSION['password_reset_grant'] = [
                'reset_id' => (int) $result['reset_id'],
                'verification_token' => (string) $result['verification_token'],
                'purpose' => 'password_reset',
                'method' => 'code',
                'tester_email' => tester_email_is_authorized((string) $pending['email'])
                    ? strtolower(trim((string) $pending['email']))
                    : '',
            ];
            unset($_SESSION['password_reset_pending']);
            password_recovery_response('Verification code accepted. Create your new password.', 'success', 'reset-password.php');
        }

        password_recovery_response(password_recovery_code_error_message(), 'danger');
    }
    } catch (Throwable $exception) {
        log_password_recovery_exception('Password recovery request failed', $exception);
        unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
        password_recovery_response('Password recovery is temporarily unavailable. Please try again later.', 'danger');
    }
}

$pending = $_SESSION['password_reset_pending'] ?? null;
$hasPendingCode = is_array($pending) && (int) ($pending['reset_id'] ?? 0) > 0 && !empty($pending['email']);
if ($hasPendingCode && (int) ($pending['expires_at_epoch'] ?? 0) > 0 && (int) $pending['expires_at_epoch'] <= time()) {
    unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_grant']);
    flash('reset', 'The verification session expired. Request a new code.', 'danger');
    $pending = null;
    $hasPendingCode = false;
}
$resendAvailableAt = $hasPendingCode ? (int) ($pending['resend_available_at'] ?? 0) : 0;
$resendWaitSeconds = max(0, $resendAvailableAt - time());
$codeExpiresAt = $hasPendingCode ? (int) ($pending['expires_at_epoch'] ?? 0) : 0;
$pageScripts = ['js/forgot-password-timer.js'];

require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    <a class="btn btn-ghost btn-icon topbar-nav-icon topbar-home-link auth-home-button" href="<?= e(url('index.php')) ?>" aria-label="Back to Home" title="Back to Home">
        <?= home_icon_svg() ?>
        <span class="sr-only">Back to Home</span>
    </a>
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <div class="auth-page__inner">
        <div class="auth-card forgot-password-card <?= $hasPendingCode ? 'forgot-password-card--code' : 'forgot-password-card--request' ?>">
            <a class="btn btn-ghost btn-sm auth-back forgot-password-back" href="<?= e(url('auth/login.php')) ?>">Back to Login</a>
            <?php if ($hasPendingCode): ?>
                <header class="forgot-password-header">
                    <span class="eyebrow">Password Reset</span>
                    <h1>Enter Verification Code</h1>
                    <p id="verification-code-help">If this address is registered and eligible, enter the 6-digit password-reset code sent to <?= e((string) $pending['destination']) ?>. The code expires after <?= e((string) PASSWORD_RESET_EXPIRY_MINUTES) ?> minutes.</p>
                    <p class="forgot-password-expiry" id="verification-code-expiry" data-code-expiry data-code-expires-at="<?= e((string) ($codeExpiresAt * 1000)) ?>" aria-live="polite">Verification session ends in <?= e((string) PASSWORD_RESET_EXPIRY_MINUTES) ?>:00.</p>
                </header>

                <form method="post" class="forgot-password-form forgot-password-code-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_code">
                    <label for="verification-code">Verification Code</label>
                    <input class="forgot-password-code-input" id="verification-code" type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-describedby="verification-code-help verification-code-expiry" required autofocus>
                    <button class="btn btn-primary forgot-password-primary" type="submit" data-verify-submit>Verify Code</button>
                </form>

                <div class="forgot-password-secondary-actions" role="group" aria-label="Verification code options">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend_code">
                        <button class="btn btn-ghost forgot-password-resend" type="submit" data-resend-button data-ready-label="Resend Code" data-resend-ready-at="<?= e((string) ($resendAvailableAt * 1000)) ?>" <?= $resendWaitSeconds > 0 ? 'disabled' : '' ?>>
                            <?= $resendWaitSeconds > 0 ? 'Resend Code in ' . e((string) $resendWaitSeconds) . 's' : 'Resend Code' ?>
                        </button>
                    </form>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="restart">
                        <button class="btn btn-ghost forgot-password-restart" type="submit">Use a Different Email</button>
                    </form>
                </div>
            <?php else: ?>
                <header class="forgot-password-header">
                    <span class="eyebrow">Account Recovery</span>
                    <h1>Forgot Password?</h1>
                    <p>Enter the email address registered to your account. We will send a secure verification code to that same address.</p>
                </header>

                <form method="post" class="forgot-password-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_code">
                    <label for="password-reset-email">Email Address</label>
                    <input id="password-reset-email" type="email" name="email" placeholder="you@example.com" maxlength="150" autocomplete="email" required autofocus>
                    <button class="btn btn-primary forgot-password-primary" type="submit">Send Verification Code</button>
                </form>
            <?php endif; ?>

            <aside class="info-card" aria-label="Account Recovery Notice">
                <strong>Account Recovery Notice</strong>
                <p>Password recovery requires verification through your registered email address. Automatic recovery of forgotten email addresses is not supported. Please ensure that your registered email remains accessible.</p>
            </aside>
            <div class="info-card forgot-password-security-note">
                <strong>Security note:</strong>
                <p>Codes expire after <?= e((string) PASSWORD_RESET_EXPIRY_MINUTES) ?> minutes, allow <?= e((string) PASSWORD_RESET_MAX_ATTEMPTS) ?> attempts, and can only be used once.</p>
            </div>
        </div>
        <div class="auth-footer">&copy; <?= e(current_year()) ?> Fred's. All rights reserved.</div>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
