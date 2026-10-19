-- Notification state, ownership, and lookup improvements.
-- Run once on existing databases. Fresh installs receive the same definition from schema.sql.

ALTER TABLE notifications ADD COLUMN role VARCHAR(50) NULL AFTER user_id;
ALTER TABLE notifications ADD COLUMN link VARCHAR(500) NULL AFTER message;
ALTER TABLE notifications ADD COLUMN event_key VARCHAR(191) NULL AFTER link;
ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read;

UPDATE notifications n
LEFT JOIN users u ON u.id = n.user_id
SET n.role = u.role,
    n.is_read = CASE WHEN n.status = 'read' THEN 1 ELSE 0 END,
    n.read_at = CASE WHEN n.status = 'read' THEN COALESCE(n.read_at, n.updated_at) ELSE NULL END
WHERE n.role IS NULL OR n.is_read <> CASE WHEN n.status = 'read' THEN 1 ELSE 0 END OR (n.status = 'read' AND n.read_at IS NULL);

CREATE INDEX idx_notifications_user_role_read_created ON notifications (user_id, role, is_read, created_at);
CREATE INDEX idx_notifications_created_at ON notifications (created_at);
CREATE UNIQUE INDEX uniq_notifications_event_recipient ON notifications (user_id, event_key);
