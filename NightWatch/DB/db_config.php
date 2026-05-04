<?php
// ============================================================================
// DB_CONFIG.PHP - DATABASE SETTINGS
// ============================================================================
// PHP reads this file to learn how to connect to MySQL.
// In production, environment variables are safer than hard-coded credentials.

// ============================================================================
// Database connection parameters
// ============================================================================

// Helper: use an environment variable when it exists, otherwise use the local default.
function nightwatch_env(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

// Database server host name. localhost means "this same machine".
define('DB_HOST', nightwatch_env('NIGHTWATCH_DB_HOST', 'localhost'));

// Use a dedicated database user with only the permissions this app needs.
define('DB_USER', nightwatch_env('NIGHTWATCH_DB_USER', 'nightwatch_app'));

// Replace the local default with a real secret before deploying.
define('DB_PASSWORD', nightwatch_env('NIGHTWATCH_DB_PASSWORD', 'jsjO@*&#^%76tucvuysovSOUGD68256d6^$@%'));

// Must match the database name created by DB/database.sql.
define('DB_NAME', nightwatch_env('NIGHTWATCH_DB_NAME', 'Registration'));

// ============================================================================
// SECURITY NOTES:
// ============================================================================
// 1. Keep this file out of public version control.
// 2. Prefer environment variables in production.
// 3. Lock down file permissions on the server.
// 4. Do not use the MySQL root user for the app.
// ============================================================================
?>
