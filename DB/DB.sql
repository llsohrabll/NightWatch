-- ============================================================================
-- DATABASE INITIALIZATION FOR NIGHT WATCH APPLICATION - COMPLETE SETUP
-- ============================================================================

CREATE DATABASE IF NOT EXISTS NightWatchDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Replace CHANGE_ME_STRONG_PASSWORD before running this script, or create the
-- user yourself and expose the credentials through NIGHTWATCH_DB_* variables.
CREATE USER IF NOT EXISTS 'nightwatch_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON NightWatchDB.* TO 'nightwatch_app'@'localhost';
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
    photo_path VARCHAR(255) DEFAULT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verification_code VARCHAR(6) DEFAULT NULL,
    email_verification_code_expires_at TIMESTAMP NULL,
    reset_code VARCHAR(6) DEFAULT NULL,
    reset_code_expires_at TIMESTAMP NULL,

    INDEX idx_users_token (token, token_expires_at),
    INDEX idx_users_email_verified (email, email_verified)
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
    status ENUM('published', 'draft') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_writeups_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_writeups_feed (status, published_at, id),
    INDEX idx_writeups_user (user_id, created_at),

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
