<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$viewer = current_user();
$viewerRole = (string) ($viewer['role'] ?? '');
if ($viewer && $viewerRole !== 'customer') {
    redirect(role_home($viewerRole));
}
$pending = $_SESSION['registration_verification_pending'] ?? null;
$customerId = $viewer && ($viewer['role'] ?? '') === 'customer'
    ? (int) $viewer['id']
    : (is_array($pending) && (int) ($pending['started_at'] ?? 0) > time() - 86400
        ? (int) ($pending['customer_id'] ?? 0)
        : 0);
$customer = $customerId > 0
    ? db_find('SELECT id, email, role, status, email_verified_at FROM users WHERE id = ? LIMIT 1', 'i', [$customerId])
    : null;

if (!$customer || ($customer['role'] ?? '') !== 'customer'
    || (!$viewer && strtolower((string) $customer['email']) !== strtolower((string) ($pending['email'] ?? '')))) {
    unset($_SESSION['registration_verification_pending']);
    flash('auth', 'Sign in to verify your registered email address.', 'warning');
    redirect('auth/login.php');
}

if (customer_registration_email_is_verified($customerId)) {
    unset($_SESSION['registration_verification_pending']);
    flash('auth', 'Your email address is already verified.', 'success');
    redirect($viewer ? 'customer/profile.php' : 'auth/login.php');
}

if (is_post()) {
    $action = request_string('action');

    if ($action === 'skip') {
        unset($_SESSION['registration_verification_pending']);
        if ($viewer) {
            $_SESSION['email_verification_reminder_dismissed'] = true;
        }
        flash('auth', 'You can verify your email later. Your account registration remains submitted.', 'info');
        redirect($viewer ? 'customer/catalog.php' : 'auth/login.php');
    }

    if ($action === 'send_code') {
        try {
            $result = request_registration_email_verification($customerId, (string) $customer['email'], true);
            $message = match ($result['status'] ?? '') {
                'sent' => 'A verification code was sent to your registered email address.',
                'cooldown', 'rate_limited' => 'Please wait before requesting another verification code.',
                'unavailable', 'delivery_failed' => 'Unable to send a verification code right now. Please try again later.',
                default => 'Unable to request a verification code right now.',
            };
            $resultStatus = (string) ($result['status'] ?? '');
            flash('auth', $message, $resultStatus === 'sent' ? 'success'
                : (in_array($resultStatus, ['cooldown', 'rate_limited'], true) ? 'warning' : 'danger'));
        } catch (Throwable $exception) {
            error_log('Email verification request failed for account #' . $customerId . '.');
            flash('auth', 'Unable to send a verification code right now. Please try again later.', 'danger');
        }
        redirect('auth/verify-registration.php');
    }

    if ($action === 'verify_code') {
        $code = trim((string) request('verification_code', ''));
        if (!rate_limit_allow('registration_verification_code', (string) $customerId, registration_verification_max_attempts() + 2, 15 * 60)) {
            flash('auth', 'Too many attempts. Request a new code later.', 'danger');
            redirect('auth/verify-registration.php');
        }

        try {
            $result = verify_registration_email_code($customerId, (string) $customer['email'], $code);
            if (($result['status'] ?? '') === 'verified') {
                rate_limit_clear('registration_verification_code', (string) $customerId);
                unset($_SESSION['registration_verification_pending']);
                flash('auth', 'Your registered email address has been verified.', 'success');
                redirect($viewer ? 'customer/profile.php' : 'auth/login.php');
            }
            flash('auth', in_array(($result['status'] ?? ''), ['too_many_attempts', 'expired'], true)
                ? 'This code has expired or reached its attempt limit. Request a new code.'
                : 'The verification code is incorrect or no longer valid.', 'danger');
        } catch (Throwable $exception) {
            error_log('Email verification failed for account #' . $customerId . '.');
            flash('auth', 'Email verification is temporarily unavailable. Please try again later.', 'danger');
        }
        redirect('auth/verify-registration.php');
    }
}

$verificationState = registration_email_verification_status($customerId);
$codeWasSent = !empty($verificationState['code_sent']);
$resendRetryAfter = max(0, (int) ($verificationState['retry_after'] ?? 0));
$sendButtonLabel = $codeWasSent ? 'Send New Code' : 'Send Code';
$pageTitle = 'Verify Email';
$bodyClass = 'guest-body email-verification-page';
$pageScripts = ['js/email-verification.js'];
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <div class="auth-page__inner">
        <div class="auth-card email-verification-card">
            <h1>Verify Your Email</h1>
            <p class="email-verification-card__intro"><?= $codeWasSent ? 'Enter the six-digit code sent to' : 'Select Send Code to receive a six-digit code at' ?> <?= e(mask_email((string) $customer['email'])) ?>.</p>
            <form method="post" class="email-verification-card__form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_code">
                <label for="email-verification-code">Verification Code</label>
                <input id="email-verification-code" type="text" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
                <button class="btn btn-primary" type="submit">Verify Email</button>
            </form>
            <div class="email-verification-card__actions">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_code">
                    <button class="btn btn-secondary" type="submit" data-email-code-send data-ready-at="<?= e((string) ((time() + $resendRetryAfter) * 1000)) ?>" aria-describedby="email-verification-resend-status" <?= $resendRetryAfter > 0 ? 'disabled' : '' ?>><?= e($sendButtonLabel) ?></button>
                </form>
                <small id="email-verification-resend-status" class="email-verification-card__help" data-email-code-countdown aria-live="polite"><?= $resendRetryAfter > 0 ? 'You can request a code in ' . e((string) $resendRetryAfter) . 's.' : '' ?></small>
                <?php if (!$viewer): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="skip">
                        <button class="btn btn-ghost" type="submit">Verify Later</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-ghost" href="<?= e(url('customer/catalog.php')) ?>">Verify Later</a>
                <?php endif; ?>
            </div>
            <small class="email-verification-card__help">Codes expire after <?= e((string) registration_verification_expiry_minutes()) ?> minutes and allow <?= e((string) registration_verification_max_attempts()) ?> attempts.</small>
        </div>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
