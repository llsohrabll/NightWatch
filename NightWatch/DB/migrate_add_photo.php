<?php
// ============================================================================
// MIGRATION: ADD THE photo_path COLUMN TO AN OLDER users TABLE
// ============================================================================
// Use this only when an old database exists and does not already have photo_path.

declare(strict_types=1);

// Load database configuration
require_once(__DIR__ . '/db_config.php');

// Connect to database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ============================================================================
// First check whether the column is already there.
// ============================================================================
$checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'photo_path'");

if ($checkColumn && $checkColumn->num_rows > 0) {
    echo "✓ Column 'photo_path' already exists in users table.<br>";
    $conn->close();
    exit;
}

// ============================================================================
// If not, alter the table once.
// ============================================================================
$alterQuery = "ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL COMMENT 'User profile photo path'";

if ($conn->query($alterQuery)) {
    echo "✓ Successfully added 'photo_path' column to users table.<br>";
    echo "✓ Migration complete!<br>";
} else {
    die("✗ Error adding column: " . $conn->error);
}

$conn->close();
?>
