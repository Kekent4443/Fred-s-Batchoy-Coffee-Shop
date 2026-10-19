<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || getenv('TESTING_MODE') !== '1') {
    http_response_code(404);
    exit;
}

// Loaded only by the disposable CLI HTTP test server.
$testSessionDirectory = (string) getenv('FREDS_TEST_SESSION_DIR');
if ($testSessionDirectory !== '' && is_dir($testSessionDirectory)) {
    session_save_path($testSessionDirectory);
}

$GLOBALS['freds_test_mail_transport'] = static function (
    string $to,
    string $name,
    string $subject,
    string $body
): array {
    $modeFile = (string) getenv('FREDS_TEST_MAIL_MODE_FILE');
    if ($modeFile !== '' && trim((string) @file_get_contents($modeFile)) === 'fail') {
        return ['success' => false, 'temporary' => false, 'message_id' => '', 'error' => 'Test SMTP failure.'];
    }

    $captureFile = (string) getenv('FREDS_TEST_MAIL_CAPTURE_FILE');
    if ($captureFile !== '') {
        file_put_contents($captureFile, json_encode(['to' => $to, 'body' => $body]) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    return ['success' => true, 'temporary' => false, 'message_id' => 'test-message', 'error' => ''];
};
