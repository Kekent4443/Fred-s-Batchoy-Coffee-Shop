<?php

declare(strict_types=1);

function private_mail_settings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $configFile = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'private'
        . DIRECTORY_SEPARATOR . 'mail.local.php';

    if (!is_file($configFile)) {
        return [];
    }

    $loaded = require $configFile;

    $settings = is_array($loaded)
        ? $loaded
        : [];

    return $settings;
}


function mail_setting(
    string $environmentName,
    string $privateKey,
    string $default = ''
): string {
    $environmentValue = getenv($environmentName);

    if (
        $environmentValue !== false &&
        trim((string) $environmentValue) !== ''
    ) {
        return trim((string) $environmentValue);
    }

    $privateSettings = private_mail_settings();

    return trim(
        (string) ($privateSettings[$privateKey] ?? $default)
    );
}

function mail_settings(): array
{
    $port = filter_var(
        mail_setting('SMTP_PORT', 'port', mail_setting('MAIL_PORT', 'port', '587')),
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ]
    );

    return [
        'host' => mail_setting(
            'SMTP_HOST',
            'host',
            mail_setting('MAIL_HOST', 'host', 'smtp-relay.brevo.com')
        ),

        'port' => $port !== false
            ? $port
            : 587,

        'username' => mail_setting(
            'SMTP_USERNAME',
            'username',
            mail_setting('MAIL_USERNAME', 'username', '')
        ),

        'password' => mail_setting(
            'SMTP_PASSWORD',
            'password',
            mail_setting('MAIL_PASSWORD', 'password', '')
        ),

        'from_address' => mail_setting(
            'SMTP_FROM_EMAIL',
            'from_address',
            mail_setting('MAIL_FROM_ADDRESS', 'from_address', '')
        ),

        'from_name' => mail_setting(
            'SMTP_FROM_NAME',
            'from_name',
            mail_setting('MAIL_FROM_NAME', 'from_name', "Fred's Batchoy and Coffee Shop")
        ),
        'encryption' => strtolower(mail_setting(
            'SMTP_ENCRYPTION',
            'encryption',
            mail_setting('MAIL_ENCRYPTION', 'encryption', $port === 465 ? 'smtps' : 'starttls')
        )),
    ];
}

function mail_configuration_errors(): array
{
    $settings = mail_settings();
    $errors = [];

    if (trim((string) $settings['host']) !== 'smtp-relay.brevo.com') {
        $errors[] = 'SMTP_HOST must be smtp-relay.brevo.com';
    }
    if ((int) $settings['port'] !== 587) {
        $errors[] = 'SMTP_PORT must be 587';
    }
    if ((string) $settings['encryption'] !== 'starttls') {
        $errors[] = 'SMTP_ENCRYPTION must be starttls';
    }
    if (trim((string) $settings['username']) === '') {
        $errors[] = 'SMTP_USERNAME is missing';
    }
    if (trim((string) $settings['password']) === '') {
        $errors[] = 'SMTP_PASSWORD is missing';
    }
    if (filter_var((string) $settings['from_address'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'SMTP_FROM_EMAIL is missing or invalid';
    }
    if (!extension_loaded('openssl')) {
        $errors[] = 'the PHP OpenSSL extension is unavailable';
    }

    return $errors;
}

function mail_log_message(string $message): string
{
    $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? 'Unknown mail error.';
    $settings = mail_settings();

    // Preserve useful provider diagnostics while ensuring a credential cannot
    // be copied into a web-server or shared-hosting error log.
    foreach (['username', 'password'] as $sensitiveKey) {
        $value = trim((string) ($settings[$sensitiveKey] ?? ''));
        if ($value !== '') {
            $message = str_replace($value, '[redacted]', $message);
        }
    }

    return mb_substr(trim($message), 0, 1000);
}

function transactional_mail_is_configured(): bool
{
    return mail_configuration_errors() === [];
}
