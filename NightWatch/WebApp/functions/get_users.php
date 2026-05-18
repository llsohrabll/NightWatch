<?php
// ============================================================================
// GET_USERS.PHP - AUTHENTICATED PUBLIC USER DIRECTORY
// ============================================================================
// Returns public profile data for signed-in users. Email addresses are not exposed.

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

// Set security headers for this JSON endpoint.
set_security_headers();

// Require HTTPS outside localhost/development.
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

require_authenticated_session();
$conn = open_db_connection();

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
    'options' => ['default' => 50, 'min_range' => 1, 'max_range' => 100],
]);
if (!is_int($limit)) $limit = 50;

$stmt = $conn->prepare('
    SELECT id, username, photo_path, created_at
    FROM users
    WHERE email_verified = 1
    ORDER BY created_at DESC, id DESC
    LIMIT ?
');

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'User directory is temporarily unavailable.'], 500);
}

$stmt->bind_param('i', $limit);
$stmt->execute();
$result = $stmt->get_result();
$users = [];

while ($row = $result->fetch_assoc()) {
    $createdTimestamp = strtotime((string) $row['created_at']);
    $users[] = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'photo_path' => safe_public_photo_path($row['photo_path'] ?? null),
        'created_at' => (string) $row['created_at'],
        'created_at_ms' => $createdTimestamp !== false ? $createdTimestamp * 1000 : null,
    ];
}

$stmt->close();
$conn->close();

send_json([
    'success' => true,
    'users' => $users,
    'total' => count($users),
]);
