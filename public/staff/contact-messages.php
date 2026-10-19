<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('staff');
require APP_ROOT . '/app/includes/contact_messages_actions.php';

$pageTitle = 'Contact Messages';
require APP_ROOT . '/app/views/contact_messages/page.php';
