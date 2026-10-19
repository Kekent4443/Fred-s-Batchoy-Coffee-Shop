<?php

declare(strict_types=1);

function support_categories(): array
{
    return [
        'product_inquiry' => 'Product inquiry',
        'product_availability' => 'Product availability',
        'order_status' => 'Order status',
        'payment_concern' => 'Payment concern',
        'delivery_concern' => 'Delivery concern',
        'cancellation_request' => 'Cancellation request',
        'refund_request' => 'Refund request',
        'account_concern' => 'Account concern',
        'general_inquiry' => 'General inquiry',
        'other' => 'Other',
    ];
}

function support_statuses(): array
{
    return [
        'open' => 'Open',
        'pending_staff' => 'Pending Staff',
        'pending_customer' => 'Pending Customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
}

function support_staff_roles(): array
{
    return ['administrator', 'manager', 'staff'];
}

function support_auto_reply_delay_seconds(): int
{
    $configured = (int) (getenv('SUPPORT_AUTO_REPLY_DELAY_SECONDS') ?: 60);

    return max(60, $configured);
}

function ensure_support_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute(
        'CREATE TABLE IF NOT EXISTS support_conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            assigned_user_id INT UNSIGNED NULL,
            product_id INT UNSIGNED NULL,
            order_id INT UNSIGNED NULL,
            category VARCHAR(60) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT "open",
            automation_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_customer_message_at DATETIME NULL,
            last_human_reply_at DATETIME NULL,
            last_auto_reply_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            INDEX idx_support_conversations_customer (customer_id, status, created_at),
            INDEX idx_support_conversations_assigned (assigned_user_id, status, updated_at),
            INDEX idx_support_conversations_order (order_id),
            INDEX idx_support_conversations_product (product_id),
            INDEX idx_support_conversations_status (status, updated_at),
            INDEX idx_support_conversations_last_customer (last_customer_message_at),
            CONSTRAINT fk_support_conversations_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_support_conversations_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_support_conversations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_support_conversations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    db_execute(
        'CREATE TABLE IF NOT EXISTS support_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NULL,
            sender_type VARCHAR(40) NOT NULL,
            message TEXT NOT NULL,
            is_automated TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            reply_to_message_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_messages_conversation (conversation_id, id),
            INDEX idx_support_messages_read (conversation_id, is_read, sender_type),
            INDEX idx_support_messages_created (created_at),
            UNIQUE KEY uniq_support_auto_reply (conversation_id, reply_to_message_id, is_automated),
            CONSTRAINT fk_support_messages_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_support_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_support_messages_reply_to FOREIGN KEY (reply_to_message_id) REFERENCES support_messages(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    db_execute(
        'CREATE TABLE IF NOT EXISTS support_faqs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(60) NOT NULL,
            question VARCHAR(255) NOT NULL,
            keywords TEXT NOT NULL,
            answer TEXT NOT NULL,
            priority INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT "active",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_support_faq_question (question),
            INDEX idx_support_faqs_category_status (category, status, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    db_execute(
        'CREATE TABLE IF NOT EXISTS support_handoffs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            trigger_message_id INT UNSIGNED NOT NULL,
            assigned_user_id INT UNSIGNED NULL,
            reason VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "open",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            INDEX idx_support_handoffs_conversation (conversation_id, status),
            UNIQUE KEY uniq_support_handoffs_trigger (trigger_message_id),
            INDEX idx_support_handoffs_assigned (assigned_user_id, status),
            CONSTRAINT fk_support_handoffs_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_support_handoffs_trigger FOREIGN KEY (trigger_message_id) REFERENCES support_messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_support_handoffs_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    db_execute(
        'CREATE TABLE IF NOT EXISTS support_internal_notes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_internal_notes_conversation (conversation_id, created_at),
            CONSTRAINT fk_support_internal_notes_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_support_internal_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    support_seed_default_faqs();
    $checked = true;
}

function support_seed_default_faqs(): void
{
    $faqs = [
        ['general_inquiry', 'Store operating hours', 'hours,open,closing,time,schedule', 'Our store operating hours may vary. Please wait for staff confirmation for today\'s exact schedule.', 10],
        ['product_availability', 'Product availability', 'available,availability,stock,in stock,out of stock,price,product', 'I can check current product price and availability when a product is linked to this conversation.', 20],
        ['order_status', 'Order status', 'order status,order,preparing,processing,confirmed,completed,reference', 'I can check the verified status when an order is linked to this conversation.', 30],
        ['payment_concern', 'Payment status', 'payment,paid,gcash,cash,transaction,proof,accepted,pending', 'I can check the recorded payment method and transaction status when an order is linked.', 30],
        ['delivery_concern', 'Delivery status', 'delivery,deliver,tracking,out for delivery,delivered,assigned', 'I can check the recorded delivery status when an order is linked.', 30],
        ['cancellation_request', 'Cancellation request', 'cancel,cancellation,void,stop order', 'Cancellation requests require staff review. Your concern will be forwarded to our staff.', 40],
        ['refund_request', 'Refund request', 'refund,return,money back,reimburse,dispute', 'Refund requests require staff review. Your concern will be forwarded to our staff.', 40],
        ['account_concern', 'Account verification', 'account,verification,approved,login,password', 'Account concerns require staff review unless a verified account status is available.', 15],
        ['general_inquiry', 'Contact information', 'contact,email,phone,call,message', 'You can continue sending your concern here. Staff will reply when human assistance is needed.', 5],
    ];

    foreach ($faqs as [$category, $question, $keywords, $answer, $priority]) {
        db_execute(
            'INSERT IGNORE INTO support_faqs (category, question, keywords, answer, priority, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "active", NOW(), NOW())',
            'ssssi',
            [$category, $question, $keywords, $answer, $priority]
        );
    }
}

function support_is_staff(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), support_staff_roles(), true);
}

function support_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function support_normalize_text(string $message): string
{
    $message = strtolower(trim($message));
    $message = preg_replace('/[^\p{L}\p{N}\s#.-]+/u', ' ', $message) ?? '';

    return trim(preg_replace('/\s+/', ' ', $message) ?? '');
}

function support_validate_category(string $category): string
{
    return array_key_exists($category, support_categories()) ? $category : 'other';
}

function support_message_excerpt(string $message, int $length = 140): string
{
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

    return strlen($message) > $length ? substr($message, 0, $length - 3) . '...' : $message;
}

function support_validate_message(string $message): string
{
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

    if ($message === '') {
        throw new InvalidArgumentException('Please enter a message.');
    }

    if (strlen($message) > 2000) {
        throw new InvalidArgumentException('Messages must be 2,000 characters or fewer.');
    }

    return $message;
}

function support_validate_subject(string $subject): string
{
    $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');

    if ($subject === '') {
        return 'Support concern';
    }

    return strlen($subject) > 180 ? substr($subject, 0, 180) : $subject;
}

function support_faq_payload(array $input, bool $editing): array
{
    $category = (string) ($input['category'] ?? '');
    if (!array_key_exists($category, support_categories())) {
        throw new InvalidArgumentException('Please select a valid FAQ category.');
    }
    $question = trim((string) ($input['question'] ?? ''));
    if ($question === '' || strlen($question) > 255) {
        throw new InvalidArgumentException('FAQ question is required and must be 255 characters or fewer.');
    }
    $keywords = support_validate_message((string) ($input['keywords'] ?? ''));
    if (array_filter(array_map('trim', explode(',', $keywords))) === []) {
        throw new InvalidArgumentException('Enter at least one FAQ keyword.');
    }
    $answer = support_validate_message((string) ($input['answer'] ?? ''));
    if (!array_key_exists('priority', $input) || !is_scalar($input['priority'])) {
        throw new InvalidArgumentException('FAQ priority must be a whole number.');
    }
    $priority = filter_var($input['priority'], FILTER_VALIDATE_INT);
    if ($priority === false) {
        throw new InvalidArgumentException('FAQ priority must be a whole number.');
    }
    $status = $editing
        ? require_allowed_value((string) ($input['status'] ?? ''), ['active', 'inactive'], 'Please select a valid FAQ status.')
        : 'active';

    return compact('category', 'question', 'keywords', 'answer', 'priority', 'status');
}

function support_disable_faq_rule(int $faqId, array $viewer): void
{
    if (($viewer['role'] ?? '') !== 'administrator' || (int) ($viewer['id'] ?? 0) < 1) {
        throw new RuntimeException('Only the Administrator can disable FAQ rules.');
    }
    if ($faqId <= 0) {
        throw new InvalidArgumentException('FAQ rule was not found.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $faq = db_find('SELECT id, status FROM support_faqs WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$faqId]);
        if (!$faq) {
            throw new InvalidArgumentException('FAQ rule was not found.');
        }
        if ($faq['status'] !== 'inactive') {
            db_execute('UPDATE support_faqs SET status = "inactive", updated_at = NOW() WHERE id = ?', 'i', [$faqId]);
            log_audit((int) $viewer['id'], 'support_faq_disabled', 'Disabled support FAQ rule #' . $faqId);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function support_customer_order(int $orderId, int $customerId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $order = fetch_order_by_id($orderId);

    if (!$order || (int) ($order['customer_id'] ?? 0) !== $customerId) {
        return null;
    }

    return $order;
}

function support_product(int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }

    return fetch_product_by_id($productId);
}

function support_fetch_conversation(int $conversationId): ?array
{
    ensure_support_schema();

    return db_find(
        'SELECT c.*, customer.full_name AS customer_name, customer.email AS customer_email,
                assigned.full_name AS assigned_name, assigned.role AS assigned_role,
                p.name AS product_name, p.price AS product_price, p.is_available AS product_is_available, p.stock_quantity AS product_stock_quantity,
                o.reference_no, o.order_status, o.payment_status, o.delivery_status, o.total_amount
         FROM support_conversations c
         INNER JOIN users customer ON customer.id = c.customer_id
         LEFT JOIN users assigned ON assigned.id = c.assigned_user_id
         LEFT JOIN products p ON p.id = c.product_id
         LEFT JOIN orders o ON o.id = c.order_id
         WHERE c.id = ? LIMIT 1',
        'i',
        [$conversationId]
    );
}

function support_can_access_conversation(array $user, array $conversation): bool
{
    if (($user['role'] ?? '') === 'customer') {
        return (int) $conversation['customer_id'] === (int) $user['id'];
    }

    if (in_array((string) ($user['role'] ?? ''), ['administrator', 'manager'], true)) {
        return true;
    }

    if (($user['role'] ?? '') === 'staff') {
        return empty($conversation['assigned_user_id']) || (int) $conversation['assigned_user_id'] === (int) $user['id'];
    }

    return false;
}

function support_require_conversation_access(int $conversationId, array $user): array
{
    $conversation = support_fetch_conversation($conversationId);

    if (!$conversation || !support_can_access_conversation($user, $conversation)) {
        throw new InvalidArgumentException('Support conversation was not found or you do not have access.');
    }

    return $conversation;
}

function support_fetch_messages(int $conversationId, int $afterId = 0, array $viewer = []): array
{
    $sql = 'SELECT m.*, u.full_name, u.role
            FROM support_messages m
            LEFT JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ?';
    $types = 'i';
    $params = [$conversationId];

    if ($afterId > 0) {
        $sql .= ' AND m.id > ?';
        $types .= 'i';
        $params[] = $afterId;
    }

    if (($viewer['role'] ?? '') === 'customer') {
        $sql .= ' AND m.sender_type <> "internal_note"';
    }

    $sql .= ' ORDER BY m.id ASC';

    return db_select($sql, $types, $params);
}

function support_sender_label(array $message): string
{
    if ((int) ($message['is_automated'] ?? 0) === 1 || ($message['sender_type'] ?? '') === 'automated') {
        return 'Automated Response';
    }

    return match ((string) ($message['sender_type'] ?? '')) {
        'customer' => 'Customer',
        'administrator' => 'Administrator',
        'manager' => 'Manager',
        'staff' => 'Staff',
        'internal_note' => 'Internal Note',
        default => 'Support',
    };
}

function support_format_message(array $message): array
{
    return [
        'id' => (int) $message['id'],
        'conversation_id' => (int) $message['conversation_id'],
        'sender_id' => isset($message['sender_id']) ? (int) $message['sender_id'] : null,
        'sender_type' => (string) $message['sender_type'],
        'sender_label' => support_sender_label($message),
        'sender_name' => (string) ($message['full_name'] ?? support_sender_label($message)),
        'message' => (string) $message['message'],
        'is_automated' => (int) ($message['is_automated'] ?? 0) === 1,
        'is_read' => (int) ($message['is_read'] ?? 0) === 1,
        'created_at' => (string) $message['created_at'],
        'created_label' => format_display_datetime($message['created_at'] ?? null),
    ];
}

function support_fetch_conversations(array $viewer, array $filters = [], int $limit = 50): array
{
    ensure_support_schema();

    $role = (string) ($viewer['role'] ?? '');
    $actionStatus = $role === 'customer' ? 'pending_customer' : 'pending_staff';
    $unreadCondition = $role === 'customer'
        ? 'm.sender_type <> "customer"'
        : 'm.sender_type = "customer"';
    $unreadExpression = 'SUM(CASE WHEN m.is_read = 0 AND ' . $unreadCondition . ' THEN 1 ELSE 0 END)';
    $priorityCase = 'CASE
        WHEN c.status = "' . $actionStatus . '" THEN 1
        WHEN ' . $unreadExpression . ' > 0 THEN 2
        WHEN c.status IN ("open", "pending_staff", "pending_customer") THEN 3
        WHEN c.status IN ("resolved", "closed") THEN 5
        ELSE 4
    END';

    $sql = 'SELECT c.*, customer.full_name AS customer_name, customer.email AS customer_email,
                   assigned.full_name AS assigned_name, p.name AS product_name, o.reference_no,
                   ' . $priorityCase . ' AS priority_rank,
                   CASE
                       WHEN ' . $priorityCase . ' = 1 THEN "high"
                       WHEN ' . $priorityCase . ' IN (2, 3) THEN "medium"
                       ELSE "low"
                   END AS priority_level,
                   ' . $unreadExpression . ' AS unread_messages
            FROM support_conversations c
            INNER JOIN users customer ON customer.id = c.customer_id
            LEFT JOIN users assigned ON assigned.id = c.assigned_user_id
            LEFT JOIN products p ON p.id = c.product_id
            LEFT JOIN orders o ON o.id = c.order_id
            LEFT JOIN support_messages m ON m.conversation_id = c.id
            WHERE 1=1';
    $types = '';
    $params = [];

    if ($role === 'customer') {
        $sql .= ' AND c.customer_id = ?';
        $types .= 'i';
        $params[] = (int) $viewer['id'];
    } elseif ($role === 'staff') {
        $sql .= ' AND (c.assigned_user_id IS NULL OR c.assigned_user_id = ?)';
        $types .= 'i';
        $params[] = (int) $viewer['id'];
    } elseif (!in_array($role, ['administrator', 'manager'], true)) {
        return [];
    }

    $status = (string) ($filters['status'] ?? '');
    if ($status !== '' && array_key_exists($status, support_statuses())) {
        $sql .= ' AND c.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    $category = (string) ($filters['category'] ?? '');
    if ($category !== '' && array_key_exists($category, support_categories())) {
        $sql .= ' AND c.category = ?';
        $types .= 's';
        $params[] = $category;
    }

    $assignedUserId = (int) ($filters['assigned_user_id'] ?? 0);
    if ($assignedUserId > 0 && in_array($role, ['administrator', 'manager'], true)) {
        $sql .= ' AND c.assigned_user_id = ?';
        $types .= 'i';
        $params[] = $assignedUserId;
    }

    $orderId = (int) ($filters['order_id'] ?? 0);
    if ($orderId > 0) {
        $sql .= ' AND c.order_id = ?';
        $types .= 'i';
        $params[] = $orderId;
    }

    $productId = (int) ($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $sql .= ' AND c.product_id = ?';
        $types .= 'i';
        $params[] = $productId;
    }

    $search = sanitize_search_query((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (c.subject LIKE ? OR customer.full_name LIKE ? OR customer.email LIKE ? OR o.reference_no LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    if (($filters['unread'] ?? '') === '1') {
        $sql .= ' AND EXISTS (
            SELECT 1 FROM support_messages unread
            WHERE unread.conversation_id = c.id
              AND unread.is_read = 0
              AND ((? = "customer" AND unread.sender_type <> "customer")
               OR (? <> "customer" AND unread.sender_type = "customer"))
        )';
        $types .= 'ss';
        array_push($params, $role, $role);
    }

    $sql .= ' GROUP BY c.id ORDER BY priority_rank ASC, c.updated_at DESC LIMIT ?';
    $types .= 'i';
    $params[] = max(1, min(100, $limit));

    return db_select($sql, $types, $params);
}

function support_unread_count_for_user(array $viewer): int
{
    $rows = support_fetch_conversations($viewer, ['unread' => '1'], 100);

    return array_sum(array_map(static fn (array $row): int => (int) ($row['unread_messages'] ?? 0), $rows));
}

function support_pending_count_for_user(array $viewer): int
{
    $role = (string) ($viewer['role'] ?? '');

    if ($role === 'customer') {
        $row = db_find(
            'SELECT COUNT(*) AS total FROM support_conversations WHERE customer_id = ? AND status = "pending_customer"',
            'i',
            [(int) $viewer['id']]
        );

        return (int) ($row['total'] ?? 0);
    }

    if (in_array($role, ['administrator', 'manager'], true)) {
        $row = db_find('SELECT COUNT(*) AS total FROM support_conversations WHERE status = "pending_staff"');

        return (int) ($row['total'] ?? 0);
    }

    if ($role === 'staff') {
        $row = db_find(
            'SELECT COUNT(*) AS total FROM support_conversations WHERE status = "pending_staff" AND (assigned_user_id IS NULL OR assigned_user_id = ?)',
            'i',
            [(int) $viewer['id']]
        );

        return (int) ($row['total'] ?? 0);
    }

    return 0;
}

function support_badge_unread_count(array $viewer): int
{
    return support_unread_count_for_user($viewer) + support_pending_count_for_user($viewer);
}

function support_notify_staff(string $title, string $message, ?int $assignedUserId = null): void
{
    if ($assignedUserId !== null && $assignedUserId > 0) {
        $assignee = db_find('SELECT role, status, deleted_at FROM users WHERE id = ? LIMIT 1', 'i', [$assignedUserId]);
        if ($assignee && support_is_staff($assignee) && ($assignee['status'] ?? '') === 'active' && empty($assignee['deleted_at'])) {
            create_notification($assignedUserId, $title, $message, 'info');
            return;
        }
    }

    notify_active_roles(['administrator', 'manager', 'staff'], $title, $message, 'info');
}

function support_create_conversation(array $customer, array $data): int
{
    ensure_support_schema();

    $category = support_validate_category((string) ($data['category'] ?? 'other'));
    $subject = support_validate_subject((string) ($data['subject'] ?? ''));
    $message = support_validate_message((string) ($data['message'] ?? ''));
    $customerId = (int) $customer['id'];
    $orderId = (int) ($data['order_id'] ?? 0);
    $productId = (int) ($data['product_id'] ?? 0);
    $order = $orderId > 0 ? support_customer_order($orderId, $customerId) : null;
    $product = $productId > 0 ? support_product($productId) : null;

    if ($orderId > 0 && !$order) {
        throw new InvalidArgumentException('Please select one of your own orders.');
    }

    if ($productId > 0 && !$product) {
        throw new InvalidArgumentException('Please select a valid product.');
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $lockedCustomer = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]);
        if (!$lockedCustomer || ($lockedCustomer['role'] ?? '') !== 'customer' || ($lockedCustomer['status'] ?? '') !== 'active' || !empty($lockedCustomer['deleted_at'])) {
            throw new InvalidArgumentException('Only an active customer can create a support conversation.');
        }

        db_execute(
            'INSERT INTO support_conversations (customer_id, product_id, order_id, category, subject, status, automation_enabled, last_customer_message_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "pending_staff", 1, NOW(), NOW(), NOW())',
            'iiiss',
            [$customerId, $product ? (int) $product['id'] : null, $order ? (int) $order['id'] : null, $category, $subject]
        );
        $conversationId = db_last_id();
        db_execute(
            'INSERT INTO support_messages (conversation_id, sender_id, sender_type, message, is_automated, is_read, created_at)
             VALUES (?, ?, "customer", ?, 0, 0, NOW())',
            'iis',
            [$conversationId, $customerId, $message]
        );
        $messageId = db_last_id();

        support_notify_staff(
            'New Support Conversation',
            (string) $customer['full_name'] . ' submitted a support concern: ' . support_message_excerpt($subject)
        );
        log_audit($customerId, 'support_conversation_created', 'Created support conversation #' . $conversationId);
        support_schedule_auto_reply($conversationId, $messageId);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    return $conversationId;
}

function support_send_message(int $conversationId, array $sender, string $message): int
{
    ensure_support_schema();

    $message = support_validate_message($message);
    $role = (string) $sender['role'];
    $senderType = $role === 'customer' ? 'customer' : $role;

    if (!rate_limit_allow('support_message', (string) $sender['id'], 20, 60)) {
        throw new InvalidArgumentException('You are sending messages too quickly. Please wait a moment.');
    }

    $database = get_db();
    $database->begin_transaction();

    try {
        $conversation = db_find('SELECT * FROM support_conversations WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$conversationId]);
        if (!$conversation || !support_can_access_conversation($sender, $conversation)) {
            throw new InvalidArgumentException('Support conversation was not found or you do not have access.');
        }

        $recent = db_find(
            'SELECT id FROM support_messages
             WHERE conversation_id = ? AND sender_id = ? AND sender_type = ? AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
             LIMIT 1 FOR UPDATE',
            'iiss',
            [$conversationId, (int) $sender['id'], $senderType, $message]
        );
        if ($recent) {
            $database->commit();
            return (int) $recent['id'];
        }

        db_execute(
            'INSERT INTO support_messages (conversation_id, sender_id, sender_type, message, is_automated, is_read, created_at)
             VALUES (?, ?, ?, ?, 0, 0, NOW())',
            'iiss',
            [$conversationId, (int) $sender['id'], $senderType, $message]
        );
        $messageId = db_last_id();

        if ($role === 'customer') {
            db_execute(
                'UPDATE support_conversations
                 SET last_customer_message_at = NOW(), status = "pending_staff", automation_enabled = 1, closed_at = NULL, updated_at = NOW()
                 WHERE id = ?',
                'i',
                [$conversationId]
            );
            support_notify_staff(
                'New Customer Support Message',
                (string) $sender['full_name'] . ' replied to support conversation #' . $conversationId . ': ' . support_message_excerpt($message),
                !empty($conversation['assigned_user_id']) ? (int) $conversation['assigned_user_id'] : null
            );
            log_audit((int) $sender['id'], 'support_customer_message_sent', 'Customer sent support message #' . $messageId . ' in conversation #' . $conversationId);
            support_schedule_auto_reply($conversationId, $messageId);
        } else {
            db_execute(
                'UPDATE support_conversations
                 SET assigned_user_id = COALESCE(assigned_user_id, ?), last_human_reply_at = NOW(),
                     automation_enabled = 0, status = "pending_customer", updated_at = NOW()
                 WHERE id = ?',
                'ii',
                [(int) $sender['id'], $conversationId]
            );
            db_execute(
                'UPDATE support_handoffs SET status = "resolved", resolved_at = NOW()
                 WHERE conversation_id = ? AND status = "open"',
                'i',
                [$conversationId]
            );
            create_notification((int) $conversation['customer_id'], 'New Support Reply', 'A support team member replied to your conversation: ' . support_message_excerpt($message), 'info');
            log_audit((int) $sender['id'], 'support_human_reply_sent', role_label($role) . ' sent support reply #' . $messageId . ' in conversation #' . $conversationId);
        }

        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    return $messageId;
}

function support_mark_messages_read(int $conversationId, array $viewer): void
{
    $conversation = support_require_conversation_access($conversationId, $viewer);

    if (($viewer['role'] ?? '') === 'customer') {
        db_execute(
            'UPDATE support_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type <> "customer"',
            'i',
            [$conversationId]
        );
        return;
    }

    db_execute(
        'UPDATE support_messages SET is_read = 1 WHERE conversation_id = ? AND sender_type = "customer"',
        'i',
        [$conversation['id']]
    );
}

function support_schedule_auto_reply(int $conversationId, int $messageId): void
{
    db_execute(
        'UPDATE support_conversations SET automation_enabled = 1, updated_at = NOW() WHERE id = ?',
        'i',
        [$conversationId]
    );
}

function support_latest_unanswered_customer_message(array $conversation): ?array
{
    if ((int) ($conversation['automation_enabled'] ?? 1) !== 1) {
        return null;
    }

    $message = db_find(
        'SELECT *
         FROM support_messages
         WHERE conversation_id = ? AND sender_type = "customer"
         ORDER BY id DESC LIMIT 1',
        'i',
        [(int) $conversation['id']]
    );

    if (!$message) {
        return null;
    }

    if (strtotime((string) $message['created_at']) > time() - support_auto_reply_delay_seconds()) {
        return null;
    }

    $laterHuman = db_find(
        'SELECT id FROM support_messages
         WHERE conversation_id = ? AND sender_type IN ("administrator", "manager", "staff") AND created_at >= ?
         LIMIT 1',
        'is',
        [(int) $conversation['id'], (string) $message['created_at']]
    );

    if ($laterHuman) {
        return null;
    }

    $existingAuto = db_find(
        'SELECT id FROM support_messages
         WHERE conversation_id = ? AND reply_to_message_id = ? AND is_automated = 1
         LIMIT 1',
        'ii',
        [(int) $conversation['id'], (int) $message['id']]
    );

    return $existingAuto ? null : $message;
}

function support_requires_handoff(string $category, string $normalizedMessage): bool
{
    if (in_array($category, ['refund_request', 'cancellation_request'], true)) {
        return true;
    }

    foreach (['refund', 'cancel', 'complaint', 'angry', 'wrong', 'dispute', 'chargeback', 'compensation', 'discount', 'return money', 'money back'] as $keyword) {
        if (str_contains($normalizedMessage, $keyword)) {
            return true;
        }
    }

    return false;
}

function support_verified_database_reply(array $conversation, string $normalizedMessage): ?string
{
    $orderId = (int) ($conversation['order_id'] ?? 0);
    $productId = (int) ($conversation['product_id'] ?? 0);
    $category = (string) ($conversation['category'] ?? 'other');
    $asksOrder = $category === 'order_status' || str_contains($normalizedMessage, 'order') || str_contains($normalizedMessage, 'status');
    $asksPayment = $category === 'payment_concern' || str_contains($normalizedMessage, 'payment') || str_contains($normalizedMessage, 'paid') || str_contains($normalizedMessage, 'gcash') || str_contains($normalizedMessage, 'transaction');
    $asksDelivery = $category === 'delivery_concern' || str_contains($normalizedMessage, 'delivery') || str_contains($normalizedMessage, 'deliver') || str_contains($normalizedMessage, 'tracking');
    $asksProduct = in_array($category, ['product_inquiry', 'product_availability'], true) || str_contains($normalizedMessage, 'price') || str_contains($normalizedMessage, 'cost') || str_contains($normalizedMessage, 'product') || str_contains($normalizedMessage, 'available') || str_contains($normalizedMessage, 'stock');
    $asksAccount = $category === 'account_concern' || str_contains($normalizedMessage, 'account') || str_contains($normalizedMessage, 'verification') || str_contains($normalizedMessage, 'approved') || str_contains($normalizedMessage, 'login');

    if ($orderId > 0 && $asksOrder && !$asksPayment && !$asksDelivery) {
        $order = fetch_order_by_id($orderId);
        if ($order && (int) $order['customer_id'] === (int) $conversation['customer_id']) {
            return 'Your order ' . $order['reference_no'] . ' is currently marked as ' . ucwords(str_replace('_', ' ', (string) $order['order_status'])) . '.';
        }
    }

    if ($orderId > 0 && $asksPayment) {
        $order = fetch_order_by_id($orderId);
        $transaction = fetch_transaction_by_order($orderId);
        if ($order && (int) $order['customer_id'] === (int) $conversation['customer_id']) {
            $method = ucwords(str_replace('_', ' ', (string) ($transaction['payment_method'] ?? ($order['payment_method'] ?? 'Not recorded'))));
            $status = ucwords(str_replace('_', ' ', (string) ($transaction['transaction_status'] ?? $order['payment_status'])));
            return 'The recorded payment method for order ' . $order['reference_no'] . ' is ' . $method . ', and the payment status is ' . $status . '.';
        }
    }

    if ($orderId > 0 && $asksDelivery) {
        $order = fetch_order_by_id($orderId);
        if ($order && (int) $order['customer_id'] === (int) $conversation['customer_id']) {
            return 'Your delivery for order ' . $order['reference_no'] . ' is currently marked as ' . ucwords(str_replace('_', ' ', (string) $order['delivery_status'])) . '.';
        }
    }

    if ($productId > 0 && $asksProduct && (str_contains($normalizedMessage, 'price') || str_contains($normalizedMessage, 'cost') || $category === 'product_inquiry')) {
        $product = fetch_product_by_id($productId);
        if ($product) {
            return 'The current recorded price of ' . $product['name'] . ' is ' . currency((float) $product['price']) . '.';
        }
    }

    if ($productId > 0 && $asksProduct) {
        $product = fetch_product_by_id($productId);
        if ($product) {
            $availability = ((int) $product['is_available'] === 1 && (string) $product['status'] === 'active') ? 'Available' : 'Unavailable';
            return $product['name'] . ' is currently marked as ' . $availability . ' with recorded stock quantity of ' . product_stock_quantity($product) . '.';
        }
    }

    if ($asksAccount) {
        $customer = fetch_user_by_id((int) $conversation['customer_id']);
        if ($customer) {
            $status = ucwords(str_replace('_', ' ', (string) ($customer['status'] ?? 'unknown')));
            $verification = ucwords(str_replace('_', ' ', (string) ($customer['verification_status'] ?? $customer['status'] ?? 'unknown')));
            return 'Your account status is currently recorded as ' . $status . ', and your verification status is ' . $verification . '.';
        }
    }

    return null;
}

function support_faq_reply(array $conversation, string $normalizedMessage): ?string
{
    $faqs = db_select(
        'SELECT * FROM support_faqs WHERE status = "active" ORDER BY priority DESC, id ASC'
    );
    $best = null;
    $bestScore = 0;
    $category = (string) $conversation['category'];

    foreach ($faqs as $faq) {
        $score = $faq['category'] === $category ? 10 : 0;
        $matchCount = 0;
        $keywords = array_filter(array_map('trim', explode(',', strtolower((string) $faq['keywords']))));

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedMessage, $keyword)) {
                $matchCount++;
                $score += str_contains($keyword, ' ') ? 15 : 7;
            }
        }

        if ($matchCount === 0) {
            continue;
        }

        if ($score > $bestScore) {
            $best = $faq;
            $bestScore = $score;
        }
    }

    if (!$best || $bestScore < 17) {
        return null;
    }

    return (string) $best['answer'];
}

function support_create_handoff(array $conversation, array $triggerMessage, string $reason): void
{
    // The unique trigger key arbitrates simultaneous poll requests. Only the
    // request that inserted a handoff may send alerts or write its audit event.
    $statement = get_db()->prepare(
        'INSERT INTO support_handoffs (conversation_id, trigger_message_id, assigned_user_id, reason, status, created_at)
         VALUES (?, ?, ?, ?, "open", NOW())'
    );
    $conversationId = (int) $conversation['id'];
    $triggerId = (int) $triggerMessage['id'];
    $assignedUserId = !empty($conversation['assigned_user_id']) ? (int) $conversation['assigned_user_id'] : null;
    $statement->bind_param('iiis', $conversationId, $triggerId, $assignedUserId, $reason);
    try {
        $statement->execute();
    } catch (mysqli_sql_exception $exception) {
        if ((int) $exception->getCode() !== 1062) {
            throw $exception;
        }
        return;
    } finally {
        $statement->close();
    }

    db_execute(
        'UPDATE support_conversations SET status = "pending_staff", updated_at = NOW() WHERE id = ?',
        'i',
        [(int) $conversation['id']]
    );
    support_notify_staff('Support Handoff Required', 'A support conversation requires human review: ' . $reason, !empty($conversation['assigned_user_id']) ? (int) $conversation['assigned_user_id'] : null);
    log_audit(null, 'support_handoff_required', 'Support conversation #' . (int) $conversation['id'] . ' requires human review: ' . $reason);
}

function support_run_auto_reply(int $conversationId): ?array
{
    ensure_support_schema();
    $conversation = support_fetch_conversation($conversationId);

    if (!$conversation) {
        return null;
    }

    $trigger = support_latest_unanswered_customer_message($conversation);

    if (!$trigger) {
        return null;
    }

    $normalized = support_normalize_text((string) $trigger['message']);
    $reply = null;
    $needsHandoff = support_requires_handoff((string) $conversation['category'], $normalized);

    if (!$needsHandoff) {
        $reply = support_verified_database_reply($conversation, $normalized) ?? support_faq_reply($conversation, $normalized);
    }

    if ($reply === null || $needsHandoff) {
        $reply = 'Thank you for your message. I could not provide a verified answer to this concern. Your message has been forwarded to our staff for assistance.';
        support_create_handoff($conversation, $trigger, $needsHandoff ? 'Concern requires human review.' : 'No verified automated answer was available.');
    }

    try {
        db_execute(
            'INSERT INTO support_messages (conversation_id, sender_id, sender_type, message, is_automated, is_read, reply_to_message_id, created_at)
             VALUES (?, NULL, "automated", ?, 1, 0, ?, NOW())',
            'isi',
            [$conversationId, $reply, (int) $trigger['id']]
        );
    } catch (Throwable $exception) {
        error_log('Support auto reply skipped: ' . $exception->getMessage());
        return null;
    }

    $messageId = db_last_id();
    db_execute(
        'UPDATE support_conversations SET last_auto_reply_at = NOW(), updated_at = NOW() WHERE id = ?',
        'i',
        [$conversationId]
    );
    create_notification((int) $conversation['customer_id'], 'Support Update', 'An automated support response is available for your conversation.', 'info');
    log_audit(null, 'support_auto_reply_sent', 'Rule-based automated reply #' . $messageId . ' sent for support conversation #' . $conversationId);

    return support_format_message(db_find('SELECT * FROM support_messages WHERE id = ? LIMIT 1', 'i', [$messageId]) ?? []);
}

function support_update_conversation(int $conversationId, array $viewer, array $data): void
{
    if (!support_is_staff($viewer)) {
        throw new InvalidArgumentException('Only support staff can update conversations.');
    }

    $status = (string) ($data['status'] ?? '');
    if (!array_key_exists($status, support_statuses())) {
        throw new InvalidArgumentException('Please select a valid conversation status.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $conversation = db_find('SELECT * FROM support_conversations WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$conversationId]);
        if (!$conversation || !support_can_access_conversation($viewer, $conversation)) {
            throw new InvalidArgumentException('Support conversation was not found or you do not have access.');
        }

        $oldAssigneeId = (int) ($conversation['assigned_user_id'] ?? 0);
        $assignedUserId = array_key_exists('assigned_user_id', $data) ? (int) $data['assigned_user_id'] : $oldAssigneeId;
        if (($viewer['role'] ?? '') === 'staff') {
            $assignedUserId = $oldAssigneeId ?: (int) $viewer['id'];
        } elseif ($assignedUserId > 0) {
            $assignee = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1', 'i', [$assignedUserId]);
            if (!$assignee || !support_is_staff($assignee) || ($assignee['status'] ?? '') !== 'active' || !empty($assignee['deleted_at'])) {
                throw new InvalidArgumentException('Please select an active staff assignee.');
            }
        }

        $oldStatus = (string) $conversation['status'];
        if ($status !== $oldStatus || $assignedUserId !== $oldAssigneeId) {
            $closedAtSql = in_array($status, ['resolved', 'closed'], true)
                ? ', closed_at = IF(closed_at IS NULL, NOW(), closed_at)' : ', closed_at = NULL';
            db_execute(
                'UPDATE support_conversations
                 SET assigned_user_id = ?, status = ?, automation_enabled = IF(? IN ("resolved", "closed"), 0, automation_enabled), updated_at = NOW()' . $closedAtSql . '
                 WHERE id = ?',
                'issi',
                [$assignedUserId > 0 ? $assignedUserId : null, $status, $status, $conversationId]
            );

            if ($assignedUserId > 0 && $assignedUserId !== $oldAssigneeId) {
                create_notification($assignedUserId, 'Support Conversation Assigned', 'A support conversation has been assigned to you.', 'info');
            }
            if ($status !== $oldStatus && in_array($status, ['resolved', 'closed'], true)) {
                create_notification((int) $conversation['customer_id'], 'Support Conversation ' . ucwords($status), 'Your support conversation has been marked as ' . $status . '.', 'success');
            }
            log_audit((int) $viewer['id'], 'support_conversation_updated', 'Updated support conversation #' . $conversationId . ' to ' . $status . ($assignedUserId > 0 ? ' assigned to user #' . $assignedUserId : ''));
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function support_add_internal_note(int $conversationId, array $viewer, string $note): void
{
    if (!support_is_staff($viewer)) {
        throw new InvalidArgumentException('Only staff can add internal notes.');
    }

    $note = support_validate_message($note);
    $database = get_db();
    $database->begin_transaction();
    try {
        $conversation = db_find('SELECT * FROM support_conversations WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$conversationId]);
        if (!$conversation || !support_can_access_conversation($viewer, $conversation)) {
            throw new InvalidArgumentException('Support conversation was not found or you do not have access.');
        }
        $recent = db_find(
            'SELECT id FROM support_internal_notes
             WHERE conversation_id = ? AND user_id = ? AND note = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
             LIMIT 1 FOR UPDATE',
            'iis',
            [$conversationId, (int) $viewer['id'], $note]
        );
        if (!$recent) {
            db_execute(
                'INSERT INTO support_internal_notes (conversation_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())',
                'iis',
                [$conversationId, (int) $viewer['id'], $note]
            );
            log_audit((int) $viewer['id'], 'support_internal_note_added', 'Added internal note to support conversation #' . $conversationId);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function support_fetch_internal_notes(int $conversationId, array $viewer): array
{
    support_require_conversation_access($conversationId, $viewer);

    if (!support_is_staff($viewer)) {
        return [];
    }

    return db_select(
        'SELECT n.*, u.full_name, u.role
         FROM support_internal_notes n
         INNER JOIN users u ON u.id = n.user_id
         WHERE n.conversation_id = ?
         ORDER BY n.created_at DESC',
        'i',
        [$conversationId]
    );
}
