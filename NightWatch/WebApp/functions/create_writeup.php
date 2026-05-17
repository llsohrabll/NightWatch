<?php
// ============================================================================
// CREATE_WRITEUP.PHP - CREATE A DRAFT OR SUBMIT/PUBLISH A MARKDOWN WRITEUP
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/app_logging.php');
require_db_config_file();

set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();
enforce_csrf_token();
enforce_rate_limit('create_writeup', 20, 3600);

$sessionUser = require_authenticated_session();

function writeup_excerpt(string $markdown): string
{
    $withoutCode = preg_replace('/```[\s\S]*?```/', ' ', $markdown) ?? $markdown;
    $withoutHtml = strip_tags($withoutCode);
    $plain = preg_replace('/[#>*_`\[\]\(\)!-]+/', ' ', $withoutHtml) ?? '';
    $plain = trim((string) preg_replace('/\s+/', ' ', $plain));

    if (strlen($plain) <= 260) return $plain;
    return rtrim(substr($plain, 0, 257)) . '...';
}

$title = trim(strip_null_bytes((string) ($_POST['title'] ?? '')));
$content = sanitize_markdown_input((string) ($_POST['content_md'] ?? ''));
$requestedStatus = strtolower(trim(strip_null_bytes((string) ($_POST['status'] ?? 'published'))));
$category = normalize_writeup_category((string) ($_POST['category'] ?? ''));
$tags = parse_writeup_tags((string) ($_POST['tags'] ?? ''));
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
} elseif (preg_match('/<(script|iframe|object|embed|form|input|button|style|link|meta)\b/i', $content)) {
    $errors[] = 'Raw active HTML is not allowed in writeups.';
}

if (!in_array($requestedStatus, ['draft', 'published'], true)) {
    $errors[] = 'Invalid writeup status.';
}

if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

$moderationRequired = in_array(strtolower((string) (getenv('NIGHTWATCH_REQUIRE_MODERATION') ?: '0')), ['1', 'true', 'yes', 'on'], true);
$isAdmin = current_user_is_admin($sessionUser);
$status = $requestedStatus === 'draft'
    ? 'draft'
    : (($moderationRequired && !$isAdmin) ? 'pending' : 'published');
$publishedAtSql = $status === 'published' ? 'CURRENT_TIMESTAMP' : 'NULL';
$excerpt = writeup_excerpt($content);
$conn = open_db_connection();

$stmt = $conn->prepare("\n    INSERT INTO writeups (user_id, title, content_md, excerpt, status, category, tags, published_at)\n    VALUES (?, ?, ?, ?, ?, ?, ?, {$publishedAtSql})\n");
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Writeup publishing is temporarily unavailable.'], 500);
}

$userId = (int) $sessionUser['user_id'];
$stmt->bind_param('issssss', $userId, $title, $content, $excerpt, $status, $category, $tags);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Failed to save writeup.'], 500);
}

$writeupId = (int) $stmt->insert_id;
$stmt->close();
$conn->close();
log_security_event('WRITEUP_CREATED | Status: ' . $status . ' | Writeup: ' . $writeupId, 'INFO', (string) $userId);

send_json([
    'success' => true,
    'message' => $status === 'published'
        ? 'Writeup published.'
        : ($status === 'pending' ? 'Writeup submitted for moderation.' : 'Draft saved.'),
    'writeup' => [
        'id' => $writeupId,
        'user_id' => $userId,
        'title' => $title,
        'content_md' => $content,
        'excerpt' => $excerpt,
        'status' => $status,
        'category' => $category,
        'tags' => $tags !== '' ? explode(',', $tags) : [],
        'author' => ['username' => (string) $sessionUser['username']],
    ],
], 201);
