<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('manager');

$profileBasePath = 'manager/profile.php';
$profilePageTitle = 'Manager Profile';
$profileSuccessMessage = 'Manager account updated.';
require APP_ROOT . '/app/controllers/profile.php';
require APP_ROOT . '/app/views/profile/page.php';
