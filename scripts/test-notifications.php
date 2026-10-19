<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$recipients = db_select('SELECT id, role FROM users WHERE status = "active" AND deleted_at IS NULL ORDER BY id LIMIT 2');
if (count($recipients) < 2) {
    throw new RuntimeException('Notification smoke test needs two active users.');
}

$recipient = $recipients[0];
$other = $recipients[1];
$recipientId = (int) $recipient['id'];
$otherId = (int) $other['id'];
$title = 'Notification smoke test ' . bin2hex(random_bytes(8));
$message = 'Two independent events with identical text.';
$database = get_db();
$database->begin_transaction();

try {
    $before = unread_notification_count($recipientId);
    $otherBefore = unread_notification_count($otherId);
    create_notification($recipientId, $title, $message);
    create_notification($recipientId, $title, $message);

    if (unread_notification_count($recipientId) !== $before + 2) {
        throw new RuntimeException('Two events did not increase the unread count by two.');
    }
    if (unread_notification_count($otherId) !== $otherBefore) {
        throw new RuntimeException('Another user received this user’s notification.');
    }

    $created = db_select(
        'SELECT id FROM notifications WHERE user_id = ? AND title = ? ORDER BY id DESC',
        'is',
        [$recipientId, $title]
    );
    if (count($created) !== 2) {
        throw new RuntimeException('The test notifications were not stored separately.');
    }

    mark_notification_read((int) $created[0]['id'], $otherId);
    if (unread_notification_count($recipientId) !== $before + 2) {
        throw new RuntimeException('Another user was able to mark this notification as read.');
    }

    mark_notification_read((int) $created[0]['id'], $recipientId);
    if (unread_notification_count($recipientId) !== $before + 1) {
        throw new RuntimeException('Reading one notification did not decrease the count by one.');
    }

    mark_all_notifications_read($recipientId);
    $state = notification_badge_state($recipient, true, true);
    if ($state['unread_count'] !== 0 || $state['menu_counts']['notifications']['count'] !== 0) {
        throw new RuntimeException('The unread badge did not reach zero after reading all items.');
    }

    echo "Notification count, ownership, read, and zero-badge checks passed. All test changes rolled back.\n";
} finally {
    $database->rollback();
}
