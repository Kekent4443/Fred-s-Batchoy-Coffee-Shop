<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();

if (!is_post()) {
    support_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $viewer = current_user();
    $conversationId = (int) post_scalar('conversation_id', 0);
    support_mark_messages_read($conversationId, $viewer);
    $badgeState = notification_badge_state($viewer, true, true);

    support_json([
        'success' => true,
        'scope_user_id' => $badgeState['scope_user_id'],
        'unread_count' => $badgeState['unread_count'],
        'unread_label' => $badgeState['unread_label'],
        'menu_counts' => $badgeState['menu_counts'],
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('Support mark read failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to mark support messages as read.'], 500);
}
