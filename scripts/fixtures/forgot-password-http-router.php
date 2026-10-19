<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || getenv('TESTING_MODE') !== '1') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/forgot-password-mail-mock.php';

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
if (in_array($path, ['/public/forgot-password.php', '/public/reset-password.php', '/public/auth/login.php'], true)) {
    require dirname(__DIR__, 2) . $path;
    return true;
}

return false;
