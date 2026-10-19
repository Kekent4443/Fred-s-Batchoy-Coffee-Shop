<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

$discountRulesBasePath = 'admin/discount-rules.php';
require APP_ROOT . '/app/controllers/discount_rules.php';
require APP_ROOT . '/app/views/discount_rules/page.php';
