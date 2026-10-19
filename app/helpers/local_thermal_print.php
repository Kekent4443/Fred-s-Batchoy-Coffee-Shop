<?php

declare(strict_types=1);

/**
 * Direct printing is available only from this Windows machine's own browser.
 * A remote/hosted request can never cause an attached POS printer to run.
 */
function local_xp58_print_available(): bool
{
    if (APP_ENV !== 'local' || PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
        return false;
    }

    $enabled = getenv('LOCAL_XP58_PRINT_ENABLED');
    if ($enabled !== false && !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
        return false;
    }

    $loopback = ['127.0.0.1', '::1', 'localhost'];
    $requestHost = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));
    $appHost = strtolower((string) (parse_url(APP_URL, PHP_URL_HOST) ?: ''));

    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $loopback, true)
        && in_array($requestHost, $loopback, true)
        && in_array($appHost, $loopback, true)
        && is_file(APP_ROOT . '/scripts/test-xprinter-raw.ps1');
}

/** @param list<string> $lines */
function local_xp58_spool_lines(array $lines): bool
{
    if (count($lines) < 10 || count($lines) > 500) {
        return false;
    }

    $safeLines = [];
    foreach ($lines as $line) {
        $text = function_exists('iconv')
            ? @iconv('Windows-1252', 'ASCII//TRANSLIT//IGNORE', $line)
            : $line;
        if (!is_string($text) || preg_match('/[^\x20-\x7E]/', $text) || strlen($text) > 32) {
            return false;
        }
        $safeLines[] = $text;
    }

    $payload = implode("\r\n", $safeLines) . "\r\n";
    if (strlen($payload) > 100000) {
        return false;
    }

    $systemRoot = (string) (getenv('SystemRoot') ?: 'C:\\Windows');
    $powershell = realpath($systemRoot . '/System32/WindowsPowerShell/v1.0/powershell.exe');
    $script = realpath(APP_ROOT . '/scripts/test-xprinter-raw.ps1');
    if ($powershell === false || $script === false) {
        return false;
    }

    $process = @proc_open(
        [$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script, '-FromStdin'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        APP_ROOT,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return false;
    }

    $remaining = $payload;
    while ($remaining !== '') {
        $written = fwrite($pipes[0], $remaining);
        if ($written === false || $written === 0) {
            break;
        }
        $remaining = substr($remaining, $written);
    }
    fclose($pipes[0]);
    // Do not copy printer output into HTTP responses or logs: it may contain
    // operational details, and a failed job must remain user-safe.
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return $remaining === '' && $exitCode === 0;
}
