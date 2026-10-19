CREATE TABLE IF NOT EXISTS support_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    assigned_user_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    category VARCHAR(60) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'open',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_faqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(60) NOT NULL,
    question VARCHAR(255) NOT NULL,
    keywords TEXT NOT NULL,
    answer TEXT NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_support_faq_question (question),
    INDEX idx_support_faqs_category_status (category, status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_handoffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    trigger_message_id INT UNSIGNED NOT NULL,
    assigned_user_id INT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_support_handoffs_conversation (conversation_id, status),
    INDEX idx_support_handoffs_trigger (trigger_message_id),
    INDEX idx_support_handoffs_assigned (assigned_user_id, status),
    CONSTRAINT fk_support_handoffs_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_handoffs_trigger FOREIGN KEY (trigger_message_id) REFERENCES support_messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_handoffs_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_internal_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_internal_notes_conversation (conversation_id, created_at),
    CONSTRAINT fk_support_internal_notes_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_support_internal_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO support_faqs (category, question, keywords, answer, priority, status, created_at, updated_at) VALUES
('general_inquiry', 'Store operating hours', 'hours,open,closing,time,schedule', 'Our store operating hours may vary. Please wait for staff confirmation for today''s exact schedule.', 10, 'active', NOW(), NOW()),
('product_availability', 'Product availability', 'available,availability,stock,in stock,out of stock,price,product', 'I can check current product price and availability when a product is linked to this conversation.', 20, 'active', NOW(), NOW()),
('order_status', 'Order status', 'order status,order,preparing,processing,confirmed,completed,reference', 'I can check the verified status when an order is linked to this conversation.', 30, 'active', NOW(), NOW()),
('payment_concern', 'Payment status', 'payment,paid,gcash,cash,transaction,proof,accepted,pending', 'I can check the recorded payment method and transaction status when an order is linked.', 30, 'active', NOW(), NOW()),
('delivery_concern', 'Delivery status', 'delivery,deliver,tracking,out for delivery,delivered,assigned', 'I can check the recorded delivery status when an order is linked.', 30, 'active', NOW(), NOW()),
('cancellation_request', 'Cancellation request', 'cancel,cancellation,void,stop order', 'Cancellation requests require staff review. Your concern will be forwarded to our staff.', 40, 'active', NOW(), NOW()),
('refund_request', 'Refund request', 'refund,return,money back,reimburse,dispute', 'Refund requests require staff review. Your concern will be forwarded to our staff.', 40, 'active', NOW(), NOW()),
('account_concern', 'Account verification', 'account,verification,approved,login,password', 'Account concerns require staff review unless a verified account status is available.', 15, 'active', NOW(), NOW()),
('general_inquiry', 'Contact information', 'contact,email,phone,call,message', 'You can continue sending your concern here. Staff will reply when human assistance is needed.', 5, 'active', NOW(), NOW());
