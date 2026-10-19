<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

$pageTitle = 'Support Chat';
$supportSubtitle = 'Monitor customer concerns, handoffs, assignments, and staff replies.';
$supportFaqUrl = 'admin/support-faqs.php';
require APP_ROOT . '/app/views/support/page.php';
