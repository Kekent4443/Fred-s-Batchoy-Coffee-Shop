<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Vary: Cookie');

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
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

enforce_request_rate_limit('notification_poll', (string) $viewer['id'], 180, 60);

$scopeUserId = notification_scope_user_id($viewer);
$badgeState = notification_badge_state($viewer, true, true);
$typeFilter = normalize_notification_type_filter((string) request('type', ''));
$notificationMetrics = notification_metrics($scopeUserId, $typeFilter);
$include = (string) request('include', '');
$notificationLimit = $include === '1' || $include === 'page' ? 100 : ($include === 'panel' ? 10 : 0);
$notifications = $notificationLimit > 0 ? array_map(static function (array $notification) use ($viewer): array {
    $link = notification_absolute_url($viewer, $notification);

    return [
        'id' => (int) $notification['id'],
        'title' => (string) $notification['title'],
        'message' => (string) $notification['message'],
        'type' => notification_type_label((string) ($notification['type'] ?? 'info')),
        'type_key' => (string) ($notification['type'] ?? 'info'),
        'status' => (string) ($notification['status'] ?? 'unread'),
        'created_at' => format_display_datetime($notification['created_at'] ?? null, 'No date recorded'),
        'source' => notification_source_label($notification),
        'link' => $link,
    ];
}, fetch_notifications_filtered($scopeUserId, $typeFilter, $notificationLimit)) : [];

echo json_encode([
    'success' => true,
    'scope_user_id' => $badgeState['scope_user_id'],
    'unread_count' => $badgeState['unread_count'],
    'unread_label' => $badgeState['unread_label'],
    'menu_counts' => $badgeState['menu_counts'],
    'notification_metrics' => $notificationMetrics,
    'notifications' => $notifications,
]);
