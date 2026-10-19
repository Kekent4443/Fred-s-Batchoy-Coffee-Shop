<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

// Staff prepare orders; assigned riders execute deliveries.
redirect('staff/orders.php');
