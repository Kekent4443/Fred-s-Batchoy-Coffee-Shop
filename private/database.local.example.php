<?php

declare(strict_types=1);

// Copy to private/database.local.php; change credentials only in that ignored file.
// DB_* process environment variables override this file. See README.md.
// The example root account with an empty password is for localhost only.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'freds_sales_system',
    'user' => 'root',
    'password' => '',
];
