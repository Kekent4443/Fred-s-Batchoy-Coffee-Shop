<?php

declare(strict_types=1);

const MIGRATION_TRACKER_FILE = '2026_09_29_schema_migrations.sql';
const MIGRATION_BASELINE_MARKER = '__baseline_reviewed__';

function migration_assert_canonical_inventory(array $files): void
{
    // Historical SQL is part of the deployment audit trail. A missing file
    // must never make a migration silently disappear from --status or --run.
    $required = [
        '2026_07_05_shop_location_management.sql',
        '2026_07_05_contact_us_inquiry_management.sql',
        '2026_07_05_support_chat.sql',
        '2026_07_06_delivery_workflow_improvements.sql',
        '2026_07_06_vat_inclusive_bulk_discounts.sql',
        '2026_07_14_conditional_user_deletion.sql',
        '2026_07_14_gcash_payment_accounts.sql',
        '2026_07_14_notification_system.sql',
        '2026_07_14_notification_url_repair.sql',
        '2026_07_14_thermal_receipt_settings.sql',
        '2026_07_15_password_reset_verification_codes.sql',
        '2026_07_16_password_reset_email_queue.sql',
        '2026_07_19_integrity_workflow_repair.sql',
        '2026_08_01_customer_verification_ui.sql',
        '2026_08_07_default_58mm_receipt.sql',
        '2026_08_07_single_administrator_policy.sql',
        '2026_09_21_structured_customer_delivery_address.sql',
        '2026_09_22_global_site_logo.sql',
        '2026_09_24_default_80mm_receipt.sql',
        '2026_09_28_authentication_activity.sql',
        '2026_09_28_customer_email_verification_codes.sql',
        '2026_09_29_runtime_schema_alignment.sql',
        MIGRATION_TRACKER_FILE,
        '2026_10_05_manager_product_shop_scope.sql',
        '2026_10_06_password_recovery_repair.sql',
        '2026_10_07_default_58mm_thermal_print.sql',
    ];
    $missing = array_values(array_diff($required, array_keys($files)));
    if ($missing !== []) {
        throw new RuntimeException('Canonical migration history is incomplete: ' . implode(', ', $missing));
    }
}

function migration_files(string $directory): array
{
    $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql');
    if ($files === false) {
        throw new RuntimeException('Unable to read the migration directory.');
    }
    $result = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (!preg_match('/^\d{4}_\d{2}_\d{2}_[a-z0-9_]+\.sql$/', $name)) {
            throw new RuntimeException('Unexpected migration filename: ' . $name);
        }
        $result[$name] = $path;
    }
    ksort($result, SORT_STRING);
    // The same-day contact upgrade needs shop_locations, but its original
    // filename sorts first. Preserve historical filenames while ordering the
    // dependency deterministically.
    $shop = '2026_07_05_shop_location_management.sql';
    $contact = '2026_07_05_contact_us_inquiry_management.sql';
    if (isset($result[$shop], $result[$contact])) {
        $ordered = [];
        foreach ($result as $name => $path) {
            if ($name === $contact) {
                continue;
            }
            $ordered[$name] = $path;
            if ($name === $shop) {
                $ordered[$contact] = $result[$contact];
            }
        }
        $result = $ordered;
    }
    return $result;
}

