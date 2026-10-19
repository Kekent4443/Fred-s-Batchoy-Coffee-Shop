<?php
$notificationTypeOptions = notification_type_options();
$notificationBasePath = $notificationBasePath ?? '';
$notificationTypeFilter = normalize_notification_type_filter((string) ($notificationTypeFilter ?? ''));
$notificationList = $notificationList ?? [];
$notificationViewer = $notificationViewer ?? current_user();
$notificationMetrics = $notificationMetrics ?? notification_metrics(
    $notificationViewer ? notification_scope_user_id($notificationViewer) : null,
    $notificationTypeFilter
);
$notificationTotalCount = (int) ($notificationMetrics['total'] ?? 0);
$notificationUnreadCount = (int) ($notificationMetrics['unread'] ?? 0);
$notificationReadCount = (int) ($notificationMetrics['read'] ?? 0);
$notificationTodayCount = (int) ($notificationMetrics['today'] ?? 0);
?>
<div
    class="notification-page"
    data-notification-page
    data-notification-read-url="<?= e(url('notification-read.php')) ?>"
    data-notification-bulk-url="<?= e(url('notification-bulk.php')) ?>"
    data-notification-count-url="<?= e(url('notification-count.php')) ?>"
    data-notification-csrf="<?= e(csrf_token()) ?>"
    data-notification-type-filter="<?= e($notificationTypeFilter) ?>"
    data-notification-scope="<?= e((string) notification_scope_user_id($notificationViewer)) ?>"
>
    <div class="metric-grid">
        <article class="metric-box"><div class="metric-box__icon blue">N</div><strong data-notification-page-total><?= e((string) $notificationTotalCount) ?></strong><span>Total Notifications</span></article>
        <article class="metric-box"><div class="metric-box__icon orange">U</div><strong data-notification-page-unread><?= e((string) $notificationUnreadCount) ?></strong><span>Unread</span></article>
        <article class="metric-box"><div class="metric-box__icon purple">T</div><strong data-notification-page-today><?= e((string) $notificationTodayCount) ?></strong><span>Today</span></article>
        <article class="metric-box"><div class="metric-box__icon green">R</div><strong data-notification-page-read><?= e((string) $notificationReadCount) ?></strong><span>Read</span></article>
    </div>

    <div class="page-panel notification-page__panel">
        <div class="notification-page__toolbar">
            <nav class="notification-filter-tabs" aria-label="Notification type filters">
                <?php foreach ($notificationTypeOptions as $type => $label): ?>
                    <?php $href = $notificationBasePath . ($type !== '' ? '?type=' . rawurlencode($type) : ''); ?>
                    <a class="notification-filter-tabs__item <?= $notificationTypeFilter === $type ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="post" class="inline-form" data-notification-bulk-form data-confirm-message="Mark all visible unread notifications as read?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="type" value="<?= e($notificationTypeFilter) ?>">
                <button class="btn btn-secondary btn-sm" type="submit">Mark All as Read</button>
            </form>
            <form method="post" class="inline-form" data-notification-bulk-form data-confirm-message="Delete all read notifications from your history?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_read">
                <input type="hidden" name="type" value="<?= e($notificationTypeFilter) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Delete Read Notifications</button>
            </form>
        </div>

        <div class="notification-history-list">
            <?php foreach ($notificationList as $notification): ?>
                <?php
                $status = (string) ($notification['status'] ?? 'unread');
                $type = (string) ($notification['type'] ?? 'info');
                $createdLabel = format_display_datetime($notification['created_at'] ?? null, 'No date recorded');
                $sourceLabel = notification_source_label($notification);
                $relatedUrl = $notificationViewer ? notification_absolute_url($notificationViewer, $notification) : '';
                $notificationReference = '';
                if (preg_match('/\bORD-[A-Z0-9-]+\b/i', (string) ($notification['title'] ?? '') . ' ' . (string) ($notification['message'] ?? ''), $referenceMatch)) {
                    $notificationReference = strtoupper((string) $referenceMatch[0]);
                }
                ?>
                <button
                    class="notification-history-item notification-history-item--<?= e($type) ?> <?= $status === 'unread' ? 'is-unread' : 'is-read' ?> <?= priority_item_class($notification) ?>"
                    type="button"
                    <?= priority_data_attr($notification) ?>
                    data-notification-page-item
                    data-notification-id="<?= e((string) $notification['id']) ?>"
                    data-notification-status="<?= e($status) ?>"
                    data-notification-title="<?= e((string) $notification['title']) ?>"
                    data-notification-message="<?= e((string) $notification['message']) ?>"
                    data-notification-type="<?= e(notification_type_label($type)) ?>"
                    data-notification-date="<?= e($createdLabel) ?>"
                    data-notification-source="<?= e($sourceLabel) ?>"
                    data-notification-related-url="<?= e($relatedUrl) ?>"
                >
                    <span class="notification-history-item__icon" aria-hidden="true"><?= e(notification_type_icon($type)) ?></span>
                    <span class="notification-history-item__body">
                        <span class="notification-history-item__title">
                            <strong><?= e((string) $notification['title']) ?></strong>
                            <?php if ($status === 'unread'): ?><em data-notification-unread-indicator>Unread</em><?php endif; ?>
                        </span>
                        <?= priority_badge($notification, 'notification') ?>
                        <?php if ($notificationReference !== ''): ?><span class="notification-history-item__reference">Order <?= e($notificationReference) ?></span><?php endif; ?>
                        <span class="notification-history-item__meta">
                            <?= e(notification_type_label($type)) ?> · <?= e($sourceLabel) ?> · <?= e($createdLabel) ?>
                        </span>
                        <time class="notification-history-item__date" datetime="<?= e((string) ($notification['created_at'] ?? '')) ?>"><?= e($createdLabel) ?></time>
                        <small><?= e((string) $notification['message']) ?></small>
                    </span>
                    <span class="badge <?= $status === 'unread' ? 'badge-warning' : 'badge-success' ?>" data-notification-item-status><?= e(ucwords($status)) ?></span>
                </button>
            <?php endforeach; ?>

            <?php if ($notificationList === []): ?>
                <div class="empty-state notification-history-empty">
                    <h2>No notifications found</h2>
                    <p>Try another category or check back later.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <dialog class="notification-detail-modal" data-notification-page-dialog aria-labelledby="notification-detail-title">
        <div class="notification-detail-modal__card">
            <button class="notification-detail-modal__close" type="button" data-notification-page-close aria-label="Close notification details">&times;</button>
            <span class="eyebrow" data-notification-dialog-type>Notification</span>
            <h2 id="notification-detail-title" data-notification-dialog-title>Select a notification</h2>
            <p data-notification-dialog-message></p>
            <dl class="notification-detail-modal__meta">
                <div><dt>Type</dt><dd data-notification-dialog-type-text>-</dd></div>
                <div><dt>Date and Time</dt><dd data-notification-dialog-date>-</dd></div>
                <div><dt>Source</dt><dd data-notification-dialog-source>-</dd></div>
                <div><dt>Status</dt><dd><span class="badge badge-muted" data-notification-dialog-status>-</span></dd></div>
            </dl>
            <a class="btn btn-secondary" href="#" data-notification-dialog-related hidden>Open Related Page</a>
        </div>
    </dialog>
</div>
