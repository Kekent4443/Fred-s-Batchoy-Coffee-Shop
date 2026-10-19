<?php

declare(strict_types=1);

// Copy this file to mail.local.php and enter a newly generated Brevo SMTP key.
// mail.local.php contains secrets and must never be committed or shared.
// Equivalent environment variables: SMTP_HOST, SMTP_PORT, SMTP_USERNAME,
// SMTP_PASSWORD, SMTP_FROM_EMAIL, SMTP_FROM_NAME, SMTP_ENCRYPTION.
// Use starttls for ports 587/2525; use smtps only with port 465.
return [
    'host' => 'smtp-relay.brevo.com',
    'port' => 587,
    'encryption' => 'starttls',
    'username' => 'your-brevo-smtp-username',
    'password' => 'your-brevo-smtp-key',
    'from_address' => 'your-verified-sender@example.com',
    'from_name' => "Fred's Batchoy and Coffee Shop",
    'webhook_secret' => 'replace-with-a-long-random-webhook-secret',
    // Optional until deployed: use a separate 32+ character random secret.
    // Without it, issued six-digit codes retain the legacy password-hash format.
    'email_code_hmac_key' => '',
];
