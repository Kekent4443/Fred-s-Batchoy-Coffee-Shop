<?php

declare(strict_types=1);

// Compatibility entry point for the existing bootstrap and CLI scripts.
require_once __DIR__ . '/../services/Foundation.php';
require_once __DIR__ . '/../services/Notifications.php';
require_once __DIR__ . '/../services/Schema.php';
require_once __DIR__ . '/../services/Promos.php';
require_once __DIR__ . '/../services/Users.php';
require_once __DIR__ . '/../services/Priority.php';
require_once __DIR__ . '/../services/CustomerVerification.php';
require_once __DIR__ . '/../services/Products.php';
require_once __DIR__ . '/../services/Uploads.php';
require_once __DIR__ . '/../services/Orders.php';
require_once __DIR__ . '/../services/Reporting.php';
require_once __DIR__ . '/../services/Records.php';
require_once __DIR__ . '/../services/PasswordRecovery.php';
