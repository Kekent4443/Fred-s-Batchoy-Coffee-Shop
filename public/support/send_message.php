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
    $messageId = support_send_message($conversationId, $viewer, (string) post_scalar('message', ''));
    $message = db_find(
        'SELECT m.*, u.full_name, u.role
         FROM support_messages m
         LEFT JOIN users u ON u.id = m.sender_id
         WHERE m.id = ? LIMIT 1',
        'i',
        [$messageId]
    );

    support_json([
        'success' => true,
        'message' => $message ? support_format_message($message) : null,
        'conversation' => support_fetch_conversation($conversationId),
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Support send message failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to send the message. Please try again.'], 500);
}