function migration_assert_supported_base_schema(mysqli $db): void
{
    // Dated upgrades assume the application's original core schema exists.
    // A completely empty database must use the fresh-install schema first.
    $required = [
        'users', 'categories', 'products', 'orders', 'order_items',
        'transactions', 'deliveries', 'notifications', 'password_resets',
        'email_notifications', 'audit_logs',
    ];
    $result = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $present = array_fill_keys(array_column($result->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME'), true);
    $missing = array_values(array_filter($required, static fn (string $name): bool => !isset($present[$name])));
    if ($missing !== []) {
        throw new RuntimeException('Core application schema is missing: ' . implode(', ', $missing) . '. Import the fresh-install schema only into an empty database, or restore the correct core backup before running dated migrations.');
    }
}

function migration_checksum(string $path): string
{
    $checksum = hash_file('sha256', $path);
    if ($checksum === false) {
        throw new RuntimeException('Unable to checksum migration: ' . basename($path));
    }
    return $checksum;
}

function migration_has_sql(string $sql): bool
{
    return trim((string) preg_replace('/^\s*(?:--[^\r\n]*|#[^\r\n]*)$/m', '', $sql)) !== '';
}

function migration_execute_chunk(mysqli $db, string $sql): void
{
    if (!migration_has_sql($sql)) {
        return;
    }
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function migration_execute_sql(mysqli $db, string $sql): void
{
    // DELIMITER is a mysql/phpMyAdmin client directive, not server SQL. The
    // single historical routine migration uses $$ for compound statements.
    $parts = preg_split('/^\s*DELIMITER[ \t]+(\S+)[ \t]*$/mi', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        throw new RuntimeException('Unable to parse migration SQL.');
    }
    migration_execute_chunk($db, (string) array_shift($parts));
    for ($i = 0; $i < count($parts); $i += 2) {
        $delimiter = $parts[$i];
        $chunk = $parts[$i + 1] ?? '';
        if ($delimiter === ';') {
            migration_execute_chunk($db, $chunk);
            continue;
        }
        if ($delimiter !== '$$') {
            throw new RuntimeException('Unsupported SQL delimiter: ' . $delimiter);
        }
        $statements = explode($delimiter, $chunk);
        $tail = array_pop($statements);
        if (migration_has_sql($tail)) {
            throw new RuntimeException('Unterminated SQL statement after DELIMITER directive.');
        }
        foreach ($statements as $statement) {
            if (migration_has_sql($statement)) {
                $db->query(trim($statement));
            }
        }
    }
}

function migration_tracking_exists(mysqli $db): bool
{
    $result = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations' LIMIT 1");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function migration_applied(mysqli $db): array
{
    if (!migration_tracking_exists($db)) {
        return [];
    }
    $rows = $db->query('SELECT migration, checksum FROM schema_migrations')->fetch_all(MYSQLI_ASSOC);
    $applied = [];
    foreach ($rows as $row) {
        $applied[(string) $row['migration']] = (string) $row['checksum'];
    }
    return $applied;
}

function migration_record(mysqli $db, string $name, string $checksum, int $batch): void
{
    $statement = $db->prepare('INSERT INTO schema_migrations (migration, checksum, batch) VALUES (?, ?, ?)');
    $statement->bind_param('ssi', $name, $checksum, $batch);
    $statement->execute();
}

function migration_install_tracker(mysqli $db, array $files): void
{
    $path = $files[MIGRATION_TRACKER_FILE] ?? null;
    if ($path === null) {
        throw new RuntimeException('The canonical schema-migrations SQL file is missing.');
    }
    $checksum = migration_checksum($path);
    $applied = migration_applied($db);
    if (isset($applied[MIGRATION_TRACKER_FILE])) {
        if (!hash_equals($applied[MIGRATION_TRACKER_FILE], $checksum)) {
            throw new RuntimeException('The recorded tracker migration has changed.');
        }
        return;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read tracker migration.');
    }
    migration_execute_sql($db, $sql);
    migration_record($db, MIGRATION_TRACKER_FILE, $checksum, 0);
}

function migration_baseline(mysqli $db, array $files, string $manifestPath): int
{
    $contents = file_get_contents($manifestPath);
    if ($contents === false) {
        throw new RuntimeException('Cannot read the baseline manifest.');
    }
    $entries = [];
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $fields = preg_split('/\s+/', $line);
        if (count($fields) !== 3 || $fields[2] !== 'APPLIED') {
            throw new RuntimeException('Baseline entries must be: filename SHA256 APPLIED.');
        }
        [$name, $checksum] = $fields;
        if (!isset($files[$name]) || isset($entries[$name]) || !preg_match('/^[a-f0-9]{64}$/', $checksum)
            || !hash_equals(migration_checksum($files[$name]), $checksum)) {
            throw new RuntimeException('Invalid or changed baseline entry: ' . $name);
        }
        $entries[$name] = $checksum;
    }
    if ($entries === []) {
        throw new RuntimeException('Baseline manifest is empty; list every verified historical migration explicitly.');
    }
    $applied = migration_applied($db);
    if (!isset($applied[MIGRATION_TRACKER_FILE])) {
        throw new RuntimeException('Install the tracker before baselining.');
    }
    $db->begin_transaction();
    try {
        foreach ($entries as $name => $checksum) {
            if (isset($applied[$name])) {
                if (!hash_equals($applied[$name], $checksum)) {
                    throw new RuntimeException('Baseline conflicts with recorded migration: ' . $name);
                }
                continue;
            }
            migration_record($db, $name, $checksum, 0);
        }
        if (!isset($applied[MIGRATION_BASELINE_MARKER])) {
            migration_record($db, MIGRATION_BASELINE_MARKER, hash('sha256', $contents), 0);
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
    return count($entries);
}

function migration_run_pending(mysqli $db, array $files, callable $report): int
{
    if (!migration_tracking_exists($db)) {
        throw new RuntimeException('Migration tracker missing. Install it and baseline this database first.');
    }
    $applied = migration_applied($db);
    if (!isset($applied[MIGRATION_BASELINE_MARKER])) {
        throw new RuntimeException('Existing schema has not been reviewed and baselined. Refusing to rerun historical SQL.');
    }
    foreach ($files as $name => $path) {
        if (isset($applied[$name]) && !hash_equals($applied[$name], migration_checksum($path))) {
            throw new RuntimeException('Applied migration has changed: ' . $name);
        }
    }
    $batch = (int) $db->query('SELECT COALESCE(MAX(batch), 0) AS n FROM schema_migrations')->fetch_assoc()['n'] + 1;
    $count = 0;
    foreach ($files as $name => $path) {
        $checksum = migration_checksum($path);
        if (isset($applied[$name])) {
            $report('skip ' . $name);
            continue;
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration: ' . $name);
        }
        $report('apply ' . $name);
        // MySQL DDL implicitly commits. Never mark a partially failed file as
        // applied; review/repair it manually before retrying non-idempotent SQL.
        migration_execute_sql($db, $sql);
        migration_record($db, $name, $checksum, $batch);
        $report('done ' . $name);
        $count++;
    }
    return $count;
}
