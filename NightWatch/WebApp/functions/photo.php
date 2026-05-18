<?php
// ============================================================================
// PHOTO.PHP - CONTROLLED PROFILE PHOTO DELIVERY OUTSIDE THE DOCUMENT ROOT
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$filename = (string) ($_GET['file'] ?? '');
if (preg_match('#\A[A-Za-z0-9._-]+\.(?:jpe?g|png)\z#i', $filename) !== 1) {
    http_response_code(404);
    exit;
}

$storageDir = profile_photo_storage_directory();
$filePath = is_dir($storageDir) ? secure_join_path($storageDir, $filename) : null;

if ($filePath === null || !is_file($filePath)) {
    $legacyDir = realpath(__DIR__ . '/../uploads/photos');
    $filePath = $legacyDir !== false ? secure_join_path($legacyDir, $filename) : null;
}

if ($filePath === null || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

$imageInfo = @getimagesize($filePath);
$mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

$etag = '"' . hash_file('sha256', $filePath) . '"';
if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: public, max-age=604800, immutable');
header('ETag: ' . $etag);
header('Content-Disposition: inline; filename="profile-photo.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
readfile($filePath);
