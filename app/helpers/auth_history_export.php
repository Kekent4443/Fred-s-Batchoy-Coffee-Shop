<?php

declare(strict_types=1);

function auth_history_export_columns(bool $failed): array
{
    return $failed
        ? ['Attempted at', 'Account name', 'Email tried', 'Role tried', 'Failure reason', 'Lock triggered', 'IP address', 'User agent']
        : ['Login at', 'Logout at', 'Duration seconds', 'Last activity', 'Name', 'Email', 'Role', 'IP address', 'User agent', 'Source', 'New IP', 'New browser signature'];
}

function auth_history_export_values(array $row, bool $failed): array
{
    if ($failed) {
        return [$row['attempted_at'], $row['account_name'] ?? '', $row['email_tried'], $row['role_tried'] ?? '', $row['failure_reason'],
            $row['lock_triggered'] ? 'Yes' : 'No', $row['ip_address'], $row['user_agent']];
    }
    $duration = $row['logout_at'] ? max(0, strtotime($row['logout_at']) - strtotime($row['login_at'])) : '';
    return [$row['login_at'], $row['logout_at'] ?? '', $duration, $row['last_activity_at'],
        $row['full_name'], $row['email'], $row['role'], $row['ip_address'], $row['user_agent'],
        $row['login_source'], $row['new_ip'] ? 'Yes' : 'No', $row['new_browser'] ? 'Yes' : 'No'];
}

function auth_history_export(array $filters, string $format, string $title): never
{
    require_login('administrator');
    $failed = $filters['tab'] === 'failed';
    $filename = 'authentication-' . $filters['tab'] . '-' . date('Ymd-His');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        fputcsv($output, auth_history_export_columns($failed));
        for ($page = 1; ; $page++) {
            $batch = auth_history_page($filters, $page, 100);
            foreach ($batch['rows'] as $row) {
                fputcsv($output, array_map('csv_spreadsheet_safe_value', auth_history_export_values($row, $failed)));
            }
            if ($page >= $batch['pages']) {
                break;
            }
        }
        fclose($output);
        exit;
    }

    // PDF is bounded to avoid exhausting PHP memory on a very large audit log;
    // CSV remains the complete, streaming export for the same filters.
    $rows = [];
    for ($page = 1; $page <= 10; $page++) {
        $batch = auth_history_page($filters, $page, 100);
        array_push($rows, ...$batch['rows']);
        if ($page >= $batch['pages']) {
            break;
        }
    }
    require_once __DIR__ . '/pdf_receipt.php';
    $pdf = auth_history_build_pdf($title, $rows, $failed, ($batch['total'] ?? 0) > count($rows));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

function auth_history_build_pdf(string $title, array $rows, bool $failed, bool $truncated = false): string
{
    $lines = [['Records Management - ' . $title, 14], ['Generated ' . date('Y-m-d H:i:s') . ' | Administrator audit export', 9]];
    if ($truncated) {
        $lines[] = ['Showing first 1000 filtered records. Use CSV for the complete export.', 9];
    }
    $lines[] = ['', 8];
    foreach ($rows as $row) {
        if ($failed) {
            $account = trim((string) ($row['account_name'] ?? ''));
            $lines[] = [sprintf('%s | %s%s | %s | %s', $row['attempted_at'], $account !== '' ? $account . ' <' : '', $row['email_tried'] . ($account !== '' ? '>' : ''), $row['role_tried'] ?: 'Unknown', $row['failure_reason']), 9];
            $lines[] = ['IP: ' . $row['ip_address'] . ($row['lock_triggered'] ? ' | Temporary lock triggered' : ''), 8];
        } else {
            $lines[] = [sprintf('%s | %s | %s <%s>', $row['login_at'], $row['role'], $row['full_name'], $row['email']), 9];
            $lines[] = ['Logout: ' . ($row['logout_at'] ?: 'Open') . ' | Last activity: ' . $row['last_activity_at'] . ' | IP: ' . $row['ip_address'], 8];
            $flags = ($row['new_ip'] ? 'New IP ' : '') . ($row['new_browser'] ? 'New browser signature' : '');
            if ($flags !== '') {
                $lines[] = [trim($flags), 8];
            }
        }
        $lines[] = ['Browser: ' . ($row['user_agent'] ?: 'Not provided'), 8];
        $lines[] = ['', 8];
    }
    if (!$rows) {
        $lines[] = ['No authentication records match these filters.', 9];
    }
    $wrapped = [];
    foreach ($lines as [$line, $size]) {
        foreach (explode("\n", wordwrap((string) $line, 125, "\n", true)) as $part) {
            $wrapped[] = [$part, $size];
        }
    }
    $pages = array_chunk($wrapped, 47);
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>'];
    $pageIds = [];
    $nextId = 5;
    foreach ($pages as $page) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $pageIds[] = $pageId;
        $commands = ['BT', '38 560 Td'];
        foreach ($page as [$line, $size]) {
            $commands[] = '/' . ($size > 9 ? 'F2' : 'F1') . ' ' . $size . ' Tf';
            $commands[] = '(' . receipt_pdf_text((string) $line) . ') Tj';
            $commands[] = '0 -11 Td';
        }
        $commands[] = 'ET';
        $stream = implode("\n", $commands) . "\n";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . $nextId . "\n0000000000 65535 f \n";
    for ($id = 1; $id < $nextId; $id++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$id]) . "\n";
    }
    return $pdf . 'trailer << /Size ' . $nextId . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
}
