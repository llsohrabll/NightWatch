<?php
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

$sessionUser = require_authenticated_session();
$conn = open_db_connection();

$stmt = $conn->prepare('SELECT id, username, email, photo_path FROM users WHERE id = ? AND email_verified = 1 LIMIT 1');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Panel is temporarily unavailable.'], 500);
}

$userId = (int) $sessionUser['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $conn->close();
    destroy_app_session();
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$latestStmt = $conn->prepare(
    "SELECT w.id, w.user_id, w.title, w.excerpt, w.published_at, u.username
     FROM writeups w
     INNER JOIN users u ON u.id = w.user_id
     WHERE w.status = 'published'
     ORDER BY w.published_at DESC, w.id DESC
     LIMIT 3"
);

if (!$latestStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Panel is temporarily unavailable.'], 500);
}

$latestStmt->execute();
$latestResult = $latestStmt->get_result();
$latestWriteups = [];
while ($row = $latestResult->fetch_assoc()) {
    $publishedTimestamp = strtotime((string) $row['published_at']);
    $latestWriteups[] = [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'title' => (string) $row['title'],
        'excerpt' => $row['excerpt'] !== null ? (string) $row['excerpt'] : '',
        'author' => ['username' => (string) $row['username']],
        'published_at' => (string) $row['published_at'],
        'published_at_ms' => $publishedTimestamp !== false ? $publishedTimestamp * 1000 : null,
    ];
}
$latestStmt->close();
$conn->close();

send_json([
    'success' => true,
    'username' => (string) $user['username'],
    'email' => (string) $user['email'],
    'photo_path' => safe_public_photo_path($user['photo_path'] ?? null),
    'expires_at' => iso8601_from_timestamp((int) $sessionUser['expires_at']),
    'expires_at_ms' => (int) $sessionUser['expires_at'] * 1000,
    'latest_writeups' => $latestWriteups,
]);
