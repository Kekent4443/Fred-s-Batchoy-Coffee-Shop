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
    support_require_conversation_access($conversationId, $viewer);
    $message = support_run_auto_reply($conversationId);

    support_json([
        'success' => true,
        'message' => $message,
        'conversation' => support_fetch_conversation($conversationId),
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('Support auto reply check failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to check automated support replies.'], 500);
}
