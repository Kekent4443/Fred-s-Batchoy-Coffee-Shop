<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('rider');
$user = current_user();
$scopeUserId = (int) $user['id'];
$typeFilter = normalize_notification_type_filter((string) request('type', ''));
$redirectPath = 'rider/notifications.php' . ($typeFilter !== '' ? '?type=' . rawurlencode($typeFilter) : '');
if (is_post() && request('action') === 'mark_all_read') {
    mark_all_notifications_read($scopeUserId, (string) request('type', ''));
    redirect($redirectPath);
}

if (is_post() && request('action') === 'delete_read') {
    delete_read_notifications($scopeUserId);
    flash('notifications', 'Read notifications deleted.', 'success');
    redirect($redirectPath);
}
$notificationList = fetch_notifications_filtered($scopeUserId, $typeFilter, 100);
$notificationViewer = $user;
$notificationBasePath = 'rider/notifications.php';
$notificationTypeFilter = $typeFilter;
$pageTitle = 'Rider Notifications';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell"><?php require APP_ROOT . '/app/includes/sidebar.php'; ?><main class="app-main"><section class="section"><?php render_page_header('Notifications', 'Review delivery assignments and status updates.', 'bell', 'Rider Portal'); ?><?php require APP_ROOT . '/app/includes/notification_page_panel.php'; ?></section></main></div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
