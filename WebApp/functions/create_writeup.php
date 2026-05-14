<?php
// ============================================================================
// CREATE_WRITEUP.PHP - PUBLISH A MARKDOWN WRITEUP FOR THE SIGNED-IN USER
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// Creating a writeup changes server state, so it must be a same-origin POST.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// A small hourly cap prevents accidental spam without blocking normal writing.
enforce_rate_limit('create_writeup', 20, 3600);

$sessionUser = require_authenticated_session();

function writeup_excerpt(string $markdown): string
{
    $withoutCode = preg_replace('/```[\s\S]*?```/', ' ', $markdown);
    $plain = strip_tags((string) $withoutCode);
    $plain = preg_replace('/[#>*_`\[\]\(\)!-]+/', ' ', (string) $plain);
    $plain = trim((string) preg_replace('/\s+/', ' ', (string) $plain));

    if (strlen($plain) <= 260) {
        return $plain;
    }

    return rtrim(substr($plain, 0, 257)) . '...';
}

$title = trim(strip_null_bytes((string) ($_POST['title'] ?? '')));
$content = trim(strip_null_bytes((string) ($_POST['content_md'] ?? '')));

$errors = [];

if (!is_valid_utf8($title) || !is_valid_utf8($content)) {
    $errors[] = 'Writeup contains invalid characters.';
}

if ($title === '') {
    $errors[] = 'Title is required.';
} elseif (strlen($title) < 3 || strlen($title) > 140) {
    $errors[] = 'Title must be between 3 and 140 characters.';
} elseif (preg_match('/[\x00-\x1F\x7F]/', $title)) {
    $errors[] = 'Title cannot contain control characters.';
}

if ($content === '') {
    $errors[] = 'Writeup body is required.';
} elseif (strlen($content) > 20000) {
    $errors[] = 'Writeup body must be 20000 characters or fewer.';
}

if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

$excerpt = writeup_excerpt($content);

$conn = open_db_connection();

$stmt = $conn->prepare("
    INSERT INTO writeups (user_id, title, content_md, excerpt, status, published_at)
    VALUES (?, ?, ?, ?, 'published', CURRENT_TIMESTAMP)
");

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Writeup publishing is temporarily unavailable.'], 500);
}

$userId = (int) $sessionUser['user_id'];
$stmt->bind_param('isss', $userId, $title, $content, $excerpt);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Failed to publish writeup.'], 500);
}

$writeupId = (int) $stmt->insert_id;
$stmt->close();
$conn->close();

send_json([
    'success' => true,
    'message' => 'Writeup published.',
    'writeup' => [
        'id' => $writeupId,
        'user_id' => $userId,
        'title' => $title,
        'content_md' => $content,
        'excerpt' => $excerpt,
        'author' => [
            'username' => (string) $sessionUser['username'],
        ],
    ],
], 201);
?>
