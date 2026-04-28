<?php
// ============================================================================
// DB_CONNECT.PHP - CREATE DATABASE CONNECTION
// ============================================================================
// This file initializes database connection using credentials from db_config.php
// Include this file in any script that needs database access

// Load database configuration constants (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME)
require_once(dirname(__DIR__) . '/functions/common.php');
require_db_config_file();

// ============================================================================
// Create database connection using MySQLi (MySQL Improved)
// ============================================================================
// MySQLi is more modern and secure than older mysql extension
// Parameters: hostname, username, password, database name
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// ============================================================================
// Note: Error handling should be done in calling script
// ============================================================================
// Check $conn->connect_error in calling code:
// if ($conn->connect_error) {
//     send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
// }
?>
