<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('staff');

$pageTitle = 'Support Chat';
$supportSubtitle = 'Reply to assigned and open customer support conversations.';
$supportFaqUrl = null;
require APP_ROOT . '/app/views/support/page.php';
