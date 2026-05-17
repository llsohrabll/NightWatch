-- NightWatch migration 002: security and feature columns.
-- Safe to run repeatedly after older NightWatch installs. DB/DB.sql also includes these guards.

SET @users_is_admin := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_admin');
SET @sql := IF(@users_is_admin = 0, 'ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER photo_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_pending_email := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pending_email');
SET @sql := IF(@users_pending_email = 0, 'ALTER TABLE users ADD COLUMN pending_email VARCHAR(255) DEFAULT NULL AFTER is_admin', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_email_change_code := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_code');
SET @sql := IF(@users_email_change_code = 0, 'ALTER TABLE users ADD COLUMN email_change_code CHAR(64) DEFAULT NULL COMMENT \'SHA-256 hash of 6-digit email-change code\' AFTER pending_email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_email_change_expires := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_code_expires_at');
SET @sql := IF(@users_email_change_expires = 0, 'ALTER TABLE users ADD COLUMN email_change_code_expires_at TIMESTAMP NULL AFTER email_change_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_login_failures := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'login_failures');
SET @sql := IF(@users_login_failures = 0, 'ALTER TABLE users ADD COLUMN login_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_code_expires_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_locked_until := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until');
SET @sql := IF(@users_locked_until = 0, 'ALTER TABLE users ADD COLUMN locked_until TIMESTAMP NULL AFTER login_failures', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @users_last_login_at := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at');
SET @sql := IF(@users_last_login_at = 0, 'ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL AFTER locked_until', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE users SET photo_path = 'assets/nightwatch_symbol.png' WHERE photo_path IS NULL OR photo_path = '';

SET @photo_type := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo_path');
SET @sql := IF(@photo_type IS NOT NULL, 'ALTER TABLE users MODIFY photo_path VARCHAR(255) NOT NULL DEFAULT \'assets/nightwatch_symbol.png\'', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_users_pending_email := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_pending_email');
SET @sql := IF(@idx_users_pending_email = 0, 'ALTER TABLE users ADD INDEX idx_users_pending_email (pending_email)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_users_locked_until := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_locked_until');
SET @sql := IF(@idx_users_locked_until = 0, 'ALTER TABLE users ADD INDEX idx_users_locked_until (locked_until)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_users_admin := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_admin');
SET @sql := IF(@idx_users_admin = 0, 'ALTER TABLE users ADD INDEX idx_users_admin (is_admin)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeup_status_type := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'status');
SET @sql := IF(@writeup_status_type NOT LIKE '%pending%' OR @writeup_status_type NOT LIKE '%rejected%', 'ALTER TABLE writeups MODIFY status ENUM(\'published\',\'draft\',\'pending\',\'rejected\') NOT NULL DEFAULT \'published\'', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_category := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'category');
SET @sql := IF(@writeups_category = 0, 'ALTER TABLE writeups ADD COLUMN category VARCHAR(40) DEFAULT NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_tags := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'tags');
SET @sql := IF(@writeups_tags = 0, 'ALTER TABLE writeups ADD COLUMN tags VARCHAR(255) DEFAULT NULL AFTER category', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_moderated_by := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'moderated_by');
SET @sql := IF(@writeups_moderated_by = 0, 'ALTER TABLE writeups ADD COLUMN moderated_by INT UNSIGNED DEFAULT NULL AFTER tags', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_moderated_at := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'moderated_at');
SET @sql := IF(@writeups_moderated_at = 0, 'ALTER TABLE writeups ADD COLUMN moderated_at TIMESTAMP NULL AFTER moderated_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_rejection_reason := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'rejection_reason');
SET @sql := IF(@writeups_rejection_reason = 0, 'ALTER TABLE writeups ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL AFTER moderated_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @writeups_published_nullable := (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND COLUMN_NAME = 'published_at');
SET @sql := IF(@writeups_published_nullable = 'NO', 'ALTER TABLE writeups MODIFY published_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_writeups_moderation := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND INDEX_NAME = 'idx_writeups_moderation');
SET @sql := IF(@idx_writeups_moderation = 0, 'ALTER TABLE writeups ADD INDEX idx_writeups_moderation (status, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_writeups_category := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND INDEX_NAME = 'idx_writeups_category');
SET @sql := IF(@idx_writeups_category = 0, 'ALTER TABLE writeups ADD INDEX idx_writeups_category (category)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_writeups_moderator := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_writeups_moderator');
SET @sql := IF(@fk_writeups_moderator = 0, 'ALTER TABLE writeups ADD CONSTRAINT fk_writeups_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL,
    window_end TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_limits_scope_ip (scope, ip_address, window_end),
    INDEX idx_rate_limits_window_end (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
