-- Canonicalize legacy notification routes without removing notification history.
-- The shared PHP repair_existing_notification_links() helper performs the
-- target-aware backfill for NULL links and validates each target on disk.

UPDATE notifications
SET link = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    link,
    '/public/public/', '/public/'),
    'admin/admin/', 'admin/'),
    'manager/manager/', 'manager/'),
    'staff/staff/', 'staff/'),
    'rider/rider/', 'rider/'),
    'customer/customer/', 'customer/')
WHERE link IS NOT NULL AND link <> '';

UPDATE notifications
SET link = 'customer-verifications.php'
WHERE link REGEXP '(^|/)customer-verifications\\.php([?].*)?$';

-- Payment verification is a transaction workflow, not customer-account verification.
UPDATE notifications n
LEFT JOIN users u ON u.id = n.user_id
SET n.link = CASE u.role
    WHEN 'administrator' THEN 'admin/transactions.php'
    WHEN 'manager' THEN 'manager/transactions.php'
    WHEN 'staff' THEN 'staff/transactions.php'
    WHEN 'customer' THEN 'customer/my-orders.php'
    ELSE n.link
END
WHERE LOWER(CONCAT(n.title, ' ', n.message)) LIKE '%payment%verification%';
