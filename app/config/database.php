<?php

declare(strict_types=1);

function private_database_settings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $settings = [];
    $configFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'database.local.php';

    if (is_file($configFile)) {
        $loaded = require $configFile;
        $settings = is_array($loaded) ? $loaded : [];
    }

    return $settings;
}

function database_setting(string $environmentName, string $privateKey, string $default = ''): string
{
    $environmentValue = getenv($environmentName);

    if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
        return trim((string) $environmentValue);
    }

    return trim((string) (private_database_settings()[$privateKey] ?? $default));
}

function get_db(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // DATABASE SETUP MARKER (localhost -> live hosting):
    // Do not put credentials in this tracked source file. For localhost, set
    // APP_ENV=local and use DB_* environment variables or the ignored
    // private/database.local.php file. On the live host, set APP_ENV=production
    // and supply its actual DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS
    // through that same private file or the host's environment settings.
    // Environment variables take precedence over private/database.local.php.
    // See README.md for localhost setup and repository upload instructions.
    $production = defined('APP_ENV') && APP_ENV === 'production';
    $database = database_setting('DB_NAME', 'name', $production ? '' : 'freds_sales_system');
    $host = database_setting('DB_HOST', 'host', $production ? '' : '127.0.0.1');
    $user = database_setting('DB_USER', 'user', $production ? '' : 'root');
    $password = database_setting('DB_PASS', 'password');
    $port = (int) database_setting('DB_PORT', 'port', '3306');

    // A missing deployment configuration must never select a privileged
    // local account or an unintended database in production.
    if ($production && ($database === '' || $host === '' || $user === '' || $password === '')) {
        throw new RuntimeException('Database connection is not configured.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Database port is invalid.');
    }

    try {
        // Hosted databases are pre-created and usually deny CREATE DATABASE.
        $connection = new mysqli($host, $user, $password, $database, $port);
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1049) {
            throw $exception;
        }

        // Database creation is a schema change. Allow the old local first-run
        // convenience only when non-production auto-migration is explicit.
        $localHosts = ['localhost', '127.0.0.1', '::1'];
        if (!in_array(strtolower($host), $localHosts, true)
            || !function_exists('schema_maintenance_enabled')
            || !schema_maintenance_enabled()) {
            throw $exception;
        }

        $connection = new mysqli($host, $user, $password, '', $port);
        $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
        $connection->query('CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $connection->select_db($database);
    }

    $connection->set_charset('utf8mb4');

    return $connection;
}
