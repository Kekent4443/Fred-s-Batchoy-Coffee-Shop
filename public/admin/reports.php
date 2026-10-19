<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

$reportsRole = 'administrator';
$reportsBasePath = 'admin/reports.php';
$reportsPageTitle = 'Reports & Analytics';
$reportsSubtitle = 'Select, filter, export, and print system reports.';
$reportsFilterPrefix = 'admin-report';
$reportsTypeLabel = 'Select Report Type';
$reportsShowSummary = true;

require APP_ROOT . '/app/controllers/reports.php';
require APP_ROOT . '/app/views/reports/page.php';
