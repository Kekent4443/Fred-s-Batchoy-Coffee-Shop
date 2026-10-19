<?php

declare(strict_types=1);

function flash(string $key, string $message, string $type = 'info'): void
{
    $_SESSION['flash'][$key] = [
        'message' => $message,
        'type' => $type,
    ];
}

function get_flash(?string $key = null): array
{
    $messages = $_SESSION['flash'] ?? [];

    if ($key !== null) {
        $message = $messages[$key] ?? [];
        unset($_SESSION['flash'][$key]);

        return $message;
    }

    unset($_SESSION['flash']);

    return $messages;
}

