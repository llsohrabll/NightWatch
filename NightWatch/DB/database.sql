-- ============================================================================
-- DATABASE INITIALIZATION FOR NIGHTWATCH APPLICATION
-- ============================================================================

-- Create the main database called 'Registration' that will store all user data
CREATE DATABASE Registration;

-- Create a dedicated database user 'nightwatch_app' with a strong deployment password.
-- Replace CHANGE_ME_STRONG_PASSWORD before running this script.
CREATE USER 'nightwatch_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- User profile photo path: stores the relative path to user's profile photo
    -- NULL if user hasn't uploaded a photo yet
    -- Format: uploads/photos/userid_filename.jpg
    photo_path VARCHAR(255) DEFAULT NULL,
    
    -- Email verification status: tracks whether user has verified their email
    -- 0 = not verified, 1 = verified
    -- New users start with 0 and must verify email during registration
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    
    -- Email verification code: 6-digit random code sent during registration
    -- NULL after email is verified or code expires
    -- Range: 000000-999999
    email_verification_code VARCHAR(6) DEFAULT NULL,
    
    -- Email verification code expiration: when email verification code becomes invalid
    -- Typically expires after 15-30 minutes
    -- NULL if no active verification code
    email_verification_code_expires_at TIMESTAMP NULL,
    
    -- Password reset code: 6-digit random code sent during password recovery
    -- NULL if no active password reset request
    -- Range: 000000-999999
    reset_code VARCHAR(6) DEFAULT NULL,
    
    -- Password reset code expiration: when reset code becomes invalid
    -- Typically expires after 30-60 minutes for security
    -- NULL if no active reset request
    reset_code_expires_at TIMESTAMP NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
