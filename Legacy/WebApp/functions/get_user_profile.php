<?php
// ============================================================================
// GET_USER_PROFILE.PHP - PUBLIC USER PROFILE AND RECENT PUBLISHED WRITEUPS
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();
set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$username = trim(strip_null_bytes((string) ($_GET['username'] ?? '')));
if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
    send_json(['success' => false, 'error' => 'Invalid username.'], 422);
}

$conn = open_db_connection();
$stmt = $conn->prepare('SELECT id, username, photo_path, created_at FROM users WHERE username = ? AND email_verified = 1 LIMIT 1');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Profile is temporarily unavailable.'], 500);
}
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Profile not found.'], 404);
}

$userId = (int) $user['id'];
$writeupStmt = $conn->prepare('SELECT id, title, excerpt, category, tags, published_at FROM writeups WHERE user_id = ? AND status = \'published\' ORDER BY published_at DESC, id DESC LIMIT 10');
if (!$writeupStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Profile is temporarily unavailable.'], 500);
}
$writeupStmt->bind_param('i', $userId);
$writeupStmt->execute();
$result = $writeupStmt->get_result();
$writeups = [];
while ($row = $result->fetch_assoc()) {
    $tagString = (string) ($row['tags'] ?? '');
    $publishedTimestamp = strtotime((string) $row['published_at']);
    $writeups[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'excerpt' => (string) ($row['excerpt'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'tags' => $tagString !== '' ? explode(',', $tagString) : [],
        'published_at' => (string) $row['published_at'],
        'published_at_ms' => $publishedTimestamp !== false ? $publishedTimestamp * 1000 : null,
    ];
}
$writeupStmt->close();
$conn->close();

$createdTimestamp = strtotime((string) $user['created_at']);
send_json([
    'success' => true,
    'profile' => [
        'id' => $userId,
        'username' => (string) $user['username'],
        'photo_path' => safe_public_photo_path($user['photo_path'] ?? null),
        'created_at' => (string) $user['created_at'],
        'created_at_ms' => $createdTimestamp !== false ? $createdTimestamp * 1000 : null,
    ],
    'writeups' => $writeups,
]);
