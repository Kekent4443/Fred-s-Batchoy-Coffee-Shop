<?php

declare(strict_types=1);

// Run monthly from a trusted machine against the live domain, never as a web route.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$baseUrl = rtrim((string) ($argv[1] ?? ''), '/');
$parts = parse_url($baseUrl);
$host = strtolower((string) ($parts['host'] ?? ''));
$local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
if ($parts === false || $host === '' || !in_array($parts['scheme'] ?? '', $local ? ['http', 'https'] : ['https'], true)
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
    || !extension_loaded('curl')) {
    fwrite(STDERR, "Usage: php scripts/check-private-urls.php https://your-domain.example\n");
    exit(2);
}

$paths = [
    '/app/bootstrap.php',
    '/private/admin-access.php',
    '/private/database.local.php',
    '/database/schema.sql',
    '/vendor/autoload.php',
    '/scripts/migrate.php',
];
$failed = false;
foreach ($paths as $path) {
    $handle = curl_init($baseUrl . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_USERAGENT => 'FredsPrivateUrlCheck/1.0',
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    // Never print response bodies: even a failed check might contain a secret.
    $passed = $body !== false && $status === 403
        && !preg_match('/<\?php|CREATE\s+TABLE|DB_(?:HOST|PASS|USER)|SMTP_PASSWORD/i', $body);
    printf("%s %s: %s%s\n", $passed ? 'PASS' : 'FAIL', $path, $status ?: 'network error', $error !== '' ? ' (request failed)' : '');
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
