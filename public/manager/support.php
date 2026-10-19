<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('manager');

$pageTitle = 'Support Chat';
$supportSubtitle = 'Assign and respond to customer support conversations.';
$supportFaqUrl = null;
require APP_ROOT . '/app/views/support/page.php';
