<?php
$viewer = current_user();
$filters = [
    'status' => (string) request('status', ''),
    'category' => (string) request('category', ''),
    'search' => (string) request('search', ''),
    'assigned_user_id' => (int) request('assigned_user_id', 0),
    'unread' => (string) request('unread', ''),
];
$conversationId = (int) request('conversation_id', 0);
$conversations = support_fetch_conversations($viewer, $filters, 100);
$selectedConversation = $conversationId > 0 ? support_fetch_conversation($conversationId) : ($conversations[0] ?? null);

if ($selectedConversation && !support_can_access_conversation($viewer, $selectedConversation)) {
    $selectedConversation = null;
}

$selectedConversationId = $selectedConversation ? (int) $selectedConversation['id'] : 0;
$messages = $selectedConversationId > 0 ? support_fetch_messages($selectedConversationId, 0, $viewer) : [];
$notes = $selectedConversationId > 0 ? support_fetch_internal_notes($selectedConversationId, $viewer) : [];
$staffUsers = array_values(array_filter(
    array_merge(fetch_users('staff'), fetch_users('manager'), fetch_users('administrator')),
    static fn (array $user): bool => ($user['status'] ?? '') === 'active'
));
?>
<div
    class="support-layout support-layout--console"
    data-support-chat
    data-conversation-id="<?= e((string) $selectedConversationId) ?>"
    data-viewer-id="<?= e((string) ($viewer['id'] ?? 0)) ?>"
    data-viewer-role="<?= e((string) ($viewer['role'] ?? '')) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
    data-send-url="<?= e(url('support/send_message.php')) ?>"
    data-poll-url="<?= e(url('support/poll_messages.php')) ?>"
    data-auto-url="<?= e(url('support/check_auto_reply.php')) ?>"
    data-mark-read-url="<?= e(url('support/mark_read.php')) ?>"
