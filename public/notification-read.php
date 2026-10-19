<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Vary: Cookie');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$viewer = current_user();

if (!$viewer) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to view notifications.']);
    exit;
}

$notificationId = (int) post_scalar('notification_id', 0);

if ($notificationId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
    exit;
}

$scopeUserId = notification_scope_user_id($viewer);
$typeFilter = normalize_notification_type_filter((string) post_scalar('type', ''));

$notification = $scopeUserId === null
    ? null
    : db_find('SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1', 'ii', [$notificationId, $scopeUserId]);

if (!$notification) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Notification was not found.']);
    exit;
}

$db = get_db();
$db->begin_transaction();

try {
    mark_notification_read($notificationId, $scopeUserId);
    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update the notification.']);
    exit;
}

$badgeState = notification_badge_state($viewer, true, true);

echo json_encode([
    'success' => true,
    'notification_id' => $notificationId,
    'status' => 'read',
    'link' => notification_absolute_url($viewer, $notification),
    'scope_user_id' => $badgeState['scope_user_id'],
    'unread_count' => $badgeState['unread_count'],
    'unread_label' => $badgeState['unread_label'],
    'menu_counts' => $badgeState['menu_counts'],
    'notification_metrics' => notification_metrics($scopeUserId, $typeFilter),
]);
