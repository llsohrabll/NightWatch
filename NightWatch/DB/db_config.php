<?php
// ============================================================================
// DB_CONFIG.PHP - DATABASE CONNECTION CONSTANTS
// ============================================================================
// This file stores database credentials and configuration
// IMPORTANT: Keep this file secure and don't commit to version control
// Add to .gitignore to prevent accidental credential exposure

// ============================================================================
// Database connection parameters
// ============================================================================

// DB_HOST: Server address where MySQL database is running
// 'localhost' = same machine as PHP server
// For remote server, use IP address or hostname (e.g., '192.168.1.100' or 'db.example.com')
define('DB_HOST', 'localhost');

// DB_USER: Username for database login
// This should be a limited user (not root) with only necessary permissions
define('DB_USER', 'nightwatch_app');

// DB_PASSWORD: Password for database user
// SECURITY: Change from 'your_password' to a strong password in production
// Strong password should contain: uppercase, lowercase, numbers, special characters
define('DB_PASSWORD', 'jsjO@*&#^%76tucvuysovSOUGD68256d6^$@%');

// DB_NAME: Name of the database to connect to
// Must match the database created in database.sql
define('DB_NAME', 'Registration');

// ============================================================================
// SECURITY NOTES:
// ============================================================================
// 1. Never commit this file to public repositories
// 2. Change default password immediately in production
// 3. Use environment variables for credentials in production
// 4. Restrict file permissions (644 or better 600)
// 5. Database user should have minimal required permissions
// ============================================================================
?>