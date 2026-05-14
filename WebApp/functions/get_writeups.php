<?php
declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

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

$search = clean_search_query((string) ($_GET['q'] ?? ''));
if ($search !== '' && !is_valid_utf8($search)) {
    send_json(['success' => false, 'error' => 'Search contains invalid characters.'], 422);
}

$conn = open_db_connection();

$baseSelect = "
    SELECT
        w.id,
        w.user_id,
        w.title,
        w.content_md,
        w.excerpt,
        w.created_at,
        w.updated_at,
        w.published_at,
        u.username,
        u.photo_path";

$baseFrom = "
    FROM writeups w
    INNER JOIN users u ON u.id = w.user_id
    WHERE w.status = 'published'";

if ($search !== '') {
    $sql = $baseSelect . ",
        MATCH(w.title, w.excerpt, w.content_md) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance
        " . $baseFrom . "
        AND (
            MATCH(w.title, w.excerpt, w.content_md) AGAINST (? IN NATURAL LANGUAGE MODE)
            OR w.title LIKE ? ESCAPE '\\\\'
            OR w.excerpt LIKE ? ESCAPE '\\\\'
            OR u.username LIKE ? ESCAPE '\\\\'
        )
        ORDER BY relevance DESC, w.published_at DESC, w.id DESC
        LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        send_json(['success' => false, 'error' => 'Writeup search is not available.'], 500);
    }

    $like = build_like_query($search);
    $stmt->bind_param('sssssi', $search, $search, $like, $like, $like, $limit);
} else {
    $sql = $baseSelect . "
        " . $baseFrom . "
        ORDER BY w.published_at DESC, w.id DESC
        LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        send_json(['success' => false, 'error' => 'Writeups are not available.'], 500);
    }
    $stmt->bind_param('i', $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$writeups = [];

while ($row = $result->fetch_assoc()) {
    $publishedTimestamp = strtotime((string) $row['published_at']);
    $createdTimestamp = strtotime((string) $row['created_at']);
    $updatedTimestamp = strtotime((string) $row['updated_at']);

    $writeups[] = [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'title' => (string) $row['title'],
        'content_md' => (string) $row['content_md'],
        'excerpt' => $row['excerpt'] !== null ? (string) $row['excerpt'] : '',
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
    'total' => count($writeups),
    'query' => $search,
]);
