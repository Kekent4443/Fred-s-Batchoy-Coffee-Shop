<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$viewer = current_user();
$scopeUserId = notification_scope_user_id($viewer);
$typeFilter = normalize_notification_type_filter((string) request('type', ''));
$redirectPath = 'admin/notifications.php' . ($typeFilter !== '' ? '?type=' . rawurlencode($typeFilter) : '');

if (is_post()) {
    $action = (string) post_scalar('action', 'send');

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($scopeUserId, (string) post_scalar('type', ''));
        flash('notifications', 'All visible notifications marked as read.', 'success');
        redirect($redirectPath);
    }

    if ($action === 'delete_read') {
        delete_read_notifications($scopeUserId);
        flash('notifications', 'Read notifications deleted.', 'success');
        redirect($redirectPath);
    }

    if ($action === 'send') {
        $audience = (string) post_scalar('audience', '');
        $title = trim((string) post_scalar('title', ''));
        $message = trim((string) post_scalar('message', ''));
        $type = (string) post_scalar('notification_type', '');
        $sendToken = (string) post_scalar('send_token', '');
        $allowedAudiences = ['all', 'customer', 'staff', 'manager'];

        if (!in_array($audience, $allowedAudiences, true)) {
            flash('notifications', 'Please select a valid notification audience.', 'danger');
            redirect($redirectPath);
        }

        if ($title === '' || $message === '' || strlen($title) > 180 || strlen($message) > 2000) {
            flash('notifications', 'Enter a title of up to 180 characters and a message of up to 2,000 characters.', 'danger');
            redirect($redirectPath);
        }

        if (!in_array($type, ['info', 'success', 'warning'], true) || !preg_match('/^[a-f0-9]{32}$/D', $sendToken)) {
            flash('notifications', 'Invalid notification type or request. Reload the page and try again.', 'danger');
            redirect($redirectPath);
        }

        $targets = system_notification_recipient_ids($audience);
        if ($targets === []) {
            flash('notifications', 'No active users match that audience.', 'warning');
            redirect($redirectPath);
        }

        $database = get_db();
        $database->begin_transaction();
        try {
            foreach ($targets as $recipientId) {
                create_notification($recipientId, $title, $message, $type, null, 'system_send:' . $sendToken);
            }
            $database->commit();
        } catch (Throwable $exception) {
            $database->rollback();
            error_log('System notification send failed: ' . $exception->getMessage());
            flash('notifications', 'Unable to send notifications. No partial broadcast was saved.', 'danger');
            redirect($redirectPath);
        }

        flash('notifications', 'System notifications sent to active users.', 'success');
        redirect($redirectPath);
    }
}

$notificationList = fetch_notifications_filtered($scopeUserId, $typeFilter, 100);
$notificationViewer = $viewer;
$notificationBasePath = 'admin/notifications.php';
$notificationTypeFilter = $typeFilter;

$pageTitle = 'Notifications';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Notifications', 'Filter, open, and manage system notifications across all users.', 'bell', 'System Notifications', static function (): void { ?><a class="btn btn-primary" href="#send-system-notifications">Send System Notifications</a><?php }); ?>

            <?php require APP_ROOT . '/app/includes/notification_page_panel.php'; ?>

            <div class="page-panel page-panel--spaced" id="send-system-notifications">
                <h2>Send System Notifications</h2>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="send_token" value="<?= e(bin2hex(random_bytes(16))) ?>">
                    <label>Audience
                        <select name="audience">
                            <option value="all">All Users</option>
                            <option value="customer">Customers</option>
                            <option value="staff">Staff</option>
                            <option value="manager">Managers</option>
                        </select>
                    </label>
                    <label>Notification Type
                        <select name="notification_type">
                            <option value="info">Information</option>
                            <option value="success">Success</option>
                            <option value="warning">Warning</option>
                        </select>
                    </label>
                    <label>Title<input type="text" name="title" maxlength="180" required></label>
                    <label>Message<textarea name="message" rows="4" maxlength="2000" required></textarea></label>
                    <button class="btn btn-primary" type="submit">Send Notification</button>
                </form>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
