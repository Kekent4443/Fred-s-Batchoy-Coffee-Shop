<?php

declare(strict_types=1);

$user = current_user();
$profileErrors = [];

if (is_post()) {
    $profileInput = [
        'full_name' => trim((string) request('full_name')),
        'email' => trim((string) request('email')),
        'phone' => trim((string) request('phone')),
        'address' => trim((string) request('address')),
    ];
    try {
        update_user_profile((int) $user['id'], $profileInput + [
            'current_password' => (string) request('current_password'),
        ]);
        flash('profile', $profileSuccessMessage, 'success');
        redirect($profileBasePath);
    } catch (InvalidArgumentException|mysqli_sql_exception $exception) {
        $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to update this account. Please check the entered details.';
        $profileErrors[validation_error_field($message)] = $message;
        $user = array_replace($user, $profileInput);
        flash('profile', $message, 'danger');
    }
}

$user = $profileErrors === [] ? current_user() : $user;
$pageTitle = $profilePageTitle;
