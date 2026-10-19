<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('manager');

$reportsRole = 'manager';
$reportsBasePath = 'manager/reports.php';
$reportsPageTitle = 'Manager Reports';
$reportsSubtitle = 'Generate, filter, export, and print operational reports.';
$reportsFilterPrefix = 'manager-report';
$reportsTypeLabel = 'Report Type';
$reportsShowSummary = false;

require APP_ROOT . '/app/controllers/reports.php';
require APP_ROOT . '/app/views/reports/page.php';
