<?php
$viewer = current_user();
$categories = contact_categories();
$statuses = contact_status_options();
$filterErrors = date_input_errors($_GET, ['date' => 'date']);
$filters = [
    'category' => array_key_exists((string) request('category', ''), $categories) ? (string) request('category', '') : '',
    'status' => array_key_exists((string) request('status', ''), $statuses) ? (string) request('status', '') : '',
    'date' => parse_display_date(request('date', '')) ?? '',
    'search' => table_search_value(),
];
$selectedId = (int) request('id', 0);
$viewerRole = (string) ($viewer['role'] ?? '');
$canViewContactSettings = in_array($viewerRole, ['administrator', 'manager'], true);

$messages = $filterErrors === [] ? fetch_contact_messages($viewer ?? [], $filters) : [];
$selectedMessage = $selectedId > 0 ? fetch_contact_message_for_viewer($selectedId, $viewer ?? []) : ($messages[0] ?? null);
$contactSettings = contact_business_info();
$canManageContactSettings = $canViewContactSettings && can_manage_contact_settings($viewer ?? []);
?>
<?php require APP_ROOT . '/app/includes/alerts.php'; ?>
<section class="section">
    <?php render_page_header('Contact Messages', 'Review submitted Contact Us concerns, update their status, and record internal notes.', 'inbox', 'Inquiry Management'); ?>

    <?php if ($canViewContactSettings): ?>
        <div class="page-panel page-panel--spaced">
            <div class="card-head">
                <div>
                    <h2>Homepage Contact Us Details</h2>
                    <p>These saved details are displayed automatically on the homepage Contact Us cards.</p>
                </div>
            </div>

            <?php if ($canManageContactSettings): ?>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_contact_settings">
                    <div class="form-grid">
                        <label>Facebook Label
                            <input type="text" name="facebook_label" maxlength="120" required value="<?= e($contactSettings['facebook_label']) ?>">
                        </label>
                        <label>Facebook URL
                            <input type="url" name="facebook_url" maxlength="500" required value="<?= e($contactSettings['facebook_url']) ?>">
                        </label>
                        <label>Gmail / Support Email
                            <input type="email" name="email_address" maxlength="150" required value="<?= e($contactSettings['email_address']) ?>">
                        </label>
                        <label>Phone Number
                            <input type="text" name="contact_number" maxlength="40" required value="<?= e($contactSettings['contact_number']) ?>">
                        </label>
                        <label>Support Heading
                            <input type="text" name="support_heading" maxlength="180" required value="<?= e($contactSettings['support_heading']) ?>">
                        </label>
                        <label>Support Description
                            <input type="text" name="support_description" maxlength="500" required value="<?= e($contactSettings['support_description']) ?>">
                        </label>
                    </div>
                    <button class="btn btn-primary" type="submit">Save Contact Details</button>
                </form>
            <?php else: ?>
                <dl class="contact-info-list">
                    <div><dt>Facebook</dt><dd><?= e($contactSettings['facebook_label']) ?> &middot; <?= e($contactSettings['facebook_url']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($contactSettings['email_address']) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= e($contactSettings['contact_number']) ?></dd></div>
                    <div><dt>Heading</dt><dd><?= e($contactSettings['support_heading']) ?></dd></div>
                    <div><dt>Description</dt><dd><?= e($contactSettings['support_description']) ?></dd></div>
                </dl>
                <p class="muted">You have read-only access to these Contact Us details.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="page-panel contact-messages-filter-panel">
        <form method="get" class="toolbar date-filter-toolbar contact-messages-filter" novalidate>
            <label class="filter-control-field">Search<input type="search" name="search" placeholder="Search sender, subject, or message..." value="<?= e($filters['search']) ?>"></label>
            <label class="filter-control-field">Category<span class="contact-messages-filter__select"><select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select><?= sidebar_icon_svg('chevron-down') ?></span></label>
            <label class="filter-control-field">Status<span class="contact-messages-filter__select"><select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select><?= sidebar_icon_svg('chevron-down') ?></span></label>
            <?= date_input_field('date', request('date', ''), 'Date', $filterErrors, 'contact') ?>
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="<?= e(url(role_folder((string) $viewer['role']) . '/contact-messages.php')) ?>">Reset</a>
        </form>
    </div>

    <div class="contact-management-layout page-panel--spaced">
        <div class="page-panel contact-message-list">
            <div class="card-head"><h2>Submitted Messages</h2></div>
            <div class="activity-list">
                <?php foreach ($messages as $message): ?>
                    <a class="contact-message-row <?= $selectedMessage && (int) $selectedMessage['id'] === (int) $message['id'] ? 'is-active' : '' ?> <?= (int) $message['is_read'] === 0 ? 'is-unread' : '' ?>" href="<?= e(url(role_folder((string) $viewer['role']) . '/contact-messages.php?id=' . $message['id'])) ?>">
                        <strong><?= e((string) $message['subject']) ?></strong>
                        <span><?= e((string) $message['full_name']) ?> · <?= e($categories[$message['category']] ?? 'Other') ?> · <?= e(format_display_datetime($message['created_at'] ?? null)) ?></span>
                        <small><?= e((string) $message['message']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if ($messages === []): ?>
                    <div class="empty-state"><h2>No contact messages found</h2><p>Try another filter or wait for new submissions.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="page-panel contact-message-detail">
            <?php if ($selectedMessage): ?>
                <div class="card-head">
                    <h2><?= e((string) $selectedMessage['subject']) ?></h2>
                    <?php if ((int) ($selectedMessage['is_read'] ?? 0) === 0): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="message_id" value="<?= e((string) $selectedMessage['id']) ?>">
                            <button class="btn btn-secondary btn-sm" type="submit">Mark as Read</button>
                        </form>
                    <?php endif; ?>
                </div>
                <dl class="contact-info-list">
                    <div><dt>Sender</dt><dd><?= e((string) $selectedMessage['full_name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e((string) $selectedMessage['email']) ?></dd></div>
                    <div><dt>Contact</dt><dd><?= e((string) $selectedMessage['contact_number']) ?></dd></div>
                    <div><dt>Category</dt><dd><?= e($categories[$selectedMessage['category']] ?? 'Other') ?></dd></div>
                    <div><dt>Status</dt><dd><?= status_badge((string) $selectedMessage['status']) ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= e(format_display_datetime($selectedMessage['created_at'] ?? null)) ?></dd></div>
                    <?php if (!empty($selectedMessage['reference_no'])): ?>
                        <div><dt>Related Order</dt><dd><?= e((string) $selectedMessage['reference_no']) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <div class="contact-message-body">
                    <h3>Message</h3>
                    <p><?= nl2br(e((string) $selectedMessage['message'])) ?></p>
                </div>
                <form method="post" class="stack-form contact-management-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_message">
                    <input type="hidden" name="message_id" value="<?= e((string) $selectedMessage['id']) ?>">
                    <label>Status
                        <select name="status">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedMessage['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Internal Note
                        <textarea name="internal_note" rows="3"><?= e((string) ($selectedMessage['internal_note'] ?? '')) ?></textarea>
                    </label>
                    <label>Email Reply / Response
                        <textarea name="response_message" rows="4"><?= e((string) ($selectedMessage['response_message'] ?? '')) ?></textarea>
                    </label>
                    <label class="toggle-row">
                        <input type="checkbox" name="send_reply" value="1">
                        <span>Send response by email</span>
                    </label>
                    <button class="btn btn-primary" type="submit">Update Message</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><h2>Select a message</h2><p>The complete message and management tools will appear here.</p></div>
            <?php endif; ?>
        </div>
    </div>
</section>
