<?php

declare(strict_types=1);

// A deployment command, never a web endpoint or bootstrap hook.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/migrations/runner.php';

$command = $argv[1] ?? '--help';
if ($command === '--help' || count($argv) !== 2 || !in_array($command, ['--status', '--install-tracker', '--run'], true) && !str_starts_with($command, '--baseline=')) {
    echo "Usage: php scripts/migrate.php --status|--install-tracker|--baseline=reviewed-manifest.txt|--run\n";
    echo "Review database/MIGRATION_AUDIT.md before baselining an existing database.\n";
    exit($command === '--help' ? 0 : 2);
}

try {
    $files = migration_files(dirname(__DIR__) . '/database/migrations');
    migration_assert_canonical_inventory($files);
    $db = get_db();
    $databaseName = (string) $db->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
    $lockName = 'freds:migrate:' . substr(hash('sha256', $databaseName), 0, 48);
    $locked = $db->query("SELECT GET_LOCK('" . $lockName . "', 0) AS acquired")->fetch_assoc();
    if ((int) ($locked['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another migration process is running for this database.');
    }
    try {
        if ($command === '--status') {
            if (!migration_tracking_exists($db)) {
                echo "schema_migrations is not installed. No historical migration will run automatically.\n";
            }
            $applied = migration_applied($db);
            foreach ($files as $name => $path) {
                $checksum = migration_checksum($path);
                $state = isset($applied[$name]) ? 'applied' : 'pending';
                if (isset($applied[$name]) && !hash_equals($applied[$name], $checksum)) {
                    $state = 'CHANGED';
                }
                echo $state . "\t" . $name . "\t" . $checksum . "\n";
            }
            echo isset($applied[MIGRATION_BASELINE_MARKER]) ? "Baseline reviewed.\n" : "Baseline NOT reviewed; --run is locked.\n";
        } elseif ($command === '--install-tracker') {
            migration_install_tracker($db, $files);
            echo "schema_migrations installed/tracked. Review and baseline existing schema before --run.\n";
        } elseif (str_starts_with($command, '--baseline=')) {
            if (!migration_tracking_exists($db)) {
                throw new RuntimeException('Run --install-tracker first.');
            }
            $manifestPath = substr($command, strlen('--baseline='));
            if ($manifestPath === '') {
                throw new RuntimeException('A reviewed baseline manifest path is required.');
            }
            migration_assert_supported_base_schema($db);
            $count = migration_baseline($db, $files, $manifestPath);
            echo "Recorded $count explicitly reviewed migration(s) and unlocked pending migrations.\n";
        } else {
            migration_assert_supported_base_schema($db);
            $count = migration_run_pending($db, $files, static function (string $message): void {
                echo $message . "\n";
            });
            echo "Applied $count pending migration(s).\n";
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('" . $lockName . "')");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
}
