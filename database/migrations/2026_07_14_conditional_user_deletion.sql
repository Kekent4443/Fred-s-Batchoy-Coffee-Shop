ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER status;
ALTER TABLE users ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at;
ALTER TABLE users ADD COLUMN deleted_previous_status VARCHAR(30) NULL AFTER deleted_by;
CREATE INDEX idx_users_deleted_at ON users (deleted_at, status);
ALTER TABLE users
    ADD CONSTRAINT fk_users_deleted_by
    FOREIGN KEY (deleted_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;
