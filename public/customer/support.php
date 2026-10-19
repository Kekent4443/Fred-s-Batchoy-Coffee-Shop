<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('customer');

$user = current_user();
$conversationId = (int) request('conversation_id', 0);
$conversations = support_fetch_conversations($user, [], 100);
$selectedConversation = $conversationId > 0 ? support_fetch_conversation($conversationId) : ($conversations[0] ?? null);

if ($selectedConversation && !support_can_access_conversation($user, $selectedConversation)) {
    $selectedConversation = null;
}

$selectedConversationId = $selectedConversation ? (int) $selectedConversation['id'] : 0;
$messages = $selectedConversationId > 0 ? support_fetch_messages($selectedConversationId, 0, $user) : [];
$orders = fetch_orders('customer', (int) $user['id']);
$products = fetch_products('', true);
$pageTitle = 'Customer Support';
$bodyClass = 'customer-body';

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<main class="customer-shell">
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>

    <section class="section">
        <?php render_page_header('Customer Support', 'Ask about products, orders, payments, deliveries, and account concerns.', 'chat', 'Support'); ?>

        <div
            class="support-layout"
            data-support-chat
            data-conversation-id="<?= e((string) $selectedConversationId) ?>"
            data-viewer-id="<?= e((string) $user['id']) ?>"
            data-viewer-role="customer"
            data-csrf="<?= e(csrf_token()) ?>"
            data-send-url="<?= e(url('support/send_message.php')) ?>"
            data-poll-url="<?= e(url('support/poll_messages.php')) ?>"
            data-auto-url="<?= e(url('support/check_auto_reply.php')) ?>"
            data-mark-read-url="<?= e(url('support/mark_read.php')) ?>"
            data-create-url="<?= e(url('support/create_conversation.php')) ?>"
        >
            <aside class="support-sidebar page-panel">
                <div class="card-head">
                    <h2>Conversations</h2>
                    <span class="badge badge-info"><?= e((string) count($conversations)) ?></span>
                </div>
                <div class="support-thread-list">
                    <?php foreach ($conversations as $conversation): ?>
                        <a class="support-thread <?= (int) $conversation['id'] === $selectedConversationId ? 'is-active' : '' ?> <?= priority_item_class($conversation) ?>" <?= priority_data_attr($conversation) ?> href="<?= e(url('customer/support.php?conversation_id=' . $conversation['id'])) ?>">
                            <strong><?= e((string) $conversation['subject']) ?></strong>
                            <?= priority_badge($conversation) ?>
                            <span><?= e(support_categories()[$conversation['category']] ?? 'Other') ?> · <?= e(support_statuses()[$conversation['status']] ?? $conversation['status']) ?></span>
                            <?php if ((int) ($conversation['unread_messages'] ?? 0) > 0): ?>
                                <small><?= e(notification_count_label((int) $conversation['unread_messages'])) ?> unread</small>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($conversations === []): ?>
                        <div class="empty-state">No support conversations yet.</div>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="support-panel page-panel">
                <div class="support-panel__header">
                    <div>
                        <h2><?= $selectedConversation ? e((string) $selectedConversation['subject']) : 'New support conversation' ?></h2>
                        <?php if ($selectedConversation): ?>
                            <p>
                                <?= e(support_categories()[$selectedConversation['category']] ?? 'Other') ?>
                                · <?= status_badge((string) $selectedConversation['status']) ?>
                                <?php if (!empty($selectedConversation['reference_no'])): ?>
                                    · Order <?= e((string) $selectedConversation['reference_no']) ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selectedConversation): ?>
                    <div class="support-messages" data-support-messages>
                        <?php foreach ($messages as $message): ?>
                            <?php
                            $formatted = support_format_message($message);
                            $isOwnMessage = (int) ($message['sender_id'] ?? 0) === (int) $user['id'];
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
                        <label class="sr-only" for="support-customer-reply">Your support message</label>
                        <textarea id="support-customer-reply" name="message" maxlength="2000" rows="3" placeholder="Type your message..." required></textarea>
                        <button class="btn btn-primary" type="submit">Send Message</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state">Select a conversation or start a new one.</div>
                <?php endif; ?>
            </section>

            <aside class="support-new page-panel">
                <div class="card-head"><h2>Start New</h2></div>
                <form data-support-create-form>
                    <?= csrf_field() ?>
                    <label for="support-new-category">Concern Category</label>
                    <select id="support-new-category" name="category" required>
                        <?php foreach (support_categories() as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="support-new-subject">Subject</label>
                    <input id="support-new-subject" type="text" name="subject" maxlength="180" placeholder="Short subject" required>
                    <label for="support-new-order">Related Order</label>
                    <select id="support-new-order" name="order_id">
                        <option value="0">No linked order</option>
                        <?php foreach ($orders as $order): ?>
                            <option value="<?= e((string) $order['id']) ?>"><?= e($order['reference_no']) ?> · <?= e(ucwords(str_replace('_', ' ', $order['order_status']))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="support-new-product">Related Product</label>
                    <select id="support-new-product" name="product_id">
                        <option value="0">No linked product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= e((string) $product['id']) ?>"><?= e((string) $product['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="support-new-message">Message</label>
                    <textarea id="support-new-message" name="message" maxlength="2000" rows="5" placeholder="Describe your concern..." required></textarea>
                    <button class="btn btn-primary" type="submit">Create Conversation</button>
                    <p class="support-form-status" data-support-status role="status" aria-live="polite"></p>
                </form>
            </aside>
        </div>
    </section>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
