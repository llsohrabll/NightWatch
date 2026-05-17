<?php
// ============================================================================
// ADMIN_WRITEUPS.PHP - LIST WRITEUPS WAITING FOR MODERATION
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
require_admin_session();

$status = strtolower(trim(strip_null_bytes((string) ($_GET['status'] ?? 'pending'))));
if (!in_array($status, ['pending', 'published', 'rejected', 'draft'], true)) {
    send_json(['success' => false, 'error' => 'Invalid status filter.'], 422);
}

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
    'options' => ['default' => 25, 'min_range' => 1, 'max_range' => 100],
]);
if (!is_int($limit)) $limit = 25;

$conn = open_db_connection();
$stmt = $conn->prepare('SELECT w.id, w.user_id, w.title, w.excerpt, w.status, w.category, w.tags, w.created_at, u.username FROM writeups w INNER JOIN users u ON u.id = w.user_id WHERE w.status = ? ORDER BY w.created_at DESC, w.id DESC LIMIT ?');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Moderation queue is temporarily unavailable.'], 500);
}
$stmt->bind_param('si', $status, $limit);
$stmt->execute();
$result = $stmt->get_result();
$writeups = [];
while ($row = $result->fetch_assoc()) {
    $tagString = (string) ($row['tags'] ?? '');
    $writeups[] = [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'title' => (string) $row['title'],
        'excerpt' => (string) ($row['excerpt'] ?? ''),
        'status' => (string) $row['status'],
        'category' => (string) ($row['category'] ?? ''),
        'tags' => $tagString !== '' ? explode(',', $tagString) : [],
        'created_at' => (string) $row['created_at'],
        'author' => ['username' => (string) $row['username']],
    ];
}
$stmt->close();
$conn->close();

send_json(['success' => true, 'writeups' => $writeups, 'total' => count($writeups)]);
