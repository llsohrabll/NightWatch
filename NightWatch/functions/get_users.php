<?php
// ============================================================================
// GET_USERS.PHP - RETURN THE DIRECTORY OF REGISTERED USERS
// ============================================================================
// The public-directory modal in the panel uses this endpoint.

declare(strict_types=1);

// Load common utilities and database config
require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// Only signed-in users can view the directory.
$sessionUser = require_authenticated_session();

// ============================================================================
// Connect to database
// ============================================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

// ============================================================================
// Return the newest accounts first.
$query = "SELECT id, username, photo_path FROM users ORDER BY created_at DESC";
$result = $conn->query($query);

if (!$result) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Database query failed.'], 500);
}

// ============================================================================
// Turn each database row into a clean JSON object.
// ============================================================================
$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        // null is easier for the frontend to test than an empty string.
        'photo_path' => !empty($row['photo_path']) ? (string) $row['photo_path'] : null
    ];
}

$result->free();
$conn->close();

// ============================================================================
// The total count is included so the frontend does not have to count again.
// ============================================================================
send_json([
    'success' => true,
    'users' => $users,
    'total' => count($users)
]);
?>
