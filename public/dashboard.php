<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = current_user();

if (!$user) {
    redirect('index.php');
}

redirect(role_home($user['role']));

