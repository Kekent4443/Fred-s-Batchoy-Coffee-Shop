<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('rider');
redirect('rider/deliveries.php');
