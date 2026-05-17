-- ============================================================================
-- DATABASE INITIALIZATION FOR NIGHT WATCH APPLICATION - COMPLETE SETUP
-- ============================================================================

CREATE DATABASE IF NOT EXISTS NightWatchDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Replace CHANGE_ME_STRONG_PASSWORD before running this script, or create the
-- user yourself and expose the credentials through NIGHTWATCH_DB_* variables.
CREATE USER IF NOT EXISTS 'nightwatch_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON NightWatchDB.* TO 'nightwatch_app'@'localhost';
FLUSH PRIVILEGES;

USE NightWatchDB;

-- ============================================================================
-- USERS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,

    -- Stores a SHA-256 hash of the browser auth token, never the raw token.
    token CHAR(64) DEFAULT NULL,
    token_expires_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    photo_path VARCHAR(255) NOT NULL DEFAULT 'assets/nightwatch_symbol.png',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    pending_email VARCHAR(255) DEFAULT NULL,
    email_change_code CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of 6-digit email-change code',
    email_change_code_expires_at TIMESTAMP NULL,
    login_failures INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verification_code CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of 6-digit verification code',
    email_verification_code_expires_at TIMESTAMP NULL,
    reset_code CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of 6-digit reset code',
    reset_code_expires_at TIMESTAMP NULL,

    INDEX idx_users_token (token, token_expires_at),
    INDEX idx_users_email_verified (email, email_verified),
    INDEX idx_users_pending_email (pending_email),
    INDEX idx_users_locked_until (locked_until),
    INDEX idx_users_admin (is_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- WRITEUPS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS writeups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(140) NOT NULL,
    content_md MEDIUMTEXT NOT NULL,
    excerpt VARCHAR(280) DEFAULT NULL,
    status ENUM('published', 'draft', 'pending', 'rejected') NOT NULL DEFAULT 'published',
    category VARCHAR(40) DEFAULT NULL,
    tags VARCHAR(255) DEFAULT NULL,
    moderated_by INT UNSIGNED DEFAULT NULL,
    moderated_at TIMESTAMP NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_writeups_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_writeups_moderator
        FOREIGN KEY (moderated_by) REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_writeups_feed (status, published_at, id),
    INDEX idx_writeups_user (user_id, created_at),
    INDEX idx_writeups_moderation (status, created_at),
    INDEX idx_writeups_category (category),

    -- The homepage search box uses this full-text index for title/body search.
    FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MIGRATION: TOKEN-COOKIE AUTH & DATABASE-BACKED SEARCH COMPATIBILITY
-- ============================================================================
-- Make existing installations compatible with the real token-cookie auth and
-- database-backed writeup search. Each change is guarded so the migration can
-- be rerun safely.

SET @token_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token'
);
SET @sql := IF(@token_type IS NULL,
    'ALTER TABLE users ADD COLUMN token CHAR(64) DEFAULT NULL',
    'ALTER TABLE users MODIFY token CHAR(64) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


SET @token_expires_col := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_expires_at'
);
SET @sql := IF(@token_expires_col = 0,
    'ALTER TABLE users ADD COLUMN token_expires_at TIMESTAMP NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @email_code_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verification_code'
);
SET @sql := IF(@email_code_type IS NULL OR @email_code_type NOT LIKE 'char(64)%',
    'ALTER TABLE users MODIFY email_verification_code CHAR(64) DEFAULT NULL COMMENT \'SHA-256 hash of 6-digit verification code\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @reset_code_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reset_code'
);
SET @sql := IF(@reset_code_type IS NULL OR @reset_code_type NOT LIKE 'char(64)%',
    'ALTER TABLE users MODIFY reset_code CHAR(64) DEFAULT NULL COMMENT \'SHA-256 hash of 6-digit reset code\'',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_users_token := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_token'
);
SET @sql := IF(@idx_users_token = 0,
    'ALTER TABLE users ADD INDEX idx_users_token (token, token_expires_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_users_email_verified := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_email_verified'
);
SET @sql := IF(@idx_users_email_verified = 0,
    'ALTER TABLE users ADD INDEX idx_users_email_verified (email, email_verified)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_writeups_search := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeups' AND INDEX_NAME = 'idx_writeups_search'
);
SET @sql := IF(@idx_writeups_search = 0,
    'ALTER TABLE writeups ADD FULLTEXT INDEX idx_writeups_search (title, excerpt, content_md)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- MIGRATION: SECURITY/FUNCTIONALITY IMPROVEMENTS
-- ============================================================================

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

-- ============================================================================
-- RATE_LIMITS TABLE - For storing rate limit tracking
-- ============================================================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(50) NOT NULL COMMENT 'Rate limit scope (e.g., login, register, verify_email)',
    ip_address VARCHAR(45) NOT NULL COMMENT 'Client IP address (supports IPv6)',
    attempt_count INT NOT NULL DEFAULT 1 COMMENT 'Number of attempts in this window',
    window_start TIMESTAMP NOT NULL COMMENT 'Start of rate limit window',
    window_end TIMESTAMP NOT NULL COMMENT 'End of rate limit window',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_rate_limits_scope_ip (scope, ip_address, window_end),
    INDEX idx_rate_limits_window_end (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MIGRATION: Add rate_limits table if not exists
-- ============================================================================
SET @rate_limits_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_limits'
);
-- Table already created above, this is just a safety check
