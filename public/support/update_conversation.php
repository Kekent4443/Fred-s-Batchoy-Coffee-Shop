<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login(['administrator', 'manager', 'staff']);

if (!is_post()) {
    support_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $viewer = current_user();
    $conversationId = (int) post_scalar('conversation_id', 0);
    $action = (string) post_scalar('action', 'update');

    if ($action === 'note') {
        support_add_internal_note($conversationId, $viewer, (string) post_scalar('note', ''));
    } elseif ($action === 'update') {
        support_update_conversation($conversationId, $viewer, [
            'status' => (string) post_scalar('status', ''),
            'assigned_user_id' => (int) post_scalar('assigned_user_id', 0),
        ]);
    } else {
        throw new InvalidArgumentException('Unsupported support action.');
    }

    support_json([
        'success' => true,
        'conversation' => support_fetch_conversation($conversationId),
        'notes' => support_fetch_internal_notes($conversationId, $viewer),
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Support update failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to update the support conversation.'], 500);
}
