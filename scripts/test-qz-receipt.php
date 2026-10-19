<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/app.php';
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';
require_once APP_ROOT . '/app/helpers/qz_receipt.php';

function qz_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$oldCertificate = getenv('QZ_TRAY_CERTIFICATE_PATH');
$oldPrivateKey = getenv('QZ_TRAY_PRIVATE_KEY_PATH');
$oldPassphrase = getenv('QZ_TRAY_PRIVATE_KEY_PASSPHRASE');
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qz-receipt-test-' . bin2hex(random_bytes(8));
if (!mkdir($tempDirectory, 0700)) {
    throw new RuntimeException('Could not create a private test directory.');
}

try {
    $order = [
        'id' => 7,
        'reference_no' => 'A-VERY-LONG-REFERENCE-NUMBER-1234567890',
        'created_at' => '2026-10-08 09:00:00',
        'customer_name' => 'Customer With A Very Long Name',
        'customer_address' => 'A Very Long Address That Must Wrap Across Multiple Lines',
        'payment_method' => 'cash',
        'order_status' => 'completed',
    ];
    $items = [[
        'product_name' => 'A Long Item Name That Must Wrap On 58mm Paper',
        'quantity' => 2,
        'unit_price' => 40.0,
        'subtotal' => 80.0,
    ]];
    $pricing = ['subtotal' => 80.0, 'tax_rate' => 12.0, 'vat_amount' => 8.57, 'total' => 80.0];
    $settings = ['business_name' => 'Freds Batchoy', 'business_address' => 'Test Street', 'paper_width' => '58mm', 'footer_message' => 'Thank you!'];
    $lines = build_receipt_text_lines($order, $items, $pricing, $settings);
    $payload = qz_receipt_payload($lines);
    qz_test_assert(is_string($payload) && $payload === implode("\r\n", $lines) . "\r\n", 'QZ output differs from the localhost RAW line format.');
    qz_test_assert(max(array_map('strlen', $lines)) <= 32, 'Receipt exceeds the XP-58 line width.');
    qz_test_assert(str_contains($payload, 'GRAND TOTAL') && str_contains($payload, 'VAT '), 'Receipt totals are missing.');
    qz_test_assert(qz_receipt_payload(['x']) === null, 'An incomplete receipt was accepted.');
    qz_test_assert(qz_receipt_payload(array_fill(0, 10, str_repeat('x', 33))) === null, 'An overwide line was accepted.');
    qz_test_assert(qz_receipt_payload(array_fill(0, 10, "\x00")) === null, 'A control byte was accepted.');

    putenv('QZ_TRAY_CERTIFICATE_PATH');
    putenv('QZ_TRAY_PRIVATE_KEY_PATH');
    qz_test_assert(qz_signing_material() === null, 'Missing credentials must fail closed.');

    $opensslConfig = (string) (getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf');
    $opensslOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    if (is_file($opensslConfig)) {
        $opensslOptions['config'] = $opensslConfig;
    }
    $key = openssl_pkey_new($opensslOptions);
    qz_test_assert($key instanceof OpenSSLAsymmetricKey, 'Could not create a test signing key.');
    $csr = openssl_csr_new(['commonName' => 'qz-receipt-test.local'], $key, $opensslOptions);
    $certificate = $csr ? openssl_csr_sign($csr, null, $key, 1, $opensslOptions) : false;
    qz_test_assert($certificate instanceof OpenSSLCertificate, 'Could not create a test certificate.');
    qz_test_assert(openssl_pkey_export($key, $keyPem, null, $opensslOptions), 'Could not export the test signing key.');
    qz_test_assert(openssl_x509_export($certificate, $certificatePem), 'Could not export the test certificate.');
    $certificatePath = $tempDirectory . DIRECTORY_SEPARATOR . 'certificate.pem';
    $privateKeyPath = $tempDirectory . DIRECTORY_SEPARATOR . 'private-key.pem';
    file_put_contents($certificatePath, $certificatePem);
    file_put_contents($privateKeyPath, $keyPem);
    putenv('QZ_TRAY_CERTIFICATE_PATH=' . $certificatePath);
    putenv('QZ_TRAY_PRIVATE_KEY_PATH=' . $privateKeyPath);
    putenv('QZ_TRAY_PRIVATE_KEY_PASSPHRASE');
    $material = qz_signing_material();
    qz_test_assert(is_array($material), 'A matching private certificate/key pair was rejected.');
    $hash = hash('sha256', 'QZ callback test');
    qz_test_assert(openssl_sign($hash, $signature, $material['key'], OPENSSL_ALGO_SHA512), 'SHA512 signing failed.');
    qz_test_assert(openssl_verify($hash, $signature, $material['certificate'], OPENSSL_ALGO_SHA512) === 1, 'The QZ signature did not verify.');

    echo "QZ 58mm RAW payload, wrapping, credential fail-closed, and SHA512 signing passed.\n";
} finally {
    foreach (['QZ_TRAY_CERTIFICATE_PATH' => $oldCertificate, 'QZ_TRAY_PRIVATE_KEY_PATH' => $oldPrivateKey, 'QZ_TRAY_PRIVATE_KEY_PASSPHRASE' => $oldPassphrase] as $name => $oldValue) {
        putenv($oldValue === false ? $name : $name . '=' . $oldValue);
    }
    foreach (['certificate.pem', 'private-key.pem'] as $name) {
        $path = $tempDirectory . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($tempDirectory);
}
