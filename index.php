<?php

declare(strict_types=1);

ini_set('expose_php', '0');
header_remove('X-Powered-By');
header('Cache-Control: no-store');
header('Location: public/index.php');
exit;
