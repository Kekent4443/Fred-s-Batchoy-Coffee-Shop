<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$registrationErrors = [];
$registrationClearedFields = [];

if (is_post()) {
    $submittedRole = post_scalar('role');
    if ($submittedRole !== null && $submittedRole !== 'customer') {
        if ($submittedRole === 'administrator') {
            log_administrator_policy_rejection(
                'administrator_registration_blocked',
                'Rejected an anonymous public-registration attempt requesting the Administrator role.'
            );
        }
        flash('auth', 'Public registration can create Customer accounts only.', 'danger');
        redirect('auth/customer-register.php');
    }

    $fullName = trim((string) request('full_name'));
    $email = trim((string) request('email'));
    $phone = trim((string) request('phone'));
    $address = trim((string) request('address'));
    $password = (string) request('password');
    $confirm = (string) request('confirm_password');
    $termsAccepted = request('terms_accepted') === '1';
    $verifyEmailRequested = request('verify_email') === '1';
    $phoneValidationError = '';

    if ($phone !== '') {
        try {
            $phone = validate_user_phone_number($phone);
        } catch (InvalidArgumentException $exception) {
            $phoneValidationError = $exception->getMessage();
        }
    }

    $registrationLimited = !rate_limit_allow('customer_registration', $email !== '' ? $email : $phone, 5, 60 * 60);

    $existingCustomer = db_find('SELECT id, role, status, password FROM users WHERE email = ? LIMIT 1', 's', [$email]);
    $canResubmitRejectedRegistration = $existingCustomer
        && ($existingCustomer['role'] ?? '') === 'customer'
        && ($existingCustomer['status'] ?? '') === 'rejected';
    $existingPhoneOwner = $phone !== '' && $phoneValidationError === ''
        ? db_find(
            'SELECT id, role, status
             FROM users
             WHERE ' . user_phone_normalized_sql_expression('phone') . ' = ?
             LIMIT 1',
            's',
            [$phone]
        )
        : null;
    $phoneBelongsToAnotherAccount = $existingPhoneOwner
        && (!$existingCustomer || (int) $existingPhoneOwner['id'] !== (int) $existingCustomer['id']);

    if ($registrationLimited) {
        $registrationErrors['form'] = 'Too many registration attempts. Please wait before submitting again.';
        flash('auth', 'Too many registration attempts. Please wait before submitting again.', 'danger');
    } elseif ($fullName === '' || $email === '' || $phone === '' || $address === '') {
        foreach (['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'address' => $address] as $field => $value) {
            if ($value === '') {
                $registrationErrors[$field] = 'This field is required.';
            }
        }
        flash('auth', 'Please complete all required registration fields.', 'danger');
    } elseif (strlen($fullName) > 150 || strlen($email) > 150 || strlen($address) > 1000) {
        $registrationErrors[strlen($fullName) > 150 ? 'full_name' : (strlen($email) > 150 ? 'email' : 'address')] = 'This value is too long.';
        flash('auth', 'One or more registration fields are too long.', 'danger');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registrationErrors['email'] = 'Please enter a valid email address.';
        flash('auth', 'Please enter a valid email address.', 'danger');
    } elseif ($phoneValidationError !== '') {
        $registrationErrors['phone'] = $phoneValidationError;
        flash('auth', $phoneValidationError, 'danger');
    } elseif (strlen($address) < 20) {
        $registrationErrors['address'] = 'Please enter your exact and complete delivery address.';
        flash('auth', 'Please enter your exact and complete delivery address.', 'danger');
    } elseif (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $registrationErrors['password'] = 'Password must be 8 to 72 characters and include at least one letter and one number.';
        flash('auth', 'Password must be 8 to 72 characters and include at least one letter and one number.', 'danger');
    } elseif ($password !== $confirm) {
        $registrationErrors['confirm_password'] = 'Passwords do not match.';
        flash('auth', 'Passwords do not match.', 'danger');
    } elseif (!$termsAccepted) {
        $registrationErrors['terms_accepted'] = 'You must agree to the Terms of Service and Privacy Policy before registering.';
        flash('auth', 'You must agree to the Terms of Service and Privacy Policy to register.', 'danger');
    } elseif ($canResubmitRejectedRegistration && !password_verify($password, (string) ($existingCustomer['password'] ?? ''))) {
        $registrationErrors['password'] = 'This email is registered. Enter the existing account password or use Forgot Password.';
        flash('auth', 'This email address is already registered. Enter the existing account password to resubmit, or use Forgot Password.', 'danger');
    } elseif ($existingCustomer && !$canResubmitRejectedRegistration) {
        $registrationErrors['email'] = 'Email address is already registered.';
        $registrationClearedFields[] = 'email';
        flash('auth', 'Email address is already registered.', 'danger');
    } elseif ($phoneBelongsToAnotherAccount) {
        $registrationErrors['phone'] = 'This phone number is already registered. Please use a different number.';
        $registrationClearedFields[] = 'phone';
        flash('auth', 'This phone number is already registered. Please use a different number', 'danger');
    } else {
        $validIdPath = null;
        $registrationSaved = false;
        try {
            $validIdPath = save_uploaded_valid_id($_FILES['valid_id'] ?? null, true);

            $registrationData = [
                'full_name' => $fullName,
                'email' => $email,
                'password' => $password,
                'phone' => $phone,
                'address' => $address,
                'role' => 'customer',
                'status' => 'pending_verification',
                'verification_status' => 'pending',
                'valid_id_path' => $validIdPath,
            ];

            if ($canResubmitRejectedRegistration) {
                $customerId = (int) $existingCustomer['id'];
                resubmit_customer_registration($customerId, $registrationData);
                $registrationSaved = true;
            } else {
                $customerId = create_user($registrationData);
                $registrationSaved = true;
            }

            if ($verifyEmailRequested) {
                $_SESSION['registration_verification_pending'] = [
                    'customer_id' => $customerId,
                    'email' => strtolower($email),
                    'started_at' => time(),
                ];
                flash('auth', 'Account saved. Select Send Code to receive your email verification code.', 'success');
                redirect('auth/verify-registration.php');
            }

            unset($_SESSION['registration_verification_pending']);
            flash('auth', 'Account created. Your registration is waiting for identity verification by authorized staff. Email verification can be completed later.', 'success');
            redirect('auth/login.php');
        } catch (InvalidArgumentException $exception) {
            if (!$registrationSaved && $validIdPath && function_exists('delete_uploaded_evidence')) {
                delete_uploaded_evidence($validIdPath);
            }
            $registrationErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
            flash('auth', $exception->getMessage(), 'danger');
        } catch (mysqli_sql_exception $exception) {
            if (!$registrationSaved && $validIdPath && function_exists('delete_uploaded_evidence')) {
                delete_uploaded_evidence($validIdPath);
            }
            $message = (int) $exception->getCode() === 1062
                ? 'That email address or phone number is already registered.'
                : 'Registration could not be saved. Please try again.';
            $duplicateField = duplicate_error_field($exception);
            $registrationErrors[$duplicateField] = $message;
            if (in_array($duplicateField, ['email', 'phone'], true)) {
                $registrationClearedFields[] = $duplicateField;
            }
            flash('auth', $message, 'danger');
        } catch (Throwable $exception) {
            if (!$registrationSaved && $validIdPath && function_exists('delete_uploaded_evidence')) {
                delete_uploaded_evidence($validIdPath);
            }
            error_log('Customer registration failed: ' . $exception->getMessage());
            $registrationErrors['form'] = 'Registration could not be completed. Please try again.';
            flash('auth', 'Registration could not be completed. Please try again.', 'danger');
        }
    }

    if ($registrationErrors !== [] && !empty($_FILES['valid_id']['name'])) {
        $registrationErrors['valid_id'] = 'For security, select your Valid ID file again before resubmitting.';
    }
}

$pageTitle = 'Customer Registration';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
    <div class="auth-page__inner">
       <div class="auth-card">
            <h1>Create Account</h1>
            <p>Fill in your details to get started.</p>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label>Full Name
                    <input type="text" name="full_name" autocomplete="name" required value="<?= old('full_name') ?>" placeholder="Juan Dela Cruz"<?= form_error_attributes($registrationErrors, 'full_name', 'registration') ?>>
                    <?= form_field_error($registrationErrors, 'full_name', 'registration') ?>
                </label>
                <label>Email Address
                    <input type="email" name="email" autocomplete="email" required value="<?= in_array('email', $registrationClearedFields, true) ? '' : old('email') ?>" placeholder="customer@example.com"<?= form_error_attributes($registrationErrors, 'email', 'registration') ?>>
                    <?= form_field_error($registrationErrors, 'email', 'registration') ?>
                </label>
                <label>Phone Number
                    <input type="tel" name="phone" inputmode="tel" autocomplete="tel" minlength="11" maxlength="13" pattern="^(09[0-9]{9}|\+639[0-9]{9})$" required value="<?= in_array('phone', $registrationClearedFields, true) ? '' : old('phone') ?>" placeholder="09XXXXXXXXX or +639XXXXXXXXX" title="Use 09XXXXXXXXX or +639XXXXXXXXX format."<?= form_error_attributes($registrationErrors, 'phone', 'registration') ?>>
                    <?= form_field_error($registrationErrors, 'phone', 'registration') ?>
                </label>
                <label>Delivery Address
                    <textarea name="address" rows="3" minlength="20" autocomplete="street-address" required placeholder="House number, street, barangay, city, province"<?= form_error_attributes($registrationErrors, 'address', 'registration') ?>><?= old('address') ?></textarea>
                    <?= form_field_error($registrationErrors, 'address', 'registration') ?>
                    <small>Enter your exact delivery address for order verification and delivery.</small>
                </label>
                <label>Password
                    <input type="password" name="password" minlength="8" maxlength="72" required placeholder="Create a strong password" autocomplete="new-password"<?= form_error_attributes($registrationErrors, 'password', 'registration') ?>>
                    <?= form_field_error($registrationErrors, 'password', 'registration') ?>
                </label>
                <label>Confirm Password
                    <input type="password" name="confirm_password" minlength="8" maxlength="72" required placeholder="Re-enter your password" autocomplete="new-password"<?= form_error_attributes($registrationErrors, 'confirm_password', 'registration') ?>>
                    <?= form_field_error($registrationErrors, 'confirm_password', 'registration') ?>
                </label>
                <label>Valid ID
                    <input type="file" name="valid_id" accept="image/*,.pdf" required<?= form_error_attributes($registrationErrors, 'valid_id', 'registration') ?>>
                    <small>Required for account verification. Accepted files: JPG, PNG, GIF, WEBP, or PDF up to 5MB.</small>
                    <?= form_field_error($registrationErrors, 'valid_id', 'registration') ?>
                </label>
                <div class="optional-email-verification">
                    <label class="terms-agreement" for="verify_email">
                        <input type="checkbox" name="verify_email" id="verify_email" value="1" <?= !empty($verifyEmailRequested) ? 'checked' : '' ?>>
                        <span class="terms-agreement__text">Verify my email now (optional)</span>
                    </label>
                    <small>You can skip this and verify after your account is approved. Email verification enables password recovery.</small>
                </div>
                <div class="terms-agreement-field">
                    <label class="terms-agreement" for="terms_accepted">
                        <input
                            type="checkbox"
                            name="terms_accepted"
                            id="terms_accepted"
                            value="1"
                            <?= $termsAccepted ? 'checked' : '' ?>
                            required<?= form_error_attributes($registrationErrors, 'terms_accepted', 'registration') ?>
                        >
                        <span class="terms-agreement__text">
                            I agree to the
                            <a class="terms-agreement__link" href="<?= e(url('terms-of-service.php')) ?>" target="_blank" rel="noopener noreferrer">Terms of Service</a>
                            and acknowledge the
                            <a class="terms-agreement__link" href="<?= e(url('privacy-policy.php')) ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
                        </span>
                    </label>
                    <?= form_field_error($registrationErrors, 'terms_accepted', 'registration') ?>
                </div>
                <button class="btn btn-primary" type="submit">Create Account</button>
            </form>

            <div class="auth-footer">
                <p>Already have an account? <a class="auth-link" href="<?= e(url('auth/login.php')) ?>">Sign In</a></p>
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
