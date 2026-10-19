<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();

enforce_request_rate_limit('support_list', (string) current_user()['id'], 120, 60);

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    support_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $viewer = current_user();
    $filters = [
        'status' => (string) request('status', ''),
        'category' => (string) request('category', ''),
        'search' => (string) request('search', ''),
        'assigned_user_id' => (int) request('assigned_user_id', 0),
        'order_id' => (int) request('order_id', 0),
        'product_id' => (int) request('product_id', 0),
        'unread' => (string) request('unread', ''),
    ];

    support_json([
        'success' => true,
        'conversations' => support_fetch_conversations($viewer, $filters, 100),
    ]);
} catch (Throwable $exception) {
    error_log('Support list failed: ' . $exception->getMessage());
    support_json(['success' => false, 'message' => 'Unable to load support conversations.'], 500);
}
