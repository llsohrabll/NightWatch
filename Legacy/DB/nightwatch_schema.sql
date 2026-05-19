-- NightWatch MySQL schema
-- Import with a MySQL root/admin account after replacing CHANGE_ME_STRONG_PASSWORD.
-- Runtime PHP reads NIGHTWATCH_DB_* environment variables from DB/db_config.php.

CREATE DATABASE IF NOT EXISTS NightWatchDB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'nightwatch_app'@'localhost'
  IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE ON NightWatchDB.* TO 'nightwatch_app'@'localhost';
FLUSH PRIVILEGES;

USE NightWatchDB;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
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
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_token (token, token_expires_at),
    KEY idx_users_email_verified (email, email_verified),
    KEY idx_users_pending_email (pending_email),
    KEY idx_users_locked_until (locked_until),
    KEY idx_users_admin (is_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS writeups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    PRIMARY KEY (id),
    CONSTRAINT fk_writeups_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_writeups_moderator
        FOREIGN KEY (moderated_by) REFERENCES users(id)
        ON DELETE SET NULL,
    KEY idx_writeups_feed (status, published_at, id),
    KEY idx_writeups_user (user_id, created_at),
    KEY idx_writeups_moderation (status, created_at),
    KEY idx_writeups_category (category),
    FULLTEXT KEY idx_writeups_search (title, excerpt, content_md)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL,
    window_end TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate_limits_scope_ip (scope, ip_address, window_end),
    KEY idx_rate_limits_window_end (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- After creating and verifying a normal account through the UI, promote it with:
-- UPDATE users SET is_admin = 1 WHERE username = 'your_username';
