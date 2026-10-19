<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$batchSize = 10;
foreach ($argv as $argument) {
    if (preg_match('/^--batch=(\d+)$/', $argument, $matches) === 1) {
        $batchSize = min(50, max(1, (int) $matches[1]));
    }
}

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freds-email-queue.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Email queue worker is already running.\n");
    exit(0);
}

try {
    $summary = process_email_queue_batch($batchSize);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
