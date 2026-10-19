-- Import into the existing application database with phpMyAdmin before deploying
-- authentication-history code. This adds audit tables only; it does not erase data.
CREATE TABLE IF NOT EXISTS login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    login_source VARCHAR(20) NOT NULL DEFAULT 'password',
    new_ip TINYINT(1) NOT NULL DEFAULT 0,
    new_browser TINYINT(1) NOT NULL DEFAULT 0,
    login_at DATETIME(6) NOT NULL,
    logout_at DATETIME(6) NULL,
    last_activity_at DATETIME(6) NOT NULL,
    end_reason VARCHAR(20) NULL,
    INDEX idx_login_history_login (login_at, id),
    INDEX idx_login_history_logout (logout_at, id),
    INDEX idx_login_history_role_login (role, login_at),
    INDEX idx_login_history_user_login (user_id, login_at),
    INDEX idx_login_history_activity (logout_at, last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS failed_logins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    email_tried VARCHAR(150) NOT NULL,
    role_tried VARCHAR(30) NULL,
    failure_reason VARCHAR(40) NOT NULL,
    lock_triggered TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NOT NULL,
    attempted_at DATETIME(6) NOT NULL,
    INDEX idx_failed_logins_attempted (attempted_at, id),
    INDEX idx_failed_logins_role_attempted (role_tried, attempted_at),
    INDEX idx_failed_logins_email_attempted (email_tried, attempted_at),
    INDEX idx_failed_logins_user_attempted (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
