<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function create_status_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function create_status_test_rejects(callable $operation, string $expectedMessage): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        create_status_test_assert($exception->getMessage() === $expectedMessage, 'Unexpected range validation message.');
        return;
    }
    throw new RuntimeException('Invalid discount range was accepted.');
}

function create_status_test_schema(mysqli $database, string $sql): void
{
    if (!$database->multi_query($sql)) {
        throw new RuntimeException('Disposable test schema import failed.');
    }
    do {
        $result = $database->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($database->more_results() && $database->next_result());
    if ($database->errno !== 0) {
        throw new RuntimeException('Disposable test schema import failed.');
    }
}

function create_status_test_form_has_status(string $page, array $query, string $formXPath): bool
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $query;
    $_POST = [];
    clear_current_user_cache();
    ob_start();
    try {
        include APP_ROOT . '/public/' . $page;
        $html = (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $forms = $xpath->query($formXPath);
    $multipleDeleteForms = str_contains($formXPath, 'delete_promo');
    create_status_test_assert(
        $forms !== false && ($multipleDeleteForms ? $forms->length > 0 : $forms->length === 1),
        'Expected ' . ($multipleDeleteForms ? 'at least one' : 'one') . ' create/edit form on ' . $page . '.'
    );

    foreach ($forms as $form) {
        if ($xpath->query('.//*[@name="status"]', $form)->length > 0) {
            return true;
        }
    }
    return false;
}

function create_status_test_render_role_page(string $page, int $userId, array $query = []): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $query;
    $_POST = [];
    $_SESSION['user_id'] = $userId;
    clear_current_user_cache();
    ob_start();
    try {
        include APP_ROOT . '/public/' . $page;
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

function create_status_test_discount_sidebar(string $page, int $userId): void
{
    $previousPhpSelf = $_SERVER['PHP_SELF'] ?? null;
    $_SERVER['PHP_SELF'] = '/public/' . $page;
    try {
        $html = create_status_test_render_role_page($page, $userId);
    } finally {
        if ($previousPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $previousPhpSelf;
        }
    }

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $discountLinks = $xpath->query('//aside[@id="app-sidebar"]//a[.//span[@class="sidebar__link-label" and normalize-space(.)="Discounts & Promotions"]]');
    create_status_test_assert($discountLinks !== false && $discountLinks->length === 1
        && $discountLinks->item(0)?->getAttribute('aria-current') === 'page',
        'The unified discount sidebar link is missing or inactive on ' . $page . '.');
    create_status_test_assert($xpath->query('//aside[@id="app-sidebar"]//span[@class="sidebar__link-label" and (normalize-space(.)="Discount Rules" or normalize-space(.)="Promo Codes")]')->length === 0,
        'Redundant discount sidebar entries remain on ' . $page . '.');
}

$host = database_setting('DB_HOST', 'host', '127.0.0.1');
create_status_test_assert(in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true), 'Tests require a local database host.');
$databaseName = 'freds_status_test_' . bin2hex(random_bytes(5));
create_status_test_assert(preg_match('/^freds_status_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe test database name.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(
    $host,
    database_setting('DB_USER', 'user', 'root'),
    database_setting('DB_PASS', 'password'),
    '',
    (int) database_setting('DB_PORT', 'port', '3306')
);
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('TESTING_MODE=1');
    putenv('APP_AUTO_MIGRATE=0');
    require_once dirname(__DIR__) . '/app/bootstrap.php';

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $schema = explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    create_status_test_schema(get_db(), $schema);

    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, "administrator", "active", NOW(), NOW())',
        'sssss',
        ['Status Test Admin', 'status-admin@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '09170000001', 'Test address']
    );
    $administratorId = db_last_id();
    $_SESSION['user_id'] = $administratorId;
    $viewer = ['id' => $administratorId, 'role' => 'administrator', 'full_name' => 'Status Test Admin'];

    $staffId = create_managed_user([
        'full_name' => 'New Staff',
        'email' => 'status-staff@example.test',
        'password' => 'TestPassword123',
        'phone' => '09170000002',
        'address' => 'Test address',
        'role' => 'staff',
        'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM users WHERE id = ?', 'i', [$staffId])['status'] ?? '') === 'active', 'Manipulated managed-user status was not forced active.');
    $managedPayload = [
        'full_name' => 'Security Test User', 'email' => 'managed-security@example.test',
        'password' => 'StrongPassword123', 'phone' => '09170000008',
        'address' => 'Test address', 'role' => 'staff',
    ];
    $userCountBeforeInvalidCreates = (int) db_find('SELECT COUNT(*) AS total FROM users')['total'];
    foreach ([
        [['password' => 'allletters'], 'Password must be 8 to 72 characters and include at least one letter and one number.'],
        [['password' => '12345678'], 'Password must be 8 to 72 characters and include at least one letter and one number.'],
        [['full_name' => ''], 'Full name is required.'],
        [['full_name' => str_repeat('N', 151)], 'Full name must be 150 characters or fewer.'],
        [['email' => 'not-an-email'], 'Please enter a valid email address.'],
        [['email' => str_repeat('e', 145) . '@x.test'], 'Email address must be 150 characters or fewer.'],
        [['address' => str_repeat('A', 1001)], 'Address must be 1000 characters or fewer.'],
        [['email' => 'status-staff@example.test'], 'Email address is already registered.'],
        [['phone' => '09170000002'], 'This phone number is already registered. Please use a different number'],
        [['phone' => '09123456789'], 'Please enter a valid personal phone number.'],
        [['phone' => '123'], 'Please enter a valid phone number in 09XXXXXXXXX or +639XXXXXXXXX format.'],
        [['role' => 'administrator'], 'Administrator accounts can only be configured through the protected deployment setup.'],
    ] as [$changes, $message]) {
        create_status_test_rejects(static fn (): int => create_managed_user(array_replace($managedPayload, $changes)), $message);
    }
    create_status_test_assert(
        (int) db_find('SELECT COUNT(*) AS total FROM users')['total'] === $userCountBeforeInvalidCreates,
        'An invalid managed-user request created an account.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_replace($managedPayload, [
        'action' => 'add_user', 'email' => 'mismatched-password@example.test',
        'phone' => '09170000011', 'confirm_password' => 'DifferentPassword123',
    ]);
    ob_start();
    include APP_ROOT . '/public/admin/users.php';
    $mismatchHtml = (string) ob_get_clean();
    create_status_test_assert(str_contains($mismatchHtml, 'Passwords do not match.'), 'Add User did not display the password confirmation error.');
    create_status_test_assert(
        db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['mismatched-password@example.test']) === null,
        'Mismatched passwords created an account.'
    );
    $_POST = array_replace($managedPayload, [
        'action' => 'add_user', 'role' => 'administrator', 'status' => 'inactive',
        'email' => 'forged-administrator@example.test', 'phone' => '09170000012',
        'confirm_password' => 'StrongPassword123',
    ]);
    ob_start();
    include APP_ROOT . '/public/admin/users.php';
    $forgedRoleHtml = (string) ob_get_clean();
    create_status_test_assert(!str_contains($forgedRoleHtml, 'name="role" value="administrator"'), 'A forged role changed the Add User form into an Administrator editor.');
    create_status_test_assert(
        db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['forged-administrator@example.test']) === null,
        'A forged Administrator role created an account.'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $managedCustomerId = create_managed_user(array_replace($managedPayload, [
        'full_name' => 'Security Test Customer', 'email' => 'managed-customer@example.test',
        'phone' => '09170000009', 'address' => 'A complete customer delivery address',
        'role' => 'customer', 'status' => 'rejected',
    ]));
    $managedCustomer = db_find('SELECT status, email_verified_at, password FROM users WHERE id = ?', 'i', [$managedCustomerId]);
    create_status_test_assert(($managedCustomer['status'] ?? '') === 'active', 'A forged customer status was accepted.');
    create_status_test_assert($managedCustomer['email_verified_at'] === null, 'Managed creation incorrectly verified an email.');
    create_status_test_assert(password_verify('StrongPassword123', (string) $managedCustomer['password']), 'Managed-user password was not hashed correctly.');
    update_user_account($staffId, [
        'full_name' => 'New Staff', 'email' => 'status-staff@example.test', 'phone' => '09170000002',
        'address' => 'Test address', 'role' => 'staff', 'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM users WHERE id = ?', 'i', [$staffId])['status'] ?? '') === 'inactive', 'Staff edit did not preserve inactive status.');
    update_user_account($staffId, [
        'full_name' => 'Edited Staff', 'email' => 'status-staff@example.test', 'phone' => '09170000002',
        'address' => 'Test address', 'role' => 'staff', 'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM users WHERE id = ?', 'i', [$staffId])['status'] ?? '') === 'inactive', 'Editing inactive staff reactivated the account.');

    $staffBeforeRejectedEdits = db_find('SELECT full_name, email, phone, address, role, status FROM users WHERE id = ?', 'i', [$staffId]);
    $staffEdit = [
        'full_name' => 'Edited Staff', 'email' => 'status-staff@example.test', 'phone' => '09170000002',
        'address' => 'Test address', 'role' => 'staff', 'status' => 'inactive',
    ];
    foreach ([
        [['full_name' => ''], 'Full name is required.'],
        [['full_name' => str_repeat('N', 151)], 'Full name must be 150 characters or fewer.'],
        [['email' => 'not-an-email'], 'Please enter a valid email address.'],
        [['email' => str_repeat('e', 145) . '@x.test'], 'Email address must be 150 characters or fewer.'],
        [['address' => str_repeat('A', 1001)], 'Address must be 1000 characters or fewer.'],
        [['email' => 'managed-customer@example.test'], 'Email address is already registered.'],
        [['phone' => '09170000009'], 'This phone number is already registered. Please use a different number'],
        [['phone' => '123'], 'Please enter a valid phone number in 09XXXXXXXXX or +639XXXXXXXXX format.'],
        [['role' => 'unknown'], 'Please select a valid account role.'],
        [['role' => 'administrator'], 'Existing accounts cannot be promoted to Administrator.'],
        [['status' => 'pending_verification'], 'Please select a valid account status.'],
        [['password' => 'forged-password'], 'Use the Reset Password action so the account owner can choose a new password securely.'],
    ] as [$changes, $message]) {
        create_status_test_rejects(static fn () => update_user_account($staffId, array_replace($staffEdit, $changes)), $message);
    }
    create_status_test_assert(
        db_find('SELECT full_name, email, phone, address, role, status FROM users WHERE id = ?', 'i', [$staffId]) === $staffBeforeRejectedEdits,
        'A rejected edit changed the existing user.'
    );

    $managerId = create_managed_user(array_replace($managedPayload, [
        'full_name' => 'Edit Test Manager', 'email' => 'edit-manager@example.test',
        'phone' => '09170000013', 'role' => 'manager',
    ]));
    $_SESSION['user_id'] = $managerId;
    clear_current_user_cache();
    create_status_test_rejects(
        static fn () => update_user_account($staffId, $staffEdit),
        'You do not have permission to update user accounts.'
    );
    $_SESSION['user_id'] = $administratorId;
    clear_current_user_cache();

    db_execute(
        'UPDATE users SET email_verified_at = NOW(), delivery_street = "Old Street", delivery_city = "Old City" WHERE id = ?',
        'i',
        [$managedCustomerId]
    );
    update_user_account($managedCustomerId, [
        'full_name' => 'Security Test Customer', 'email' => 'new-managed-customer@example.test',
        'phone' => '09170000009', 'address' => 'A different complete customer delivery address',
        'role' => 'customer', 'status' => 'active',
    ]);
    $editedCustomer = db_find('SELECT email_verified_at, delivery_street, delivery_city FROM users WHERE id = ?', 'i', [$managedCustomerId]);
    create_status_test_assert($editedCustomer['email_verified_at'] === null, 'Changing email did not revoke verification.');
    create_status_test_assert($editedCustomer['delivery_street'] === null && $editedCustomer['delivery_city'] === null, 'Changing a customer address retained stale checkout address fields.');

    db_execute(
        'INSERT INTO support_conversations (customer_id, category, subject, status) VALUES (?, "other", "Handoff test", "pending_staff")',
        'i',
        [$managedCustomerId]
    );
    $supportConversationId = db_last_id();
    db_execute(
        'INSERT INTO support_messages (conversation_id, sender_id, sender_type, message) VALUES (?, ?, "customer", "Please help")',
        'ii',
        [$supportConversationId, $managedCustomerId]
    );
    $supportTriggerId = db_last_id();
    $handoffAuditBefore = (int) db_find('SELECT COUNT(*) AS total FROM audit_logs WHERE action = "support_handoff_required"')['total'];
    $handoffNotificationBefore = (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE title = "Support Handoff Required"')['total'];
    $supportConversation = ['id' => $supportConversationId, 'assigned_user_id' => null];
    $supportTrigger = ['id' => $supportTriggerId];
    support_create_handoff($supportConversation, $supportTrigger, 'Disposable test concern.');
    support_create_handoff($supportConversation, $supportTrigger, 'Disposable test concern.');
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM support_handoffs WHERE trigger_message_id = ?', 'i', [$supportTriggerId])['total'] === 1, 'Repeated support checks created duplicate handoffs.');
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM audit_logs WHERE action = "support_handoff_required"')['total'] === $handoffAuditBefore + 1, 'Repeated support checks created duplicate audit events.');
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE title = "Support Handoff Required"')['total'] > $handoffNotificationBefore, 'First support handoff did not alert staff.');
    create_status_test_assert(support_send_message($supportConversationId, [
        'id' => $managedCustomerId, 'role' => 'customer', 'full_name' => 'Test Customer',
    ], 'Please help') === $supportTriggerId, 'Repeated support message created another row.');
    create_status_test_rejects(static fn () => support_require_conversation_access($supportConversationId, [
        'id' => $managerId, 'role' => 'customer',
    ]), 'Support conversation was not found or you do not have access.');
    $resolvedNoticesBefore = (int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = "Support Conversation Resolved"', 'i', [$managedCustomerId])['total'];
    support_update_conversation($supportConversationId, $viewer, ['status' => 'resolved', 'assigned_user_id' => 0]);
    support_update_conversation($supportConversationId, $viewer, ['status' => 'resolved', 'assigned_user_id' => 0]);
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = "Support Conversation Resolved"', 'i', [$managedCustomerId])['total'] === $resolvedNoticesBefore + 1, 'Repeating a resolved update sent another customer notice.');
    create_status_test_rejects(static fn () => support_update_conversation($supportConversationId, $viewer, [
        'status' => 'invalid', 'assigned_user_id' => 0,
    ]), 'Please select a valid conversation status.');
    create_status_test_assert((string) db_find('SELECT status FROM support_conversations WHERE id = ?', 'i', [$supportConversationId])['status'] === 'resolved', 'Invalid support update changed conversation status.');
    support_add_internal_note($supportConversationId, $viewer, 'Disposable internal note');
    support_add_internal_note($supportConversationId, $viewer, 'Disposable internal note');
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM support_internal_notes WHERE conversation_id = ? AND note = ?', 'is', [$supportConversationId, 'Disposable internal note'])['total'] === 1, 'Repeated internal note submitted duplicate rows.');

    $faqInput = [
        'category' => 'general_inquiry', 'question' => 'Disposable FAQ question',
        'keywords' => 'batchoy, menu', 'answer' => 'Disposable FAQ answer',
        'priority' => '10', 'status' => 'inactive',
    ];
    create_status_test_assert(support_faq_payload($faqInput, false)['status'] === 'active', 'New FAQ did not default to Active.');
    create_status_test_assert(support_faq_payload($faqInput, true)['status'] === 'inactive', 'FAQ edit did not retain the selected status.');
    create_status_test_rejects(static fn () => support_faq_payload(array_replace($faqInput, ['question' => '']), false), 'FAQ question is required and must be 255 characters or fewer.');
    create_status_test_rejects(static fn () => support_faq_payload(array_replace($faqInput, ['keywords' => ', ,']), false), 'Enter at least one FAQ keyword.');
    create_status_test_rejects(static fn () => support_faq_payload(array_replace($faqInput, ['priority' => 'high']), false), 'FAQ priority must be a whole number.');
    create_status_test_rejects(static fn () => support_faq_payload(array_replace($faqInput, ['status' => 'enabled']), true), 'Please select a valid FAQ status.');

    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, deleted_at) VALUES (?, ?, ?, ?, ?, "staff", "inactive", NULL)',
        'sssss', ['Inactive Notification Test', 'inactive-notify@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '09170000021', 'Test address']
    );
    $inactiveRecipientId = db_last_id();
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, deleted_at) VALUES (?, ?, ?, ?, ?, "customer", "active", NOW())',
        'sssss', ['Deleted Notification Test', 'deleted-notify@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '09170000022', 'Test address']
    );
    $deletedRecipientId = db_last_id();
    create_status_test_rejects(static fn () => support_update_conversation($supportConversationId, $viewer, [
        'status' => 'pending_staff', 'assigned_user_id' => $inactiveRecipientId,
    ]), 'Please select an active staff assignee.');
    $recipients = system_notification_recipient_ids('all');
    create_status_test_assert(in_array($managedCustomerId, $recipients, true), 'Active customer was omitted from system notification audience.');
    create_status_test_assert(!in_array($inactiveRecipientId, $recipients, true) && !in_array($deletedRecipientId, $recipients, true), 'Inactive or deleted user was included in a system notification audience.');
    create_status_test_rejects(static fn () => system_notification_recipient_ids('rider'), 'Please select a valid notification audience.');
    $eventKey = 'system_send:' . bin2hex(random_bytes(16));
    $unreadBefore = unread_notification_count($managedCustomerId);
    create_notification($managedCustomerId, 'Disposable notification', 'A test update', 'info', null, $eventKey);
    create_notification($managedCustomerId, 'Disposable notification', 'A test update', 'info', null, $eventKey);
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND event_key = ?', 'is', [$managedCustomerId, $eventKey])['total'] === 1, 'Repeated notification send was not deduplicated.');
    create_status_test_assert(unread_notification_count($managedCustomerId) === $unreadBefore + 1, 'One event did not add exactly one unread notification.');
    $notificationId = (int) db_find('SELECT id FROM notifications WHERE user_id = ? AND event_key = ?', 'is', [$managedCustomerId, $eventKey])['id'];
    mark_notification_read($notificationId, $managerId);
    create_status_test_assert((string) db_find('SELECT status FROM notifications WHERE id = ?', 'i', [$notificationId])['status'] === 'unread', 'Another user marked a notification as read.');
    mark_notification_read($notificationId, $managedCustomerId);
    create_status_test_assert((string) db_find('SELECT status FROM notifications WHERE id = ?', 'i', [$notificationId])['status'] === 'read', 'Recipient could not mark a notification as read.');
    create_status_test_assert(unread_notification_count($managedCustomerId) === $unreadBefore, 'Reading one notification did not decrease the unread count by one.');
    mark_all_notifications_read($managedCustomerId);
    create_status_test_assert(notification_badge_state(['id' => $managedCustomerId, 'role' => 'customer'], false, true)['unread_count'] === 0, 'Zero unread notifications left a red badge count.');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['user_id' => (string) $staffId];
    $_POST = array_replace($staffEdit, ['action' => 'update_user', 'full_name' => 'Forged URL Edit']);
    ob_start();
    include APP_ROOT . '/public/admin/users.php';
    $missingIdHtml = (string) ob_get_clean();
    create_status_test_assert(str_contains($missingIdHtml, 'User account does not exist.'), 'Missing POST user ID was not rejected.');
    create_status_test_assert((db_find('SELECT full_name FROM users WHERE id = ?', 'i', [$staffId])['full_name'] ?? '') === 'Edited Staff', 'URL query parameters supplied a missing edit target.');
    $staffPasswordBefore = (string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$staffId])['password'];
    $_GET = [];
    $_POST = array_replace($staffEdit, [
        'action' => 'update_user', 'user_id' => (string) $staffId,
        'password' => 'forged-password',
    ]);
    ob_start();
    include APP_ROOT . '/public/admin/users.php';
    $forgedPasswordHtml = (string) ob_get_clean();
    create_status_test_assert(str_contains($forgedPasswordHtml, 'Use the Reset Password action'), 'Forged edit password was not rejected.');
    create_status_test_assert((string) db_find('SELECT password FROM users WHERE id = ?', 'i', [$staffId])['password'] === $staffPasswordBefore, 'Forged edit password changed the account.');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];

    create_category('Status Test Category', 'For disposable tests');
    $category = db_find('SELECT id, status FROM categories WHERE name = ? LIMIT 1', 's', ['Status Test Category']);
    $categoryId = (int) ($category['id'] ?? 0);
    create_status_test_assert($categoryId > 0 && ($category['status'] ?? '') === 'active', 'New category was not active.');

    save_product([
        'category_id' => $categoryId, 'name' => 'Status Test Product', 'description' => '',
        'price' => 60, 'is_available' => 1, 'status' => 'inactive', 'stock_quantity' => 20,
        'image_url' => 'uploads/products/status-test.png',
    ]);
    $product = db_find('SELECT id, status FROM products WHERE name = ? LIMIT 1', 's', ['Status Test Product']);
    $productId = (int) ($product['id'] ?? 0);
    create_status_test_assert($productId > 0 && ($product['status'] ?? '') === 'active', 'Manipulated new-product status was not forced active.');
    save_product([
        'category_id' => $categoryId, 'name' => 'Status Test Product', 'description' => '',
        'price' => 60, 'is_available' => 1, 'status' => 'inactive', 'stock_quantity' => 20,
        'image_url' => 'uploads/products/status-test.png',
    ], $productId);
    create_status_test_assert((db_find('SELECT status FROM products WHERE id = ?', 'i', [$productId])['status'] ?? '') === 'inactive', 'Product edit did not preserve inactive status.');
    save_product([
        'category_id' => $categoryId, 'name' => 'Status Test Product', 'description' => 'Edited while inactive',
        'price' => 60, 'is_available' => 1, 'status' => 'inactive', 'stock_quantity' => 20,
        'image_url' => 'uploads/products/status-test.png',
    ], $productId);
    create_status_test_assert((db_find('SELECT status FROM products WHERE id = ?', 'i', [$productId])['status'] ?? '') === 'inactive', 'Editing inactive product reactivated it.');

    update_category($categoryId, 'Status Test Category', 'Edited without reactivation', 'inactive');
    create_status_test_assert((db_find('SELECT status FROM categories WHERE id = ?', 'i', [$categoryId])['status'] ?? '') === 'inactive', 'Category edit did not preserve inactive status.');
    update_category($categoryId, 'Status Test Category', 'Edited again while inactive', 'inactive');
    create_status_test_assert((db_find('SELECT status FROM categories WHERE id = ?', 'i', [$categoryId])['status'] ?? '') === 'inactive', 'Editing inactive category reactivated it.');

    save_bulk_discount_rule($viewer, 0, [
        'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 9,
        'discount_rate' => 10, 'status' => 'inactive',
    ]);
    $rule = db_find('SELECT id, status FROM bulk_discount_rules WHERE label = ? LIMIT 1', 's', ['Status Test Rule']);
    create_status_test_assert(($rule['status'] ?? '') === 'active', 'Manipulated new-discount-rule status was not forced active.');
    save_bulk_discount_rule($viewer, (int) $rule['id'], [
        'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 9,
        'discount_rate' => 10, 'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $rule['id']])['status'] ?? '') === 'inactive', 'Discount rule edit did not preserve inactive status.');
    save_bulk_discount_rule($viewer, (int) $rule['id'], [
        'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 9,
        'discount_rate' => 10, 'status' => 'active',
    ]);
    create_status_test_assert((db_find('SELECT status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $rule['id']])['status'] ?? '') === 'active', 'Inactive bulk rule could not be reactivated.');
    save_bulk_discount_rule($viewer, (int) $rule['id'], [
        'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 9,
        'discount_rate' => 10, 'status' => 'inactive',
    ]);

    $gcashId = save_gcash_account($viewer, [
        'account_name' => 'Status Test GCash', 'account_number' => '09170000003',
        'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM gcash_payment_accounts WHERE id = ?', 'i', [$gcashId])['status'] ?? '') === 'active', 'Manipulated new-GCash-account status was not forced active.');
    save_gcash_account($viewer, [
        'id' => $gcashId, 'account_name' => 'Status Test GCash', 'account_number' => '09170000003',
        'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM gcash_payment_accounts WHERE id = ?', 'i', [$gcashId])['status'] ?? '') === 'inactive', 'GCash account edit did not preserve inactive status.');
    create_status_test_rejects(static fn () => save_gcash_account($viewer, [
        'id' => $gcashId, 'account_name' => 'Status Test GCash', 'account_number' => '09170000003', 'status' => 'enabled',
    ]), 'Please select a valid GCash account status.');
    create_status_test_assert((db_find('SELECT status FROM gcash_payment_accounts WHERE id = ?', 'i', [$gcashId])['status'] ?? '') === 'inactive', 'Invalid GCash update changed the account.');
    create_status_test_rejects(static fn () => delete_gcash_account($viewer, $gcashId), 'The last GCash account cannot be deleted. Deactivate it instead.');
    try {
        save_gcash_account(['id' => $managerId, 'role' => 'manager'], [
            'id' => $gcashId, 'account_name' => 'Forged GCash', 'account_number' => '09170000003', 'status' => 'active',
        ]);
        throw new RuntimeException('Manager changed GCash payment settings.');
    } catch (RuntimeException $exception) {
        create_status_test_assert($exception->getMessage() === 'Only the Administrator can update GCash payment settings.', 'Unexpected GCash role rejection.');
    }
    $temporaryGcashId = save_gcash_account($viewer, [
        'account_name' => 'Temporary GCash', 'account_number' => '09170000023',
    ]);
    save_gcash_account($viewer, [
        'id' => $gcashId, 'account_name' => 'Status Test GCash', 'account_number' => '09170000003', 'status' => 'active',
    ]);
    delete_gcash_account($viewer, $temporaryGcashId);
    create_status_test_assert(fetch_gcash_account($temporaryGcashId) === null && fetch_gcash_account($gcashId) !== null, 'Deleting an extra GCash account affected the remaining account.');

    $locationId = add_shop_location($viewer, [
        'shop_name' => 'Status Test Shop', 'complete_address' => 'Test address',
        'google_maps_url' => 'https://www.google.com/maps',
        'google_maps_embed_url' => 'https://www.google.com/maps?q=test&output=embed',
        'latitude' => 9.0, 'longitude' => 123.0, 'contact_number' => '09170000004',
        'landmark' => '', 'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM shop_locations WHERE id = ?', 'i', [$locationId])['status'] ?? '') === 'active', 'Manipulated new-shop-location status was not forced active.');
    save_shop_location($locationId, $viewer, [
        'shop_name' => 'Status Test Shop', 'complete_address' => 'Test address',
        'google_maps_url' => 'https://www.google.com/maps',
        'google_maps_embed_url' => 'https://www.google.com/maps?q=test&output=embed',
        'latitude' => 9.0, 'longitude' => 123.0, 'contact_number' => '09170000004',
        'landmark' => '', 'status' => 'inactive',
    ]);
    create_status_test_assert((db_find('SELECT status FROM shop_locations WHERE id = ?', 'i', [$locationId])['status'] ?? '') === 'inactive', 'Shop-location edit did not preserve inactive status.');
    db_execute('UPDATE users SET can_edit_shop_location = 1, assigned_shop_id = ? WHERE id = ?', 'ii', [$locationId, $managerId]);
    $locationManager = ['id' => $managerId, 'role' => 'manager', 'full_name' => 'Test Manager'];
    save_shop_location($locationId, $locationManager, [
        'shop_name' => 'Manager Edited Shop', 'complete_address' => 'Test address',
        'google_maps_url' => 'https://www.google.com/maps',
        'google_maps_embed_url' => 'https://www.google.com/maps?q=test&output=embed',
        'latitude' => 9.0, 'longitude' => 123.0, 'contact_number' => '09170000004',
        'landmark' => '', 'status' => 'active',
    ]);
    create_status_test_assert((db_find('SELECT status FROM shop_locations WHERE id = ?', 'i', [$locationId])['status'] ?? '') === 'inactive', 'Manager forged a shop status change.');

    $promoAdmin = $viewer + ['status' => 'active'];
    $promoPayload = [
        'code' => 'STATUS_TEST_PROMO', 'description' => 'Disposable promo test',
        'discount_type' => 'percent', 'discount_value' => 10, 'minimum_subtotal' => 0,
        'maximum_discount' => '', 'usage_limit' => '', 'starts_at' => '', 'ends_at' => '',
        'status' => 'inactive',
    ];
    $promoId = save_promo_code($promoAdmin, 0, $promoPayload);
    create_status_test_assert((db_find('SELECT status FROM promo_codes WHERE id = ?', 'i', [$promoId])['status'] ?? '') === 'active', 'Manipulated new-promo status was not forced active.');
    create_status_test_assert((float) promo_code_preview('STATUS_TEST_PROMO', 100)['discount_amount'] === 10.0, 'New active promo did not apply its existing discount rule.');
    $boundary = strtotime('2026-09-30 12:00:00');
    $windowedPromo = [
        'status' => 'active', 'starts_at' => date('Y-m-d H:i:s', $boundary),
        'ends_at' => date('Y-m-d H:i:s', $boundary + 60),
        'usage_limit' => 2, 'used_count' => 0,
    ];
    create_status_test_assert(promo_effective_status($windowedPromo, $boundary - 1) === 'scheduled', 'Promo was available before Starts At.');
    create_status_test_assert(promo_effective_status($windowedPromo, $boundary) === 'active', 'Promo was unavailable at Starts At.');
    create_status_test_assert(promo_effective_status($windowedPromo, $boundary + 60) === 'active', 'Promo expired at rather than after Ends At.');
    create_status_test_assert(promo_effective_status($windowedPromo, $boundary + 61) === 'expired', 'Promo remained active after Ends At.');
    create_status_test_assert(promo_effective_status(array_replace($windowedPromo, ['status' => 'inactive']), $boundary + 61) === 'inactive', 'Manual Inactive status lost precedence.');
    create_status_test_assert(promo_effective_status(array_replace($windowedPromo, ['used_count' => 2]), $boundary + 30) === 'usage_limit_reached', 'Exhausted promo remained active.');
    $cappedPromo = array_replace($windowedPromo, [
        'starts_at' => null, 'ends_at' => null, 'usage_limit' => null,
        'discount_type' => 'percent', 'discount_value' => 90,
        'minimum_subtotal' => 0, 'maximum_discount' => 1,
    ]);
    create_status_test_assert(validated_promo_discount($cappedPromo, 100) === 1.0, 'Percentage cap was not applied.');
    create_status_test_assert(str_contains(promo_discount_description($cappedPromo), 'up to PHP 1.00 maximum discount'), 'Percentage cap description is unclear.');
    create_status_test_assert(validated_promo_discount(array_replace($cappedPromo, ['discount_type' => 'fixed', 'discount_value' => 500, 'maximum_discount' => null]), 60) === 60.0, 'Fixed discount exceeded the subtotal.');
    create_status_test_rejects(
        static fn (): float => validated_promo_discount(array_replace($cappedPromo, ['minimum_subtotal' => 101]), 100),
        'This promo code is unavailable or does not apply to your cart.'
    );
    create_status_test_rejects(
        static fn (): float => validated_promo_discount(array_replace($cappedPromo, ['discount_value' => 101]), 100),
        'This promo code is unavailable or does not apply to your cart.'
    );
    $expiredPromoPayload = array_replace($promoPayload, [
        'code' => 'EXPIRED_TEST_PROMO', 'starts_at' => date('Y-m-d H:i:s', time() - 3600),
        'ends_at' => date('Y-m-d H:i:s', time() - 60), 'status' => 'active',
    ]);
    $expiredPromoId = save_promo_code($promoAdmin, 0, $expiredPromoPayload);
    $expiredPromo = db_find('SELECT * FROM promo_codes WHERE id = ?', 'i', [$expiredPromoId]);
    create_status_test_assert($expiredPromo['status'] === 'active' && promo_effective_status($expiredPromo) === 'expired', 'Stored Active promo did not display as Expired.');
    create_status_test_rejects(static fn (): array => promo_code_preview('EXPIRED_TEST_PROMO', 100), 'This promo code is unavailable or does not apply to your cart.');
    create_status_test_rejects(static fn (): array => checkout_locked_pricing(100, 1, 'EXPIRED_TEST_PROMO'), 'This promo code is unavailable or does not apply to this order.');
    save_promo_code($promoAdmin, $expiredPromoId, $expiredPromoPayload);
    create_status_test_assert(promo_effective_status(db_find('SELECT * FROM promo_codes WHERE id = ?', 'i', [$expiredPromoId])) === 'expired', 'Editing reactivated an expired promo.');
    $limitedPromoId = save_promo_code($promoAdmin, 0, array_replace($promoPayload, ['code' => 'LIMITED_TEST_PROMO', 'usage_limit' => 1]));
    db_execute('UPDATE promo_codes SET used_count = 1 WHERE id = ?', 'i', [$limitedPromoId]);
    create_status_test_assert(promo_effective_status(db_find('SELECT * FROM promo_codes WHERE id = ?', 'i', [$limitedPromoId])) === 'usage_limit_reached', 'Reached usage limit did not change effective status.');
    create_status_test_rejects(static fn (): array => promo_code_preview('LIMITED_TEST_PROMO', 100), 'This promo code is unavailable or does not apply to your cart.');
    create_status_test_rejects(static fn (): array => checkout_locked_pricing(100, 1, 'LIMITED_TEST_PROMO'), 'This promo code is unavailable or does not apply to this order.');
    $scheduledPromoId = save_promo_code($promoAdmin, 0, array_replace($promoPayload, [
        'code' => 'SCHEDULED_TEST_PROMO', 'starts_at' => date('Y-m-d H:i:s', time() + 3600),
        'ends_at' => date('Y-m-d H:i:s', time() + 7200),
    ]));
    create_status_test_assert(promo_effective_status(db_find('SELECT * FROM promo_codes WHERE id = ?', 'i', [$scheduledPromoId])) === 'scheduled', 'Future promo was not Scheduled.');
    create_status_test_rejects(static fn (): array => promo_code_preview('SCHEDULED_TEST_PROMO', 100), 'This promo code is unavailable or does not apply to your cart.');
    create_status_test_rejects(static fn (): array => checkout_locked_pricing(100, 1, 'SCHEDULED_TEST_PROMO'), 'This promo code is unavailable or does not apply to this order.');
    save_promo_code($promoAdmin, 0, array_replace($promoPayload, ['code' => 'MINIMUM_TEST_PROMO', 'minimum_subtotal' => 150]));
    create_status_test_rejects(static fn (): array => promo_code_preview('MINIMUM_TEST_PROMO', 100), 'This promo code is unavailable or does not apply to your cart.');
    create_status_test_rejects(static fn (): array => checkout_locked_pricing(100, 1, 'MINIMUM_TEST_PROMO'), 'This promo code is unavailable or does not apply to this order.');
    save_promo_code($promoAdmin, 0, array_replace($promoPayload, [
        'code' => 'CAPPED_TEST_PROMO', 'discount_value' => 90, 'maximum_discount' => 1,
    ]));
    create_status_test_assert((float) promo_code_preview('CAPPED_TEST_PROMO', 100)['discount_amount'] === 1.0, 'Preview ignored the maximum discount.');
    create_status_test_assert((float) checkout_locked_pricing(100, 1, 'CAPPED_TEST_PROMO')['promo_discount_amount'] === 1.0, 'Checkout ignored the maximum discount.');
    save_promo_code($promoAdmin, 0, array_replace($promoPayload, [
        'code' => 'FIXED_TEST_PROMO', 'discount_type' => 'fixed', 'discount_value' => 500,
    ]));
    create_status_test_assert((float) checkout_locked_pricing(60, 1, 'FIXED_TEST_PROMO')['promo_discount_amount'] === 60.0, 'Checkout fixed discount exceeded subtotal.');
    $promoManagementHtml = create_status_test_render_role_page('admin/promo-codes.php', $administratorId);
    $bulkManagementHtml = create_status_test_render_role_page('admin/discount-rules.php', $administratorId);
    create_status_test_assert(str_contains($bulkManagementHtml, 'Discounts &amp; Promotions')
        && str_contains($bulkManagementHtml, 'Bulk Discounts')
        && str_contains($bulkManagementHtml, 'Promo Codes')
        && str_contains($bulkManagementHtml, 'aria-current="page"')
        && str_contains($promoManagementHtml, 'Discounts &amp; Promotions')
        && str_contains($promoManagementHtml, 'Bulk Discounts')
        && str_contains($promoManagementHtml, 'Promo Codes'), 'Administrator discount and promo tabs did not render as one section.');
    create_status_test_discount_sidebar('admin/discount-rules.php', $administratorId);
    create_status_test_discount_sidebar('admin/promo-codes.php', $administratorId);
    create_status_test_assert(str_contains($promoManagementHtml, 'Expired') && str_contains($promoManagementHtml, 'Scheduled') && str_contains($promoManagementHtml, 'Usage Limit Reached'), 'Promo management table did not show effective statuses.');
    create_status_test_assert(str_contains($promoManagementHtml, '90% discount, up to PHP 1.00 maximum discount'), 'Promo management table did not explain the cap.');
    save_bulk_discount_rule($viewer, 0, [
        'label' => 'Overlap Test Rule', 'min_quantity' => 5, 'max_quantity' => 10,
        'discount_rate' => 7, 'status' => 'active',
    ]);
    $overlapRuleId = (int) db_find('SELECT id FROM bulk_discount_rules WHERE label = ?', 's', ['Overlap Test Rule'])['id'];
    try {
        $overlapManagementHtml = create_status_test_render_role_page('admin/discount-rules.php', $administratorId);
        create_status_test_assert(str_contains($overlapManagementHtml, 'Overlaps an active rule'),
            'Overlapping active bulk rules were not identified in management.');
        $previewBase = pricing_summary(100, 5);
        $previewPromo = promo_code_preview('STATUS_TEST_PROMO', 100, (float) $previewBase['discount_amount']);
        $lockedPricing = checkout_locked_pricing(100, 5, 'STATUS_TEST_PROMO');
        create_status_test_assert((float) $previewBase['discount_rate'] === 7.0
            && (float) $lockedPricing['discount_amount'] === 7.0
            && (float) $previewPromo['discount_amount'] === 9.3
            && (float) $lockedPricing['promo_discount_amount'] === 9.3
            && (float) $lockedPricing['total'] === round((float) $previewBase['total'] - 9.3, 2),
            'Overlapping rules or promo stacking produced different preview and checkout totals.');
    } finally {
        delete_bulk_discount_rule($viewer, $overlapRuleId);
    }
    create_category('Promo Checkout Category', 'Disposable checkout test');
    $checkoutCategoryId = (int) db_find('SELECT id FROM categories WHERE name = ?', 's', ['Promo Checkout Category'])['id'];
    save_product([
        'category_id' => $checkoutCategoryId, 'name' => 'Promo Checkout Product',
        'description' => '', 'price' => 100, 'is_available' => 1,
        'stock_quantity' => 5, 'image_url' => 'uploads/products/promo-checkout-test.png',
    ]);
    $checkoutProductId = (int) db_find('SELECT id FROM products WHERE name = ?', 's', ['Promo Checkout Product'])['id'];
    add_cart_item(fetch_product_by_id($checkoutProductId));
    $ordersBeforeExpiredCheckout = (int) db_find('SELECT COUNT(*) AS total FROM orders')['total'];
    db_execute('UPDATE gcash_payment_accounts SET status = "inactive"');
    create_status_test_assert(active_gcash_account() === null, 'Inactive GCash settings were shown as available at checkout.');
    create_status_test_rejects(
        static fn (): ?int => create_order_from_cart($managedCustomerId, [
            'payment_method' => 'gcash', 'delivery_address' => 'A complete customer delivery address',
            'gcash_account_id' => $gcashId,
            'gcash_reference' => 'DISPOSABLE-REF-1234', 'proof_of_payment_path' => 'uploads/payment-proofs/disposable-test.png',
            'idempotency_key' => bin2hex(random_bytes(24)),
        ]),
        'GCash payment is temporarily unavailable. Please select Cash or contact support.'
    );
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM orders')['total'] === $ordersBeforeExpiredCheckout, 'GCash checkout without an active account created an order.');
    save_gcash_account($viewer, [
        'id' => $gcashId, 'account_name' => 'Status Test GCash', 'account_number' => '09170000003', 'status' => 'active',
    ]);
    create_status_test_assert((int) (active_gcash_account()['id'] ?? 0) === $gcashId, 'Activated GCash account was not available at checkout.');
    create_status_test_rejects(
        static fn (): ?int => create_order_from_cart($managedCustomerId, [
            'payment_method' => 'gcash', 'delivery_address' => 'A complete customer delivery address',
            'gcash_account_id' => $temporaryGcashId,
            'gcash_reference' => 'DISPOSABLE-REF-5678', 'proof_of_payment_path' => 'uploads/payment-proofs/disposable-test.png',
            'idempotency_key' => bin2hex(random_bytes(24)),
        ]),
        'GCash payment is temporarily unavailable. Please select Cash or contact support.'
    );
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM orders')['total'] === $ordersBeforeExpiredCheckout, 'GCash checkout with a removed account created an order.');
    $expiredCheckout = [
        'payment_method' => 'cash', 'delivery_address' => 'A complete customer delivery address',
        'promo_code' => 'EXPIRED_TEST_PROMO', 'idempotency_key' => bin2hex(random_bytes(24)),
    ];
    create_status_test_rejects(
        static fn (): ?int => create_order_from_cart($managedCustomerId, $expiredCheckout),
        'This promo code is unavailable or does not apply to this order.'
    );
    create_status_test_assert((int) db_find('SELECT COUNT(*) AS total FROM orders')['total'] === $ordersBeforeExpiredCheckout, 'Expired promo checkout created an order.');
    create_status_test_assert((int) db_find('SELECT used_count FROM promo_codes WHERE id = ?', 'i', [$expiredPromoId])['used_count'] === 0, 'Failed checkout consumed a promo use.');
    $cappedPromoId = (int) db_find('SELECT id FROM promo_codes WHERE code = ? LIMIT 1', 's', ['CAPPED_TEST_PROMO'])['id'];
    save_promo_code($promoAdmin, $cappedPromoId, array_replace($promoPayload, [
        'code' => 'CAPPED_TEST_PROMO', 'discount_value' => 90,
        'maximum_discount' => 1, 'usage_limit' => 1, 'status' => 'active',
    ]));
    $validCheckout = array_replace($expiredCheckout, [
        'promo_code' => 'CAPPED_TEST_PROMO', 'idempotency_key' => bin2hex(random_bytes(24)),
    ]);
    $promoOrderId = create_order_from_cart($managedCustomerId, $validCheckout);
    create_status_test_assert($promoOrderId !== null && $promoOrderId > 0, 'Valid capped promo checkout failed.');
    create_status_test_assert((float) db_find('SELECT promo_discount_amount FROM orders WHERE id = ?', 'i', [$promoOrderId])['promo_discount_amount'] === 1.0, 'Placed order did not honor the discount cap.');
    create_status_test_assert((int) db_find('SELECT used_count FROM promo_codes WHERE id = ?', 'i', [$cappedPromoId])['used_count'] === 1, 'Successful order did not consume exactly one promo use.');
    create_status_test_assert(create_order_from_cart($managedCustomerId, $validCheckout) === $promoOrderId, 'Idempotent checkout did not return its existing order.');
    create_status_test_assert((int) db_find('SELECT used_count FROM promo_codes WHERE id = ?', 'i', [$cappedPromoId])['used_count'] === 1, 'Idempotent checkout consumed a second promo use.');
    create_status_test_rejects(static fn (): array => promo_code_preview('CAPPED_TEST_PROMO', 100), 'This promo code is unavailable or does not apply to your cart.');
    save_promo_code($promoAdmin, $promoId, $promoPayload);
    create_status_test_assert((db_find('SELECT status FROM promo_codes WHERE id = ?', 'i', [$promoId])['status'] ?? '') === 'inactive', 'Promo edit did not save Inactive status.');
    save_promo_code($promoAdmin, $promoId, $promoPayload);
    create_status_test_assert((db_find('SELECT status FROM promo_codes WHERE id = ?', 'i', [$promoId])['status'] ?? '') === 'inactive', 'Editing inactive promo reactivated it.');
    save_promo_code($promoAdmin, $promoId, array_replace($promoPayload, ['status' => 'active']));
    create_status_test_assert((db_find('SELECT status FROM promo_codes WHERE id = ?', 'i', [$promoId])['status'] ?? '') === 'active'
        && (float) promo_code_preview('STATUS_TEST_PROMO', 100)['discount_amount'] === 10.0,
        'Inactive promo could not be reactivated and applied.');
    save_promo_code($promoAdmin, $promoId, $promoPayload);

    create_status_test_assert(!create_status_test_form_has_status('admin/users.php', [], '//form[input[@name="action" and @value="add_user"]]'), 'Add User still offers status selection.');
    $addUserHtml = create_status_test_render_role_page('admin/users.php', $administratorId);
    $addUserDocument = new DOMDocument();
    @$addUserDocument->loadHTML($addUserHtml);
    $addUserXPath = new DOMXPath($addUserDocument);
    create_status_test_assert(
        $addUserXPath->query('//form[input[@name="action" and @value="add_user"]]//input[@name="confirm_password" and @required]')->length === 1,
        'Add User is missing required password confirmation.'
    );
    create_status_test_assert(create_status_test_form_has_status('admin/users.php', ['edit' => (string) $staffId], '//form[input[@name="action" and @value="update_user"]]'), 'Edit User lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/products.php', [], '//form[input[@name="action" and @value="save_product"]]'), 'Add Product still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/products.php', ['edit' => (string) $productId], '//form[input[@name="action" and @value="save_product"]]'), 'Edit Product lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/categories.php', [], '//form[input[@name="action" and @value="save_category"]]'), 'Add Category still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/categories.php', ['edit' => (string) $categoryId], '//form[input[@name="action" and @value="save_category"]]'), 'Edit Category lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/discount-rules.php', [], '//form[input[@name="action" and @value="save_rule"]]'), 'Add Discount Rule still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/discount-rules.php', ['edit' => (string) $rule['id']], '//form[input[@name="action" and @value="save_rule"]]'), 'Edit Discount Rule lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/discount-rules.php', [], '//form[input[@name="action" and @value="delete_rule"]]'), 'Admin Discount Rule delete action is missing.');
    create_status_test_assert(!create_status_test_form_has_status('admin/support-faqs.php', [], '//form[input[@name="faq_id"]]'), 'Add FAQ Rule still offers status selection.');
    db_execute(
        'INSERT INTO support_faqs (category, question, keywords, answer, priority, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, "inactive", NOW(), NOW())',
        'ssss',
        ['general_inquiry', 'Status test FAQ', 'status test', 'Test answer']
    );
    $faqId = db_last_id();
    create_status_test_assert(support_faq_reply(['category' => 'general_inquiry'], 'status test') === null, 'Inactive FAQ matched a customer message.');
    db_execute('UPDATE support_faqs SET status = "active" WHERE id = ?', 'i', [$faqId]);
    create_status_test_assert(support_faq_reply(['category' => 'general_inquiry'], 'status test') === 'Test answer', 'Active FAQ did not match its keyword.');
    try {
        support_disable_faq_rule($faqId, ['id' => $managerId, 'role' => 'manager']);
        throw new RuntimeException('Manager disabled an FAQ rule.');
    } catch (RuntimeException $exception) {
        create_status_test_assert($exception->getMessage() === 'Only the Administrator can disable FAQ rules.', 'Unexpected FAQ role rejection.');
    }
    support_disable_faq_rule($faqId, $viewer);
    create_status_test_assert(support_faq_reply(['category' => 'general_inquiry'], 'status test') === null, 'Disabled FAQ still matched a customer message.');
    create_status_test_rejects(static fn () => support_disable_faq_rule(99999999, $viewer), 'FAQ rule was not found.');
    create_status_test_assert(create_status_test_form_has_status('admin/support-faqs.php', ['edit' => (string) $faqId], '//form[input[@name="faq_id"]]'), 'Edit FAQ Rule lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/gcash-settings.php', [], '//form[input[@name="action" and @value="save_account"]]'), 'Add GCash Account still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/gcash-settings.php', ['edit' => (string) $gcashId], '//form[input[@name="action" and @value="save_account"]]'), 'Edit GCash Account lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/shop-location.php', [], '//form[input[@name="action" and @value="add_location"]]'), 'Add Shop Location still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/shop-location.php', ['shop_id' => (string) $locationId], '//form[input[@name="action" and @value="save_location"]]'), 'Edit Shop Location lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/promo-codes.php', [], '//form[input[@name="action" and @value="save_promo"]]'), 'Admin New Promo Code still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('admin/promo-codes.php', ['edit' => (string) $promoId], '//form[input[@name="action" and @value="save_promo"]]'), 'Admin Edit Promo Code lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('admin/promo-codes.php', [], '//form[input[@name="action" and @value="delete_promo"]]'), 'Admin Promo Code delete action is missing.');

    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, "manager", "active", NOW(), NOW())',
        'sssss',
        ['Status Test Manager', 'status-manager@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '09170000005', 'Test address']
    );
    $managerId = db_last_id();
    $_SESSION['user_id'] = $managerId;
    clear_current_user_cache();
    create_status_test_rejects(
        static fn (): int => create_managed_user(array_replace($managedPayload, [
            'email' => 'manager-forged-customer@example.test', 'phone' => '09170000010',
            'address' => 'A complete customer delivery address', 'role' => 'customer',
        ])),
        'You do not have permission to create this account role.'
    );
    $managerCreatedRiderId = create_managed_user(array_replace($managedPayload, [
        'full_name' => 'Manager Created Rider',
        'email' => 'manager-created-rider@example.test',
        'phone' => '09170000014',
        'role' => 'rider',
        'status' => 'inactive',
    ]));
    $managerCreatedRider = db_find('SELECT role, status FROM users WHERE id = ? LIMIT 1', 'i', [$managerCreatedRiderId]);
    create_status_test_assert(
        ($managerCreatedRider['role'] ?? '') === 'rider' && ($managerCreatedRider['status'] ?? '') === 'active',
        'Manager-created Rider did not retain the allowed role and enforced Active status.'
    );
    create_status_test_assert(!create_status_test_form_has_status('manager/staff.php', [], '//form[input[@name="action" and @value="add_staff"]]'), 'Manager Add Staff still offers status selection.');
    $managerAccountsHtml = create_status_test_render_role_page('manager/staff.php', $managerId);
    $managerAccountsDocument = new DOMDocument();
    @$managerAccountsDocument->loadHTML($managerAccountsHtml);
    $managerAccountsXPath = new DOMXPath($managerAccountsDocument);
    $managerAccountForm = '//form[input[@name="action" and @value="add_staff"]]';
    create_status_test_assert(
        $managerAccountsXPath->query($managerAccountForm . '//select[@name="role" and @required]/option[@value="staff"]')->length === 1
        && $managerAccountsXPath->query($managerAccountForm . '//select[@name="role" and @required]/option[@value="rider"]')->length === 1
        && $managerAccountsXPath->query($managerAccountForm . '//select[@name="role"]/option[@value="" and @disabled]')->length === 1
        && $managerAccountsXPath->query($managerAccountForm . '//select[@name="role"]/option[not(@value="") and not(@value="staff") and not(@value="rider")]')->length === 0,
        'Manager account form does not restrict role selection to Staff and Rider.'
    );
    create_status_test_assert(
        str_contains($managerAccountsHtml, 'manager-created-rider@example.test'),
        'Manager-created Rider is missing from Staff Account Management.'
    );
    create_status_test_assert(!create_status_test_form_has_status('manager/products.php', [], '//form[input[@name="action" and @value="add_product"]]'), 'Manager Add Product still offers status selection.');
    create_status_test_assert(!create_status_test_form_has_status('manager/discount-rules.php', [], '//form[input[@name="action" and @value="save_rule"]]'), 'Manager Add Discount Rule still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('manager/discount-rules.php', ['edit' => (string) $rule['id']], '//form[input[@name="action" and @value="save_rule"]]'), 'Manager Edit Discount Rule lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('manager/discount-rules.php', [], '//form[input[@name="action" and @value="delete_rule"]]'), 'Manager Discount Rule delete action is missing.');
    create_status_test_assert(!create_status_test_form_has_status('manager/promo-codes.php', [], '//form[input[@name="action" and @value="save_promo"]]'), 'Manager New Promo Code still offers status selection.');
    create_status_test_assert(create_status_test_form_has_status('manager/promo-codes.php', ['edit' => (string) $promoId], '//form[input[@name="action" and @value="save_promo"]]'), 'Manager Edit Promo Code lost status selection.');
    create_status_test_assert(!create_status_test_form_has_status('manager/promo-codes.php', [], '//form[input[@name="action" and @value="delete_promo"]]'), 'Manager Promo Code delete action is missing.');
    $managerPromoHtml = create_status_test_render_role_page('manager/promo-codes.php', $managerId);
    $managerBulkHtml = create_status_test_render_role_page('manager/discount-rules.php', $managerId);
    create_status_test_assert(str_contains($managerBulkHtml, 'Discounts &amp; Promotions')
        && str_contains($managerBulkHtml, 'Bulk Discounts')
        && str_contains($managerPromoHtml, 'Discounts &amp; Promotions')
        && str_contains($managerPromoHtml, 'Promo Codes'), 'Manager discount and promo tabs did not render as one section.');
    create_status_test_discount_sidebar('manager/discount-rules.php', $managerId);
    create_status_test_discount_sidebar('manager/promo-codes.php', $managerId);
    create_status_test_assert(str_contains($managerPromoHtml, 'Expired') && str_contains($managerPromoHtml, 'Usage Limit Reached'), 'Manager promo table did not show effective statuses.');

    foreach ([[5, 5], [1, 1], [100, 100], [10, 5]] as [$minimum, $maximum]) {
        create_status_test_rejects(
            static fn (): array => validate_bulk_discount_quantity_range($minimum, $maximum),
            'Maximum Quantity must be greater than Minimum Quantity.'
        );
    }
    create_status_test_rejects(
        static fn (): array => validate_bulk_discount_quantity_range(5, ''),
        'Maximum Quantity is required.'
    );
    foreach ([[5, 6], [1, 100], [10, 4294967295]] as [$minimum, $maximum]) {
        create_status_test_assert(validate_bulk_discount_quantity_range($minimum, $maximum) === [$minimum, $maximum], 'Valid discount range was rejected.');
    }

    $originalRule = db_find('SELECT min_quantity, max_quantity, status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $rule['id']]);
    foreach (['not-a-number', 'INF', '-1', '101'] as $invalidRate) {
        create_status_test_rejects(
            static fn () => save_bulk_discount_rule($viewer, (int) $rule['id'], [
                'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 10,
                'discount_rate' => $invalidRate, 'status' => 'active',
            ]),
            'Discount rate must be between 0 and 100.'
        );
    }
    create_status_test_rejects(
        static fn () => save_bulk_discount_rule($viewer, (int) $rule['id'], [
            'label' => 'Status Test Rule', 'min_quantity' => 5, 'max_quantity' => 10,
            'discount_rate' => 10, 'status' => 'forged',
        ]),
        'Please select a valid rule status.'
    );
    create_status_test_rejects(
        static fn () => save_bulk_discount_rule($viewer, 999999, [
            'label' => 'Missing Rule', 'min_quantity' => 5, 'max_quantity' => 10,
            'discount_rate' => 10, 'status' => 'active',
        ]),
        'Discount rule does not exist.'
    );
    create_status_test_assert(
        db_find('SELECT min_quantity, max_quantity, status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $rule['id']]) === $originalRule,
        'An invalid rate, status, or target changed the saved discount rule.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['discount_rate' => '10', 'status' => 'active'];
    $_POST = ['label' => 'Forged Query Rule', 'min_quantity' => '5', 'max_quantity' => '10'];
    create_status_test_rejects(
        static fn (): array => bulk_discount_rule_payload_from_request((int) $rule['id']),
        'Discount rate must be between 0 and 100.'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    foreach ([[5, 5], [10, 5], [5, null]] as [$minimum, $maximum]) {
        create_status_test_rejects(
            static fn () => save_bulk_discount_rule($viewer, (int) $rule['id'], [
                'label' => 'Status Test Rule', 'min_quantity' => $minimum, 'max_quantity' => $maximum,
                'discount_rate' => 10, 'status' => 'active',
            ]),
            $maximum === null ? 'Maximum Quantity is required.' : 'Maximum Quantity must be greater than Minimum Quantity.'
        );
        create_status_test_assert(
            db_find('SELECT min_quantity, max_quantity, status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $rule['id']]) === $originalRule,
            'Invalid edit or activation changed the saved discount rule.'
        );
    }
    create_status_test_rejects(
        static fn () => save_bulk_discount_rule($viewer, 0, [
            'label' => 'Invalid New Rule', 'min_quantity' => 5, 'max_quantity' => 5,
            'discount_rate' => 10, 'status' => 'active',
        ]),
        'Maximum Quantity must be greater than Minimum Quantity.'
    );
    create_status_test_assert(db_find('SELECT id FROM bulk_discount_rules WHERE label = ?', 's', ['Invalid New Rule']) === null, 'Invalid rule was inserted.');
    foreach ([[5, 6], [1, 100]] as [$minimum, $maximum]) {
        $label = 'Valid Range ' . $minimum . '-' . $maximum;
        save_bulk_discount_rule($viewer, 0, [
            'label' => $label, 'min_quantity' => $minimum, 'max_quantity' => $maximum,
            'discount_rate' => 3, 'status' => 'inactive',
        ]);
        $saved = db_find('SELECT min_quantity, max_quantity, status FROM bulk_discount_rules WHERE label = ?', 's', [$label]);
        create_status_test_assert((int) ($saved['min_quantity'] ?? 0) === $minimum
            && (int) ($saved['max_quantity'] ?? 0) === $maximum
            && ($saved['status'] ?? '') === 'active', 'Valid discount rule was not created active.');
    }

    db_execute(
        'INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status) VALUES (?, ?, NULL, 10, "active")',
        'si', ['Legacy Open End', 10]
    );
    create_status_test_assert(
        !in_array('Legacy Open End', array_column(active_bulk_discount_rules(), 'label'), true),
        'A rule without a required maximum remained eligible at cart preview.'
    );
    db_execute(
        'INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status) VALUES (?, ?, ?, 10, "active")',
        'sii', ['Legacy Equal Range', 5, 5]
    );
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_28_customer_email_verification_codes.sql');
    create_status_test_schema(get_db(), $migration);
    create_status_test_schema(get_db(), $migration);
    $openEnded = db_find('SELECT max_quantity, status FROM bulk_discount_rules WHERE label = ?', 's', ['Legacy Open End']);
    create_status_test_assert((int) ($openEnded['max_quantity'] ?? 0) === 4294967295 && ($openEnded['status'] ?? '') === 'active', 'Legacy open-ended rule was not preserved.');
    $equalRange = db_find('SELECT max_quantity, status FROM bulk_discount_rules WHERE label = ?', 's', ['Legacy Equal Range']);
    create_status_test_assert(($equalRange['status'] ?? '') === 'inactive', 'Legacy equal-range rule remained active.');
    $equalRule = db_find('SELECT id FROM bulk_discount_rules WHERE label = ?', 's', ['Legacy Equal Range']);
    create_status_test_rejects(
        static fn () => save_bulk_discount_rule($viewer, (int) $equalRule['id'], [
            'label' => 'Legacy Equal Range', 'min_quantity' => 5, 'max_quantity' => 5,
            'discount_rate' => 10, 'status' => 'active',
        ]),
        'Maximum Quantity must be greater than Minimum Quantity.'
    );
    create_status_test_assert(
        (db_find('SELECT status FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $equalRule['id']])['status'] ?? '') === 'inactive',
        'Invalid legacy rule was reactivated.'
    );

    $deleteTarget = db_find('SELECT id FROM bulk_discount_rules WHERE label = ?', 's', ['Valid Range 5-6']);
    create_status_test_assert($deleteTarget !== null, 'Discount delete test target is missing.');
    try {
        delete_bulk_discount_rule(['id' => $administratorId, 'role' => 'staff'], (int) $deleteTarget['id']);
        throw new RuntimeException('Unauthorized discount deletion was accepted.');
    } catch (RuntimeException $exception) {
        create_status_test_assert($exception->getMessage() === 'You do not have permission to delete discount rules.', 'Unexpected unauthorized-delete result.');
    }
    create_status_test_assert(db_find('SELECT id FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $deleteTarget['id']]) !== null, 'Unauthorized deletion changed a rule.');
    try {
        delete_bulk_discount_rule(['id' => $managedCustomerId, 'role' => 'manager'], (int) $deleteTarget['id']);
        throw new RuntimeException('Forged manager role deleted a discount rule.');
    } catch (RuntimeException $exception) {
        create_status_test_assert($exception->getMessage() === 'You do not have permission to delete discount rules.', 'Unexpected forged-role delete result.');
    }
    create_status_test_assert(
        validation_error_field('Maximum Quantity must be greater than Minimum Quantity.') === 'max_quantity',
        'Maximum Quantity server error was attached to the wrong field.'
    );

    delete_bulk_discount_rule($viewer, (int) $deleteTarget['id']);
    create_status_test_assert(db_find('SELECT id FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $deleteTarget['id']]) === null, 'Administrator deletion did not remove the rule.');
    create_status_test_assert(db_find('SELECT id FROM audit_logs WHERE action = ? AND description LIKE ? LIMIT 1', 'ss', ['bulk_discount_rule_deleted', '%#' . $deleteTarget['id'] . '%']) !== null, 'Discount deletion was not audited.');
    create_status_test_rejects(
        static fn () => delete_bulk_discount_rule($viewer, (int) $deleteTarget['id']),
        'Discount rule does not exist.'
    );

    $manager = db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['status-manager@example.test']);
    $managerTarget = db_find('SELECT id FROM bulk_discount_rules WHERE label = ?', 's', ['Valid Range 1-100']);
    delete_bulk_discount_rule(['id' => (int) $manager['id'], 'role' => 'manager'], (int) $managerTarget['id']);
    create_status_test_assert(db_find('SELECT id FROM bulk_discount_rules WHERE id = ?', 'i', [(int) $managerTarget['id']]) === null, 'Manager deletion did not remove the rule.');

    foreach (db_select('SELECT id FROM bulk_discount_rules') as $remainingRule) {
        delete_bulk_discount_rule($viewer, (int) $remainingRule['id']);
    }
    create_status_test_assert(fetch_bulk_discount_rules(true) === [], 'Deleting the last discount rule recreated default rules.');
    create_status_test_assert(bulk_discount_rate_for_quantity(10) === 0.0, 'Deleted discounts still affect pricing.');

    $manager = db_find('SELECT id FROM users WHERE email = ? LIMIT 1', 's', ['status-manager@example.test']);
    $promoManager = ['id' => (int) $manager['id'], 'role' => 'manager', 'status' => 'active'];
    try {
        delete_promo_code(['id' => $staffId, 'role' => 'staff', 'status' => 'active'], $promoId);
        throw new RuntimeException('Unauthorized promo deletion was accepted.');
    } catch (RuntimeException $exception) {
        create_status_test_assert($exception->getMessage() === 'Only an active administrator or manager can manage promo codes.', 'Unexpected unauthorized promo-delete result.');
    }
    create_status_test_assert(db_find('SELECT id FROM promo_codes WHERE id = ?', 'i', [$promoId]) !== null, 'Unauthorized promo deletion changed a record.');

    $usedPromoId = save_promo_code($promoAdmin, 0, ['code' => 'USED_TEST_PROMO'] + $promoPayload);
    db_execute('UPDATE promo_codes SET used_count = 1 WHERE id = ?', 'i', [$usedPromoId]);
    create_status_test_rejects(
        static fn () => delete_promo_code($promoManager, $usedPromoId),
        'This promo code has order history. Set its status to Inactive instead of deleting it.'
    );
    create_status_test_assert(db_find('SELECT id FROM promo_codes WHERE id = ?', 'i', [$usedPromoId]) !== null, 'Used promo was deleted.');

    $historicalPromoId = save_promo_code($promoAdmin, 0, ['code' => 'ORDER_TEST_PROMO'] + $promoPayload);
    db_execute(
        'INSERT INTO users (full_name, email, password, phone, address, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, "customer", "active", NOW(), NOW())',
        'sssss', ['Promo Test Customer', 'promo-customer@example.test', password_hash('TestPassword123', PASSWORD_DEFAULT), '09170000009', 'Test address']
    );
    $promoCustomerId = db_last_id();
    db_execute(
        'INSERT INTO orders (reference_no, customer_id, promo_code, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())',
        'sis', ['PROMO-TEST-ORDER', $promoCustomerId, 'ORDER_TEST_PROMO']
    );
    create_status_test_rejects(
        static fn () => delete_promo_code($promoAdmin, $historicalPromoId),
        'This promo code has order history. Set its status to Inactive instead of deleting it.'
    );
    create_status_test_assert(db_find('SELECT id FROM promo_codes WHERE id = ?', 'i', [$historicalPromoId]) !== null, 'Promo with historical order was deleted.');

    delete_promo_code($promoManager, $promoId);
    create_status_test_assert(db_find('SELECT id FROM promo_codes WHERE id = ?', 'i', [$promoId]) === null, 'Manager did not delete unused promo.');
    create_status_test_assert(db_find('SELECT id FROM audit_logs WHERE action = ? AND description LIKE ? LIMIT 1', 'ss', ['promo_code_deleted', '%#' . $promoId . '%']) !== null, 'Promo deletion was not audited.');
    create_status_test_rejects(
        static fn () => delete_promo_code($promoAdmin, $promoId),
        'Promo code does not exist.'
    );
    $adminPromoId = save_promo_code($promoAdmin, 0, ['code' => 'ADMIN_DELETE_PROMO'] + $promoPayload);
    delete_promo_code($promoAdmin, $adminPromoId);
    create_status_test_assert(db_find('SELECT id FROM promo_codes WHERE id = ?', 'i', [$adminPromoId]) === null, 'Administrator did not delete unused promo.');

    $activeStaffId = create_managed_user([
        'full_name' => 'View Test Staff', 'email' => 'view-staff@example.test',
        'password' => 'TestPassword123', 'phone' => '09170000006',
        'address' => 'Test address', 'role' => 'staff',
    ]);
    db_execute(
        'INSERT INTO orders (reference_no, customer_id, payment_method, payment_status, created_at, updated_at)
         VALUES (?, ?, "gcash", "pending", NOW(), NOW())',
        'si',
        ['PAYMENT-REVIEW-TEST', $managedCustomerId]
    );
    $reviewOrderId = db_last_id();
    db_execute(
        'INSERT INTO transactions (order_id, payment_method, amount, transaction_status, gcash_reference, proof_of_payment_path, created_at, updated_at)
         VALUES (?, "gcash", 100, "pending", ?, ?, NOW(), NOW())',
        'iss',
        [$reviewOrderId, 'REVIEW-TEST-REFERENCE-001', 'uploads/payment-proofs/disposable-review-test.png']
    );
    $reviewTransactionId = db_last_id();
    create_status_test_rejects(
        static fn () => review_gcash_payment($reviewTransactionId, 'verify', $managedCustomerId),
        'Only active staff, managers, or administrators can review GCash payments.'
    );
    create_status_test_assert(
        (db_find('SELECT transaction_status FROM transactions WHERE id = ?', 'i', [$reviewTransactionId])['transaction_status'] ?? '') === 'pending',
        'Unauthorized review changed the transaction.'
    );
    review_gcash_payment($reviewTransactionId, 'verify', $activeStaffId);
    create_status_test_assert(
        (db_find('SELECT transaction_status FROM transactions WHERE id = ?', 'i', [$reviewTransactionId])['transaction_status'] ?? '') === 'completed'
        && (db_find('SELECT payment_status FROM orders WHERE id = ?', 'i', [$reviewOrderId])['payment_status'] ?? '') === 'paid',
        'Authorized review did not mark the transaction and order paid.'
    );
    create_status_test_rejects(
        static fn () => review_gcash_payment($reviewTransactionId, 'verify', $activeStaffId),
        'This GCash payment has already been reviewed.'
    );
    create_status_test_assert(
        (int) (db_find('SELECT COUNT(*) AS total FROM payment_review_history WHERE transaction_id = ?', 'i', [$reviewTransactionId])['total'] ?? 0) === 1,
        'Repeated review created duplicate history.'
    );
    db_execute(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, created_at)
         VALUES (?, ?, 2, 100, 200, NOW())',
        'ii',
        [$reviewOrderId, $checkoutProductId]
    );
    db_execute('UPDATE orders SET total_amount = 200 WHERE id = ?', 'i', [$reviewOrderId]);
    db_execute(
        'INSERT INTO deliveries (order_id, assigned_staff_id, delivery_status, delivery_address, created_at)
         VALUES (?, ?, "assigned", ?, NOW())',
        'iis',
        [$reviewOrderId, $activeStaffId, 'Disposable test delivery address']
    );
    db_execute(
        'INSERT INTO orders (reference_no, customer_id, payment_method, payment_status, created_at, updated_at)
         VALUES (?, ?, "gcash", "pending", NOW(), NOW())',
        'si',
        ['PAYMENT-WAITING-TEST', $managedCustomerId]
    );
    $waitingOrderId = db_last_id();
    db_execute(
        'INSERT INTO transactions (order_id, payment_method, amount, transaction_status, gcash_reference, created_at, updated_at)
         VALUES (?, "gcash", 100, "pending", ?, NOW(), NOW())',
        'is',
        [$waitingOrderId, 'REVIEW-TEST-REFERENCE-002']
    );
    $waitingTransactionId = db_last_id();
    create_status_test_rejects(
        static fn () => review_gcash_payment($waitingTransactionId, 'verify', $managerId),
        'A GCash reference number and payment proof are required before review.'
    );
    create_status_test_assert(
        (db_find('SELECT transaction_status FROM transactions WHERE id = ?', 'i', [$waitingTransactionId])['transaction_status'] ?? '') === 'pending',
        'Missing-proof review changed the transaction.'
    );
    foreach ([
        ['admin/transactions.php', $administratorId, 'data-label="Reference"'],
        ['manager/transactions.php', $managerId, 'data-label="GCash Reference"'],
        ['staff/transactions.php', $activeStaffId, 'data-label="Actions"'],
    ] as [$page, $userId, $expected]) {
        $html = create_status_test_render_role_page($page, $userId);
        create_status_test_assert(str_contains($html, $expected), $page . ' did not render a labeled responsive transaction row.');
        create_status_test_assert(str_contains($html, 'Waiting for proof and reference'), $page . ' offered a review action without payment evidence.');
    }
    foreach ([
        ['admin', $administratorId], ['manager', $managerId], ['staff', $activeStaffId],
    ] as [$folder, $roleUserId]) {
        foreach (['profile.php' => 'Update Account', 'support.php' => 'Support Chat', 'contact-messages.php' => 'Contact Messages'] as $page => $expected) {
            $html = create_status_test_render_role_page($folder . '/' . $page, $roleUserId);
            create_status_test_assert(str_contains($html, $expected), 'Shared ' . $page . ' did not render for ' . $folder . '.');
        }
    }

    foreach ([
        ['admin/notifications.php', $administratorId, 'Send System Notifications'],
        ['admin/support-faqs.php', $administratorId, 'Support FAQ Rules'],
        ['admin/shop-location.php', $administratorId, 'Shop Location Management'],
        ['manager/shop-location.php', $managerId, 'Shop Location'],
        ['admin/gcash-settings.php', $administratorId, 'GCash Payment Settings'],
        ['customer/support.php', $managedCustomerId, 'Customer Support'],
    ] as [$page, $userId, $expected]) {
        $html = create_status_test_render_role_page($page, $userId);
        create_status_test_assert(str_contains($html, $expected), $page . ' did not render for its authorized role.');
    }

    foreach (array_keys(report_type_options(true)) as $reportType) {
        $reportData = build_report_data($reportType);
        create_status_test_assert($reportData['headers'] !== [] && is_array($reportData['rows']), 'Report failed to build: ' . $reportType);
    }
    $todayReportDate = date('m/d/y');
    $salesRows = build_report_data('sales', ['category_id' => $checkoutCategoryId])['rows'];
    create_status_test_assert(
        count(array_filter($salesRows, static fn (array $row): bool => $row[0] === 'Promo Checkout Product' && (int) $row[2] === 2)) === 1,
        'Sales report did not count paid product quantities in the selected category.'
    );
    $completedTransactionRows = build_report_data('transactions', [
        'status' => 'completed', 'date_from' => $todayReportDate, 'date_to' => $todayReportDate,
    ])['rows'];
    create_status_test_assert(
        count(array_filter($completedTransactionRows, static fn (array $row): bool => $row[0] === 'PAYMENT-REVIEW-TEST')) === 1
        && count(array_filter($completedTransactionRows, static fn (array $row): bool => $row[0] === 'PAYMENT-WAITING-TEST')) === 0,
        'Transaction status/date filters returned the wrong rows.'
    );
    $assignedDeliveryRows = build_report_data('deliveries', ['status' => 'assigned'])['rows'];
    create_status_test_assert(
        count(array_filter($assignedDeliveryRows, static fn (array $row): bool => $row[0] === 'PAYMENT-REVIEW-TEST')) === 1,
        'Delivery report did not apply its status filter.'
    );
    $activeProductRows = build_report_data('products', ['status' => 'active', 'category_id' => $checkoutCategoryId])['rows'];
    create_status_test_assert(
        count(array_filter($activeProductRows, static fn (array $row): bool => $row[0] === 'Promo Checkout Product')) === 1,
        'Product report did not apply category/status filters.'
    );
    create_status_test_assert(build_report_data('transactions', ['date_from' => '12/31/99'])['rows'] === [], 'Future date filter returned past transactions.');
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $malformedDateErrors = date_filter_errors(['date_from' => ['not-a-date']]);
        create_status_test_assert(isset($malformedDateErrors['date_from']), 'Array-valued date filter was not rejected.');
        create_status_test_assert(normalize_report_filters(['status' => ['completed'], 'category_id' => ['1']])['status'] === '', 'Array-valued report filter was accepted.');
    } finally {
        restore_error_handler();
    }
    create_status_test_assert(report_status_options('user_activity') === [], 'User activity exposes an irrelevant status filter.');
    create_status_test_assert(isset(report_status_options('transactions')['accepted'])
        && !isset(report_status_options('transactions')['confirmed']), 'Transaction report statuses do not match the database.');
    $adminReportHtml = create_status_test_render_role_page('admin/reports.php', $administratorId);
    $managerReportHtml = create_status_test_render_role_page('manager/reports.php', (int) $manager['id']);
    create_status_test_assert(str_contains($adminReportHtml, 'Export CSV') && str_contains($managerReportHtml, 'Export CSV'), 'Valid report omitted CSV export.');
    create_status_test_assert(str_contains($adminReportHtml, 'data-label="Product"')
        && str_contains($managerReportHtml, 'data-label="Product"'), 'Responsive report table is missing column labels.');
    $transactionReportHtml = create_status_test_render_role_page('manager/reports.php', (int) $manager['id'], ['report_type' => 'transactions']);
    create_status_test_assert(str_contains($transactionReportHtml, 'Transaction Status')
        && !str_contains($transactionReportHtml, 'name="category_id"'), 'Transaction report shows the wrong filters.');
    $invalidDateHtml = create_status_test_render_role_page('admin/reports.php', $administratorId, ['date_from' => '99/99/26']);
    create_status_test_assert(!str_contains($invalidDateHtml, 'Export CSV'), 'Invalid date still offers an unfiltered export.');
    $malformedDateHtml = create_status_test_render_role_page('manager/reports.php', $managerId, ['date_from' => ['not-a-date']]);
    create_status_test_assert(str_contains($malformedDateHtml, 'Enter a valid start date') && !str_contains($malformedDateHtml, 'Export CSV'), 'Array-valued date did not show a safe validation error.');

    echo "Create defaults, discount and promo rules, forged-role delete denial, report and transaction queries, payment-review authorization and duplicate checks, responsive labels, shared views, and rendered form checks passed in a disposable database.\n";
} finally {
    $admin->query('DROP DATABASE `' . $databaseName . '`');
    $admin->close();
}
