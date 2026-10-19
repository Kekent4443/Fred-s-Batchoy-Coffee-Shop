<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken)
        || $token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        // Use the standard 403 status: some shared-host Apache/FastCGI stacks
        // rewrite the non-standard 419 status to a misleading 500 response.
        http_response_code(403);

        if (request_expects_json()) {
            prepare_json_response_output();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh the page and try again.']);
            exit;
        }

        exit('Invalid CSRF token.');
    }
}
