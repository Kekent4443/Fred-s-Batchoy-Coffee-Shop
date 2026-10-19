<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login('administrator');

// Authentication history is append-only in this module. There is no POST
// action, delete endpoint, or retention cleanup for either audit table.
if (is_post()) {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

if (!auth_history_schema_ready()) {
    $pageTitle = 'Authentication Records';
    require_once APP_ROOT . '/app/includes/head.php';
    require_once APP_ROOT . '/app/includes/topbar.php';
    ?>
    <div class="app-shell">
        <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
        <main class="app-main"><section class="section">
            <?php render_page_header('Authentication Records', 'Review authentication activity and security events.', 'records', 'SECURITY AUDIT'); ?>
            <div class="page-panel" role="alert">
                <h2>Database update required</h2>
                <p>Authentication history tables are not installed yet. Import <code>database/migrations/2026_09_28_authentication_activity.sql</code> into this application database using phpMyAdmin, then reload this page.</p>
            </div>
        </section></main>
    </div>
    <?php
    require_once APP_ROOT . '/app/includes/footer.php';
    exit;
}

$filters = auth_history_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$summary = auth_history_summary();
$result = auth_history_page($filters, $page);
$tabs = ['login' => 'Login History', 'logout' => 'Logout History', 'successful' => 'Successful', 'failed' => 'Failed'];
$roles = ['administrator' => 'Administrator', 'manager' => 'Manager', 'staff' => 'Staff', 'rider' => 'Rider', 'customer' => 'Customer'];
$reasons = [
    'unknown_account' => 'Unknown account', 'wrong_password' => 'Wrong password',
    'inactive' => 'Inactive account', 'pending_verification' => 'Pending approval',
    'rejected' => 'Rejected account', 'unsupported_role' => 'Unsupported role',
    'throttled' => 'Rate limited', 'temporarily_locked' => 'Temporarily locked',
];
$queryUrl = static function (array $changes = []) use ($filters): string {
    $query = array_intersect_key($filters, array_flip(['tab', 'role', 'period', 'from', 'to', 'state', 'search']));
    return url('admin/auth-records.php') . '?' . http_build_query(array_merge($query, $changes));
};

$export = (string) ($_GET['export'] ?? '');
if (in_array($export, ['csv', 'pdf'], true)) {
    require_once APP_ROOT . '/app/helpers/auth_history_export.php';
    auth_history_export($filters, $export, $tabs[$filters['tab']]);
}

$pageTitle = 'Authentication Records';
$pageScripts[] = 'js/auth-records.js';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section auth-records">
            <?php render_page_header('Authentication Records', 'Review sign-ins, sign-outs, failed attempts, and current account activity.', 'records', 'SECURITY AUDIT'); ?>

            <div class="metric-grid auth-records__metrics">
                <article class="metric-box"><div class="metric-box__icon blue">L</div><strong><?= e((string) $summary['logins_today']) ?></strong><span>Logins Today</span></article>
                <article class="metric-box"><div class="metric-box__icon green">O</div><strong><?= e((string) $summary['active_now']) ?></strong><span>Active Users Now</span></article>
                <article class="metric-box"><div class="metric-box__icon red">F</div><strong><?= e((string) $summary['failed_today']) ?></strong><span>Failed Attempts Today</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">R</div><strong><?= e(implode(' · ', array_map(static fn (array $r): string => ($roles[$r['role']] ?? ucfirst($r['role'])) . ': ' . $r['n'], $summary['roles'])) ?: '0') ?></strong><span>Logins Per Role Today</span></article>
            </div>

            <div class="page-panel auth-records__panel">
                <nav id="auth-records-tabs" class="auth-records__tabs" aria-label="Authentication record views" data-auth-records-tabs>
                    <?php foreach ($tabs as $key => $label): ?>
                        <a href="<?= e($queryUrl(['tab' => $key, 'state' => '', 'page' => 1])) ?>#auth-records-tabs" class="auth-records__tab<?= $filters['tab'] === $key ? ' is-active' : '' ?>"<?= $filters['tab'] === $key ? ' aria-current="page"' : '' ?> data-auth-records-tab><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <form class="auth-records__filters" method="get" action="<?= e(url('admin/auth-records.php')) ?>">
                    <input type="hidden" name="tab" value="<?= e($filters['tab']) ?>">
                    <label><span>Search name or email</span><input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search name or email"></label>
                    <label><span>Role</span><select name="role"><option value="">All roles</option><?php foreach ($roles as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label><span>Date</span><select name="period"><option value="all"<?= $filters['period'] === 'all' ? ' selected' : '' ?>>All dates</option><option value="today"<?= $filters['period'] === 'today' ? ' selected' : '' ?>>Today</option><option value="week"<?= $filters['period'] === 'week' ? ' selected' : '' ?>>This Week</option><option value="custom"<?= $filters['period'] === 'custom' ? ' selected' : '' ?>>Custom range</option></select></label>
                    <?= date_input_field('from', $filters['from_input'], 'From', $filters['date_errors'], 'auth-records', 'auth-records__date-field') ?>
                    <?= date_input_field('to', $filters['to_input'], 'To', $filters['date_errors'], 'auth-records', 'auth-records__date-field') ?>
                    <label><span><?= $filters['tab'] === 'failed' ? 'Failure reason' : 'Session state' ?></span><select name="state"><option value="">All</option><?php if ($filters['tab'] === 'failed'): ?><?php foreach ($reasons as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['state'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?><?php else: ?><option value="open"<?= $filters['state'] === 'open' ? ' selected' : '' ?>>Open session</option><option value="logged_out"<?= $filters['state'] === 'logged_out' ? ' selected' : '' ?>>Ended session</option><?php endif; ?></select></label>
                    <div class="auth-records__filter-actions"><button type="submit" class="btn btn-primary">Filter Records</button><a class="btn btn-ghost" href="<?= e(url('admin/auth-records.php?tab=' . $filters['tab'])) ?>">Reset</a></div>
                </form>

                <div class="auth-records__toolbar"><p><?= e((string) $result['total']) ?> <?= e(strtolower($tabs[$filters['tab']])) ?> record<?= $result['total'] === 1 ? '' : 's' ?> · 20 per page</p><div><a class="btn btn-ghost" href="<?= e($queryUrl(['export' => 'csv'])) ?>">Export CSV</a> <a class="btn btn-ghost" href="<?= e($queryUrl(['export' => 'pdf'])) ?>" title="PDF includes up to 1,000 matching records; CSV includes all matching records">Export PDF (1,000 max)</a></div></div>

                <div id="auth-records-results" class="table-responsive auth-records__table-wrap" tabindex="0" aria-label="Authentication history records">
                    <table class="data-table auth-records__table auth-records__table--<?= e($filters['tab']) ?>">
                        <?php if ($filters['tab'] === 'failed'): ?>
                            <thead><tr><th>Attempted At</th><th>Account / Email Tried</th><th>Role</th><th>Reason</th><th>IP Address</th><th>Browser / User Agent</th></tr></thead>
                            <tbody>
                                <?php foreach ($result['rows'] as $row): ?>
                                    <tr class="auth-records__failed">
                                        <td data-label="Attempted At"><?= e(format_display_datetime($row['attempted_at'])) ?></td>
                                        <td data-label="Account / Email Tried"><?php if (!empty($row['account_name'])): ?><strong><?= e($row['account_name']) ?></strong><br><?php endif; ?><?= e($row['email_tried']) ?></td>
                                        <td data-label="Role"><?= e($roles[$row['role_tried']] ?? 'Unknown') ?></td>
                                        <td data-label="Reason"><span class="auth-records__badge auth-records__badge--failed"><?= e($reasons[$row['failure_reason']] ?? ucwords(str_replace('_', ' ', $row['failure_reason']))) ?></span><?php if ($row['lock_triggered']): ?> <span class="auth-records__badge auth-records__badge--failed">Lock triggered</span><?php endif; ?></td>
                                        <td data-label="IP Address"><?= auth_history_ip_markup($row['ip_address'] ?? null) ?></td>
                                        <td data-label="Browser / User Agent" class="auth-records__agent" title="<?= e($row['user_agent']) ?>"><?= e($row['user_agent'] ?: 'Not provided') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php else: ?>
                            <thead><tr><th>Login At</th><th>Account</th><th>Role</th><th>Logout At</th><th>Duration</th><th>Last Activity</th><th>IP Address</th><th>Browser / User Agent</th><th>Flags</th></tr></thead>
                            <tbody><?php foreach ($result['rows'] as $row): ?>
                                <?php $duration = $row['logout_at'] ? max(0, strtotime($row['logout_at']) - strtotime($row['login_at'])) : null; ?>
                                <tr>
                                    <td data-label="Login At"><?= e(format_display_datetime($row['login_at'])) ?></td>
                                    <td data-label="Account"><strong><?= e($row['full_name']) ?></strong><br><small><?= e($row['email']) ?></small></td>
                                    <td data-label="Role"><?= e($roles[$row['role']] ?? $row['role']) ?></td>
                                    <td data-label="Logout At"><?= e(format_display_datetime($row['logout_at'], '—')) ?></td>
                                    <td data-label="Duration"><?= $duration === null ? '—' : e(sprintf('%dh %02dm %02ds', intdiv($duration, 3600), intdiv($duration % 3600, 60), $duration % 60)) ?></td>
                                    <td data-label="Last Activity"><?= e(format_display_datetime($row['last_activity_at'])) ?></td>
                                    <td data-label="IP Address"><?= auth_history_ip_markup($row['ip_address'] ?? null) ?></td>
                                    <td data-label="Browser / User Agent" class="auth-records__agent" title="<?= e($row['user_agent']) ?>"><?= e($row['user_agent'] ?: 'Not provided') ?></td>
                                    <td data-label="Flags"><?php if ($row['new_ip']): ?><span class="auth-records__badge">New IP</span><?php endif; ?><?php if ($row['new_browser']): ?><span class="auth-records__badge">New browser signature</span><?php endif; ?><?php if (!$row['new_ip'] && !$row['new_browser']): ?>—<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        <?php endif; ?>
                    </table>
                </div>
                <?php if (!$result['rows']): ?><p class="auth-records__empty"><?= $filters['date_errors'] ? 'Correct the date filters to view authentication records.' : 'No authentication records match these filters.' ?></p><?php endif; ?>
                <nav class="auth-records__pagination" aria-label="Records pagination"><span>Page <?= e((string) $result['page']) ?> of <?= e((string) $result['pages']) ?></span><?php if ($result['page'] > 1): ?><a class="btn btn-ghost" href="<?= e($queryUrl(['page' => $result['page'] - 1])) ?>#auth-records-results">Previous</a><?php endif; ?><?php if ($result['page'] < $result['pages']): ?><a class="btn btn-ghost" href="<?= e($queryUrl(['page' => $result['page'] + 1])) ?>#auth-records-results">Next</a><?php endif; ?></nav>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
