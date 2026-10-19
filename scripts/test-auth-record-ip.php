<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('APP_TRUSTED_PROXIES=127.0.0.1,::1');
require_once dirname(__DIR__) . '/app/bootstrap.php';

function auth_ip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['192.168.1.5', '127.0.0.1', '10.0.0.1', '8.8.8.8'] as $ip) {
    $markup = auth_history_ip_markup($ip);
    auth_ip_assert(str_contains($markup, '>' . $ip . '</span>') && str_contains($markup, '>IPv4</span>'),
        'IPv4 address was changed or misclassified.');
}
foreach (['::1', '2405:8d40:48c8:f7f6:544b:5ee7:f2bf:f028', '::ffff:192.0.2.1'] as $ip) {
    $markup = auth_history_ip_markup($ip);
    auth_ip_assert(str_contains($markup, '>' . $ip . '</span>') && str_contains($markup, '>IPv6</span>'),
        'IPv6 address was changed or misclassified.');
}
foreach ([null, '', 'not-an-ip', '999.1.2.3', '<script>'] as $ip) {
    auth_ip_assert(str_contains(auth_history_ip_markup($ip), '>Unknown</span>')
        && !str_contains(auth_history_ip_markup($ip), '<script>'), 'Invalid address did not use the safe fallback.');
}

$_SERVER['REMOTE_ADDR'] = '192.168.1.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
auth_ip_assert(client_ip() === '192.168.1.5', 'An untrusted forwarded header replaced the direct client IP.');
auth_ip_assert(auth_history_client_ip() === '192.168.1.5', 'Authentication history did not record the direct client IP.');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 127.0.0.1';
auth_ip_assert(client_ip() === '8.8.8.8', 'A trusted IPv4 proxy did not report the client IP.');
auth_ip_assert(auth_history_client_ip() === '8.8.8.8', 'Authentication history did not record the trusted IPv4 client IP.');

$_SERVER['REMOTE_ADDR'] = '::1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '2405:8d40:48c8:f7f6:544b:5ee7:f2bf:f028';
auth_ip_assert(client_ip() === '2405:8d40:48c8:f7f6:544b:5ee7:f2bf:f028',
    'A trusted IPv6 proxy did not report the client IP.');
auth_ip_assert(auth_history_client_ip() === '2405:8d40:48c8:f7f6:544b:5ee7:f2bf:f028',
    'Authentication history did not record the trusted IPv6 client IP.');

unset($_SERVER['REMOTE_ADDR']);
auth_ip_assert(auth_history_client_ip() === 'Unknown', 'Missing server peer IP was recorded as localhost.');
$_SERVER['REMOTE_ADDR'] = 'invalid-peer';
auth_ip_assert(auth_history_client_ip() === 'Unknown', 'Invalid server peer IP was recorded as localhost.');

echo "Authentication Records IP display and trusted-proxy capture checks passed.\n";
