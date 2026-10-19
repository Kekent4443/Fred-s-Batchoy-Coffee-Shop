<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!is_post()) {
    flash('auth', 'Please use the logout button to end your session securely.', 'warning');
    redirect('index.php');
}

logout_user();
flash('auth', 'You have been logged out successfully.', 'success');
redirect('index.php');
