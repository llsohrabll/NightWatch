<?php
// ============================================================================
// GET_WRITEUPS.PHP - PUBLIC PUBLISHED WRITEUP FEED WITH SEARCH + PAGINATION
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();
set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

function clean_search_query(string $query): string
{
    $query = trim(strip_null_bytes($query));
    $query = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query) ?? '';
    $query = preg_replace('/\s+/u', ' ', $query) ?? '';
    return trim(function_exists('mb_substr') ? mb_substr($query, 0, 80, 'UTF-8') : substr($query, 0, 80));
}

function build_like_query(string $query): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
}

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
    'options' => ['default' => 10, 'min_range' => 1, 'max_range' => 30],
]);
if (!is_int($limit)) $limit = 10;

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
    'options' => ['default' => 1, 'min_range' => 1, 'max_range' => 1000],
]);
if (!is_int($page)) $page = 1;
$offset = ($page - 1) * $limit;

$search = clean_search_query((string) ($_GET['q'] ?? ''));
if ($search !== '' && !is_valid_utf8($search)) {
    send_json(['success' => false, 'error' => 'Search contains invalid characters.'], 422);
}

$conn = open_db_connection();

$whereSql = "\n    FROM writeups w\n    INNER JOIN users u ON u.id = w.user_id\n    WHERE w.status = 'published'";

if ($search !== '') {
    $whereSql .= "\n        AND (\n            MATCH(w.title, w.excerpt, w.content_md) AGAINST (? IN NATURAL LANGUAGE MODE)\n            OR w.title LIKE ? ESCAPE '\\\\'\n            OR w.excerpt LIKE ? ESCAPE '\\\\'\n            OR w.category LIKE ? ESCAPE '\\\\'\n            OR w.tags LIKE ? ESCAPE '\\\\'\n            OR u.username LIKE ? ESCAPE '\\\\'\n        )";
}

$countSql = 'SELECT COUNT(*) AS total ' . $whereSql;
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Writeup count is not available.'], 500);
}
if ($search !== '') {
    $like = build_like_query($search);
    $countStmt->bind_param('ssssss', $search, $like, $like, $like, $like, $like);
}
$countStmt->execute();
$totalRow = $countStmt->get_result()->fetch_assoc();
$total = (int) ($totalRow['total'] ?? 0);
$countStmt->close();

$selectSql = "\n    SELECT\n        w.id, w.user_id, w.title, w.excerpt, w.content_md, w.category, w.tags,\n        w.created_at, w.updated_at, w.published_at, u.username, u.photo_path";

if ($search !== '') {
    $sql = $selectSql . ",\n        MATCH(w.title, w.excerpt, w.content_md) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance\n        " . $whereSql . "\n        ORDER BY relevance DESC, w.published_at DESC, w.id DESC\n        LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        send_json(['success' => false, 'error' => 'Writeup search is not available.'], 500);
    }
    $like = build_like_query($search);
    $stmt->bind_param('sssssssii', $search, $search, $like, $like, $like, $like, $like, $limit, $offset);
} else {
    $sql = $selectSql . $whereSql . "\n        ORDER BY w.published_at DESC, w.id DESC\n        LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        send_json(['success' => false, 'error' => 'Writeups are not available.'], 500);
    }
    $stmt->bind_param('ii', $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$writeups = [];

while ($row = $result->fetch_assoc()) {
    $publishedTimestamp = strtotime((string) $row['published_at']);
    $createdTimestamp = strtotime((string) $row['created_at']);
    $updatedTimestamp = strtotime((string) $row['updated_at']);
    $tagString = (string) ($row['tags'] ?? '');

    $writeups[] = [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'title' => (string) $row['title'],
        'excerpt' => $row['excerpt'] !== null ? (string) $row['excerpt'] : '',
        'content_md' => (string) $row['content_md'],
        'category' => (string) ($row['category'] ?? ''),
        'tags' => $tagString !== '' ? explode(',', $tagString) : [],
        'author' => [
            'username' => (string) $row['username'],
            'photo_path' => safe_public_photo_path($row['photo_path'] ?? null),
        ],
        'created_at' => (string) $row['created_at'],
        'created_at_ms' => $createdTimestamp !== false ? $createdTimestamp * 1000 : null,
        'updated_at' => (string) $row['updated_at'],
        'updated_at_ms' => $updatedTimestamp !== false ? $updatedTimestamp * 1000 : null,
        'published_at' => (string) $row['published_at'],
        'published_at_ms' => $publishedTimestamp !== false ? $publishedTimestamp * 1000 : null,
    ];
}

$stmt->close();
$conn->close();

send_json([
    'success' => true,
    'writeups' => $writeups,
    'total' => $total,
    'query' => $search,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => (int) ceil($total / max(1, $limit)),
        'has_next' => $offset + count($writeups) < $total,
        'has_previous' => $page > 1,
    ],
]);
