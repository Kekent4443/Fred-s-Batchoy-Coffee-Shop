<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('manager');

$productManagementRole = 'manager';
$productManagementBasePath = 'manager/products.php';
$productManagementPageTitle = 'Product Management';
$productManagementSubtitle = 'Manage product information, availability, and stock for your assigned shop.';

require APP_ROOT . '/app/controllers/products.php';
require APP_ROOT . '/app/views/products/page.php';
