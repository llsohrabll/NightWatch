-- ============================================================================
-- DATABASE INITIALIZATION FOR NIGHTWATCH APPLICATION
-- ============================================================================

-- Create the main database called 'Registration' that will store all user data
CREATE DATABASE Registration;

-- Create a dedicated database user 'nightwatch_app' with password for security
-- This user has limited permissions (only on Registration database)
CREATE USER 'nightwatch_app'@'localhost' IDENTIFIED BY 'jsjO@*&#^%76tucvuysovSOUGD68256d6^$@%';

-- Grant all privileges on Registration database to the nightwatch_app user
-- This allows the app to read, write, update, and delete data
GRANT ALL PRIVILEGES ON Registration.* TO 'nightwatch_app'@'localhost';

-- Flush privileges to apply the changes immediately
FLUSH PRIVILEGES;

-- Switch to the Registration database for all subsequent operations
USE Registration;

-- ============================================================================
-- USERS TABLE: Stores all registered user accounts
-- ============================================================================
CREATE TABLE users (
    -- Unique identifier for each user, auto-increments with each new user
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    
    -- Username field: must be unique (no two users can have same username)
    -- Max 50 characters, cannot be empty
    username VARCHAR(50) NOT NULL UNIQUE,
    
    -- Email field: must be unique and valid for password recovery
    -- Max 255 characters, cannot be empty
    email VARCHAR(255) NOT NULL UNIQUE,
    
    -- Password field: stores hashed password using Argon2ID algorithm (not plaintext)
    -- Max 255 characters to accommodate hash output
    password VARCHAR(255) NOT NULL,
    
    -- Token field: stores session token for user authentication
    -- Used to verify user identity without checking password repeatedly
    -- Can be NULL if no active session
    token VARCHAR(255) DEFAULT NULL,
    
    -- Token expiration time: determines when the session token becomes invalid
    -- Stored as TIMESTAMP for easy comparison with current time
    -- NULL if no active session
    token_expires_at TIMESTAMP NULL,
    
    -- Created timestamp: automatically records when user account was created
    -- Defaults to current server time
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;