<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('staff');

$user = current_user();
$scopeUserId = (int) ($user['id'] ?? 0);
$typeFilter = normalize_notification_type_filter((string) request('type', ''));
$redirectPath = 'staff/notifications.php' . ($typeFilter !== '' ? '?type=' . rawurlencode($typeFilter) : '');

if (is_post() && request('action') === 'mark_all_read') {
    mark_all_notifications_read($scopeUserId, (string) request('type', ''));
    flash('notifications', 'All visible notifications marked as read.', 'success');
    redirect($redirectPath);
}

if (is_post() && request('action') === 'delete_read') {
    delete_read_notifications($scopeUserId);
    flash('notifications', 'Read notifications deleted.', 'success');
    redirect($redirectPath);
}

$notificationList = fetch_notifications_filtered($scopeUserId, $typeFilter, 100);
$notificationViewer = $user;
$notificationBasePath = 'staff/notifications.php';
$notificationTypeFilter = $typeFilter;

$pageTitle = 'Staff Notifications';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Staff Notifications', 'Filter, open, and review assignment, payment, order, delivery, and support updates.', 'bell', 'Notification Management'); ?>

            <?php require APP_ROOT . '/app/includes/notification_page_panel.php'; ?>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
