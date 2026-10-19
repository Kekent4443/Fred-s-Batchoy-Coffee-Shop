<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

$viewer = current_user();
$scopeUserId = notification_scope_user_id($viewer);
$typeFilter = normalize_notification_type_filter((string) request('type', ''));
$redirectPath = 'manager/notifications.php' . ($typeFilter !== '' ? '?type=' . rawurlencode($typeFilter) : '');

if (is_post()) {
    $action = (string) request('action', 'send_notification');

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($scopeUserId, (string) request('type', ''));
        flash('notifications', 'All visible notifications marked as read.', 'success');
        redirect($redirectPath);
    }

    if ($action === 'delete_read') {
        delete_read_notifications($scopeUserId);
        flash('notifications', 'Read notifications deleted.', 'success');
        redirect($redirectPath);
    }

    if ($action !== 'send_notification') {
        redirect($redirectPath);
    }

    $audience = (string) request('audience', 'staff');
    $title = trim((string) request('title'));
    $message = trim((string) request('message'));
    $type = normalize_notification_type_filter((string) request('type', 'info'));
    $type = $type !== '' ? $type : 'info';

    if ($title === '' || $message === '') {
        flash('notifications', 'Please enter a notification title and message.', 'danger');
        redirect($redirectPath);
    }

    $targetRoles = match ($audience) {
        'customers' => ['customer'],
        'both' => ['staff', 'customer'],
        default => ['staff'],
    };

    $targets = [];

    foreach ($targetRoles as $role) {
        foreach (fetch_users($role) as $target) {
            if (($target['status'] ?? '') === 'active') {
                $targets[(int) $target['id']] = $target;
            }
        }
    }

    if ($targets === []) {
        flash('notifications', 'No active recipients were found for the selected audience.', 'warning');
        redirect($redirectPath);
    }

    foreach ($targets as $target) {
        create_notification((int) $target['id'], $title, $message, $type);
    }

    flash('notifications', 'Notification sent to ' . count($targets) . ' recipient(s).', 'success');
    redirect($redirectPath);
}

$notificationList = fetch_notifications_filtered($scopeUserId, $typeFilter, 100);
$notificationViewer = $viewer;
$notificationBasePath = 'manager/notifications.php';
$notificationTypeFilter = $typeFilter;

$pageTitle = 'Manager Notifications';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Notifications', 'Filter, open, and review staff and customer notification activity.', 'bell', 'Notification Management'); ?>

            <?php require APP_ROOT . '/app/includes/notification_page_panel.php'; ?>

            <form method="post" class="form-card page-panel--spaced">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_notification">
                <div class="card-head"><h2>Notify Staff or Customers</h2></div>
                <label>Audience
                    <select name="audience" required>
                        <option value="staff">Staff only</option>
                        <option value="customers">Customers only</option>
                        <option value="both">Staff and customers</option>
                    </select>
                </label>
                <label>Notification Type
                    <select name="type" required>
                        <option value="info">Information</option>
                        <option value="success">Success</option>
                        <option value="warning">Warning</option>
                    </select>
                </label>
                <label>Title
                    <input type="text" name="title" maxlength="180" required>
                </label>
                <label>Message
                    <textarea name="message" rows="4" required></textarea>
                </label>
                <button class="btn btn-primary" type="submit">Send Notification</button>
            </form>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
