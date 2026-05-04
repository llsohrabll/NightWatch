<?php
// ============================================================================
// DB_CONNECT.PHP - OPEN A MYSQL CONNECTION
// ============================================================================
// Include this helper when a script needs the $conn variable.

// Load the shared app helpers and then the database settings.
require_once(dirname(__DIR__) . '/functions/common.php');
require_db_config_file();

// ============================================================================
// Create the connection object. PHP will try to reach the configured server now.
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// ============================================================================
// This file only opens the connection. The calling script decides how to handle errors.
// ============================================================================
// Check $conn->connect_error in calling code:
// if ($conn->connect_error) {
//     send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
// }
?>
