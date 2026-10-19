<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

$profileBasePath = 'admin/profile.php';
$profilePageTitle = 'Administrator Profile';
$profileSuccessMessage = 'Administrator account updated.';
require APP_ROOT . '/app/controllers/profile.php';
require APP_ROOT . '/app/views/profile/page.php';
