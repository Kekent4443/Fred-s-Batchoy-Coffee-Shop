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
    echo json_encode(['success' => false, 'message' => 'Please log in to manage notifications.']);
    exit;
}

$action = (string) post_scalar('action', '');
$scopeUserId = notification_scope_user_id($viewer);
$typeFilter = normalize_notification_type_filter((string) post_scalar('type', ''));

if ($scopeUserId === null || !in_array($action, ['mark_all_read', 'delete_read'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid notification action.']);
    exit;
}

$db = get_db();
$db->begin_transaction();

try {
    $affected = $action === 'mark_all_read'
        ? mark_all_notifications_read($scopeUserId, $typeFilter)
        : delete_read_notifications($scopeUserId);
    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update notifications.']);
    exit;
}

$badgeState = notification_badge_state($viewer, true, true);

echo json_encode([
    'success' => true,
    'action' => $action,
    'affected' => $affected,
    'scope_user_id' => $badgeState['scope_user_id'],
    'unread_count' => $badgeState['unread_count'],
    'unread_label' => $badgeState['unread_label'],
    'menu_counts' => $badgeState['menu_counts'],
    'notification_metrics' => notification_metrics($scopeUserId, $typeFilter),
]);
