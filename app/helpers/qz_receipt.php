<?php

declare(strict_types=1);

/** The localhost RAW spooler and QZ Tray must receive identical ASCII lines. */
function qz_receipt_payload(array $lines): ?string
{
    if (count($lines) < 10 || count($lines) > 500) {
        return null;
    }

    foreach ($lines as $line) {
        if (!is_string($line) || strlen($line) > 32 || preg_match('/[^\x20-\x7E]/', $line)) {
            return null;
        }
    }

    $payload = implode("\r\n", $lines) . "\r\n";
    return strlen($payload) <= 100000 ? $payload : null;
}

function qz_signing_file(string $environmentName, bool $private = false): ?string
{
    $configured = trim((string) (getenv($environmentName) ?: ''));
    $path = $configured !== '' ? realpath($configured) : false;
    if ($path === false || !is_file($path) || !is_readable($path) || filesize($path) > 100000) {
        return null;
    }

    if ($private) {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $projectRoot = realpath(APP_ROOT);
        if ($projectRoot !== false
            && str_starts_with($normalized, strtolower(str_replace('\\', '/', $projectRoot)) . '/')) {
            return null;
        }
    }

    return $path;
}

/** @return array{certificate:string,key:OpenSSLAsymmetricKey}|null */
function qz_signing_material(): ?array
{
    $certificatePath = qz_signing_file('QZ_TRAY_CERTIFICATE_PATH');
    $privatePath = qz_signing_file('QZ_TRAY_PRIVATE_KEY_PATH', true);
    if ($certificatePath === null || $privatePath === null) {
        return null;
    }

    $certificate = file_get_contents($certificatePath);
    $privatePem = file_get_contents($privatePath);
    if (!is_string($certificate) || !is_string($privatePem)) {
        return null;
    }

    $key = openssl_pkey_get_private($privatePem, (string) (getenv('QZ_TRAY_PRIVATE_KEY_PASSPHRASE') ?: ''));
    $certificateDetails = openssl_x509_parse($certificate);
    $keyDetails = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;
    $now = time();
    if (!$key instanceof OpenSSLAsymmetricKey
        || !is_array($certificateDetails)
        || (int) ($certificateDetails['validFrom_time_t'] ?? 0) > $now
        || (int) ($certificateDetails['validTo_time_t'] ?? 0) <= $now
        || !is_array($keyDetails)
        || (int) ($keyDetails['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA
        || (int) ($keyDetails['bits'] ?? 0) < 2048
        || !openssl_x509_check_private_key($certificate, $key)) {
        return null;
    }

    return ['certificate' => $certificate, 'key' => $key];
}

function qz_receipt_json(int $status, array $payload): never
{
    prepare_json_response_output();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function qz_receipt_viewer(): ?array
{
    $viewer = current_user();
    return $viewer && in_array((string) ($viewer['role'] ?? ''), ['customer', 'staff'], true)
        ? $viewer
        : null;
}
