<?php

declare(strict_types=1);

// A subprocess-friendly check: verify_csrf() terminates invalid requests.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/helpers/csrf.php';

$case = $argv[1] ?? '';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['csrf_token' => 'test-session-token'];
$_POST = match ($case) {
    'valid' => ['csrf_token' => 'test-session-token'],
    'invalid' => ['csrf_token' => 'forged-token'],
    'missing' => [],
    default => throw new InvalidArgumentException('Expected valid, invalid, or missing.'),
};

verify_csrf();
echo 'Accepted';
