<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

if (!is_post()) {
    support_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $conversationId = support_create_conversation(current_user(), [
        'category' => (string) post_scalar('category', 'other'),
        'subject' => (string) post_scalar('subject', ''),
        'message' => (string) post_scalar('message', ''),
        'order_id' => (int) post_scalar('order_id', 0),
        'product_id' => (int) post_scalar('product_id', 0),
    ]);

    support_json([
        'success' => true,
        'conversation_id' => $conversationId,
        'redirect_url' => url('customer/support.php?conversation_id=' . $conversationId),
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Support create conversation failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to create the support conversation. Please try again.'], 500);
}
