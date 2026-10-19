<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();

enforce_request_rate_limit('support_poll', (string) current_user()['id'], 240, 60);

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    support_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $viewer = current_user();
    $conversationId = (int) request('conversation_id', 0);
    $afterId = (int) request('after_id', 0);
    support_require_conversation_access($conversationId, $viewer);

    $messages = array_map(
        static fn (array $message): array => support_format_message($message),
        support_fetch_messages($conversationId, $afterId, $viewer)
    );

    $supportCount = support_pending_count_for_user($viewer);

    support_json([
        'success' => true,
        'messages' => $messages,
        'conversation' => support_fetch_conversation($conversationId),
        'menu_counts' => [
            'support' => [
                'count' => $supportCount,
                'label' => notification_count_label($supportCount),
            ],
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    support_json(['success' => false, 'message' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('Support poll failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to load support messages.'], 500);
}