>
    <aside class="support-sidebar page-panel">
        <form method="get" class="support-filter">
            <label class="sr-only" for="support-filter-search">Search support conversations</label>
            <input id="support-filter-search" type="search" name="search" placeholder="Search customer, subject, order..." value="<?= e($filters['search']) ?>">
            <label class="sr-only" for="support-filter-status">Filter by status</label>
            <select id="support-filter-status" name="status">
                <option value="">All statuses</option>
                <?php foreach (support_statuses() as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="sr-only" for="support-filter-category">Filter by category</label>
            <select id="support-filter-category" name="category">
                <option value="">All categories</option>
                <?php foreach (support_categories() as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="checkbox-row"><input type="checkbox" name="unread" value="1" <?= $filters['unread'] === '1' ? 'checked' : '' ?>> Unread only</label>
            <button class="btn btn-secondary" type="submit">Filter</button>
        </form>
        <div class="support-thread-list">
            <?php foreach ($conversations as $conversation): ?>
                <a class="support-thread <?= (int) $conversation['id'] === $selectedConversationId ? 'is-active' : '' ?> <?= priority_item_class($conversation) ?>" <?= priority_data_attr($conversation) ?> href="<?= e(url(role_folder($viewer['role']) . '/support.php?conversation_id=' . $conversation['id'])) ?>">
                    <strong><?= e((string) $conversation['subject']) ?></strong>
                    <?= priority_badge($conversation) ?>
                    <span><?= e((string) $conversation['customer_name']) ?> · <?= e(support_statuses()[$conversation['status']] ?? $conversation['status']) ?></span>
                    <?php if ((int) ($conversation['unread_messages'] ?? 0) > 0): ?>
                        <small><?= e(notification_count_label((int) $conversation['unread_messages'])) ?> unread</small>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ($conversations === []): ?>
                <div class="empty-state">No support conversations found.</div>
            <?php endif; ?>
        </div>
    </aside>

    <section class="support-panel page-panel">
        <?php if ($selectedConversation): ?>
            <div class="support-panel__header">
                <div>
                    <h2><?= e((string) $selectedConversation['subject']) ?></h2>
                    <p>
                        <?= e((string) $selectedConversation['customer_name']) ?>
                        · <?= e(support_categories()[$selectedConversation['category']] ?? 'Other') ?>
                        · <?= status_badge((string) $selectedConversation['status']) ?>
                    </p>
                </div>
            </div>
            <div class="support-context-grid">
                <article><strong>Order</strong><span><?= !empty($selectedConversation['reference_no']) ? e((string) $selectedConversation['reference_no']) : 'Not linked' ?></span></article>
                <article><strong>Product</strong><span><?= !empty($selectedConversation['product_name']) ? e((string) $selectedConversation['product_name']) : 'Not linked' ?></span></article>
                <article><strong>Assigned</strong><span><?= !empty($selectedConversation['assigned_name']) ? e((string) $selectedConversation['assigned_name']) : 'Unassigned' ?></span></article>
            </div>
            <div class="support-messages" data-support-messages>
                <?php foreach ($messages as $message): ?>
                    <?php
                    $formatted = support_format_message($message);
                    $isOwnMessage = (int) ($message['sender_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
                    ?>
                    <article class="support-message <?= $isOwnMessage ? 'is-own' : '' ?> <?= $formatted['is_automated'] ? 'is-automated' : '' ?>" data-message-id="<?= e((string) $formatted['id']) ?>">
                        <div class="support-message__meta">
                            <strong><?= e($formatted['sender_label']) ?></strong>
                            <span><?= e($formatted['created_label']) ?></span>
                        </div>
                        <p><?= nl2br(e($formatted['message'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <form class="support-compose" data-support-send-form>
                <?= csrf_field() ?>
                <input type="hidden" name="conversation_id" value="<?= e((string) $selectedConversationId) ?>">
                <label class="sr-only" for="support-staff-reply">Support reply</label>
                <textarea id="support-staff-reply" name="message" maxlength="2000" rows="3" placeholder="Reply as <?= e(role_label((string) $viewer['role'])) ?>..." required></textarea>
                <button class="btn btn-primary" type="submit">Send Reply</button>
            </form>
        <?php else: ?>
            <div class="empty-state">Select a support conversation.</div>
        <?php endif; ?>
    </section>

    <aside class="support-new page-panel">
        <?php if ($selectedConversation): ?>
            <div class="card-head"><h2>Manage</h2></div>
            <form method="post" action="<?= e(url('support/update_conversation.php')) ?>" class="support-control-form" data-support-update-form>
                <?= csrf_field() ?>
                <input type="hidden" name="conversation_id" value="<?= e((string) $selectedConversationId) ?>">
                <label for="support-conversation-status">Status</label>
                <select id="support-conversation-status" name="status">
                    <?php foreach (support_statuses() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedConversation['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (in_array($viewer['role'], ['administrator', 'manager'], true)): ?>
                    <label for="support-assigned-user">Assign Staff</label>
                    <select id="support-assigned-user" name="assigned_user_id">
                        <option value="0">Unassigned</option>
                        <?php foreach ($staffUsers as $staff): ?>
                            <option value="<?= e((string) $staff['id']) ?>" <?= (int) ($selectedConversation['assigned_user_id'] ?? 0) === (int) $staff['id'] ? 'selected' : '' ?>>
                                <?= e($staff['full_name'] . ' · ' . role_label($staff['role'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button class="btn btn-secondary" type="submit">Update</button>
            </form>
            <form method="post" action="<?= e(url('support/update_conversation.php')) ?>" class="support-control-form" data-support-note-form>
                <?= csrf_field() ?>
                <input type="hidden" name="conversation_id" value="<?= e((string) $selectedConversationId) ?>">
                <input type="hidden" name="action" value="note">
                <label for="support-internal-note">Internal Note</label>
                <textarea id="support-internal-note" name="note" rows="4" maxlength="2000" placeholder="Customer cannot see internal notes."></textarea>
                <button class="btn btn-ghost" type="submit">Add Note</button>
            </form>
            <div class="support-notes">
                <?php foreach ($notes as $note): ?>
                    <article>
                        <strong><?= e((string) $note['full_name']) ?></strong>
                        <span><?= e(format_display_datetime($note['created_at'] ?? null)) ?></span>
                        <p><?= nl2br(e((string) $note['note'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </aside>
</div>
