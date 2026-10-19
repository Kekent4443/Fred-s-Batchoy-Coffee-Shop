-- Single-Administrator policy for existing installations.
-- Compatible with MySQL 5.7+, MySQL 8.x, and MariaDB versions supporting SIGNAL.
-- Import once through phpMyAdmin after taking a database backup.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS assert_single_administrator$$
CREATE PROCEDURE assert_single_administrator()
BEGIN
    DECLARE administrator_count INT DEFAULT 0;

    SELECT COUNT(*) INTO administrator_count
    FROM users
    WHERE role = 'administrator';

    IF administrator_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration requires exactly one existing Administrator account.';
    END IF;
END$$

CALL assert_single_administrator()$$
DROP PROCEDURE assert_single_administrator$$

DROP TRIGGER IF EXISTS users_single_administrator_before_insert$$
CREATE TRIGGER users_single_administrator_before_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.role = 'administrator'
       AND EXISTS (SELECT 1 FROM users WHERE role = 'administrator') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one Administrator account is allowed.';
    END IF;
END$$

DROP TRIGGER IF EXISTS users_single_administrator_before_update$$
CREATE TRIGGER users_single_administrator_before_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.role <> 'administrator' AND NEW.role = 'administrator' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Existing accounts cannot be promoted to Administrator.';
    END IF;

    IF OLD.role = 'administrator'
       AND (NEW.role <> 'administrator' OR NEW.status <> 'active' OR NEW.deleted_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The primary Administrator cannot be demoted, deactivated, or deleted.';
    END IF;
END$$

DROP TRIGGER IF EXISTS users_single_administrator_before_delete$$
CREATE TRIGGER users_single_administrator_before_delete
BEFORE DELETE ON users
FOR EACH ROW
BEGIN
    IF OLD.role = 'administrator' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The primary Administrator cannot be deleted.';
    END IF;
END$$

DELIMITER ;
