<?php
// ============================================================================
// UPLOAD_PHOTO.PHP - VALIDATE, RE-ENCODE, AND STORE A USER PROFILE PHOTO
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
enforce_rate_limit('upload_photo', 10, 3600);

$sessionUser = require_authenticated_session();

if (!extension_loaded('gd')) {
    send_json(['success' => false, 'error' => 'Image processing is unavailable. Enable the PHP GD extension.'], 500);
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload temporary directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write file to disk.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension.',
    ];
    $errorCode = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    send_json(['success' => false, 'error' => $errorMessages[$errorCode] ?? 'Unknown upload error.'], 400);
}

$uploadedFile = $_FILES['photo'];
$temporaryPath = (string) ($uploadedFile['tmp_name'] ?? '');

if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
    send_json(['success' => false, 'error' => 'Invalid upload source.'], 400);
}

$maxFileSize = 500 * 1024;
$fileSize = (int) ($uploadedFile['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxFileSize) {
    send_json(['success' => false, 'error' => 'File size must be greater than 0 and no more than 500KB.'], 413);
}

$extension = strtolower(pathinfo((string) ($uploadedFile['name'] ?? ''), PATHINFO_EXTENSION));
$extensionMimeMap = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];
if (!isset($extensionMimeMap[$extension])) {
    send_json(['success' => false, 'error' => 'Invalid file type. Only JPG, JPEG and PNG files are allowed.'], 415);
}

$imageInfo = @getimagesize($temporaryPath);
if ($imageInfo === false) {
    send_json(['success' => false, 'error' => 'Uploaded file is not a valid image.'], 415);
}

$width = (int) ($imageInfo[0] ?? 0);
$height = (int) ($imageInfo[1] ?? 0);
$imageMime = (string) ($imageInfo['mime'] ?? '');
if ($width < 64 || $height < 64 || $width > 4096 || $height > 4096) {
    send_json(['success' => false, 'error' => 'Image dimensions must be between 64x64 and 4096x4096 pixels.'], 422);
}
if (!in_array($imageMime, $extensionMimeMap[$extension], true)) {
    send_json(['success' => false, 'error' => 'Image content does not match the file extension.'], 415);
}

if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $finfoMime = (string) finfo_file($finfo, $temporaryPath);
        finfo_close($finfo);
        if (!in_array($finfoMime, $extensionMimeMap[$extension], true)) {
            send_json(['success' => false, 'error' => 'Invalid image MIME type.'], 415);
        }
    }
}

function reencode_square_profile_image(string $sourcePath, string $mime, string $destinationPath, string $targetExtension): void
{
    if ($mime === 'image/jpeg') {
        $source = @imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $source = @imagecreatefrompng($sourcePath);
    } else {
        $source = false;
    }

    if (!$source) {
        send_json(['success' => false, 'error' => 'Could not decode image.'], 415);
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $side = min($sourceWidth, $sourceHeight);
    $sourceX = (int) floor(($sourceWidth - $side) / 2);
    $sourceY = (int) floor(($sourceHeight - $side) / 2);
    $targetSize = 512;

    $target = imagecreatetruecolor($targetSize, $targetSize);
    if (!$target) {
        imagedestroy($source);
        send_json(['success' => false, 'error' => 'Image processing failed.'], 500);
    }
    if ($targetExtension === 'png') {
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetSize, $targetSize, $transparent);
    } else {
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $targetSize, $targetSize, $white);
    }

    imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, $targetSize, $targetSize, $side, $side);

    $ok = $targetExtension === 'png'
        ? imagepng($target, $destinationPath, 6)
        : imagejpeg($target, $destinationPath, 85);

    imagedestroy($source);
    imagedestroy($target);

    if (!$ok) {
        @unlink($destinationPath);
        send_json(['success' => false, 'error' => 'Failed to re-encode uploaded image.'], 500);
    }
}

$storageDir = profile_photo_storage_directory();
if (!is_dir($storageDir) && !@mkdir($storageDir, 0750, true)) {
    send_json(['success' => false, 'error' => 'Profile photo storage could not be created.'], 500);
}
if (!is_writable($storageDir)) {
    send_json(['success' => false, 'error' => 'Profile photo storage is not writable.'], 500);
}
@file_put_contents($storageDir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
@chmod($storageDir . DIRECTORY_SEPARATOR . '.htaccess', 0644);

$targetExtension = $imageMime === 'image/png' ? 'png' : 'jpg';
$newFilename = 'profile_' . (int) $sessionUser['user_id'] . '_' . bin2hex(random_bytes(16)) . '.' . $targetExtension;
$filePath = $storageDir . DIRECTORY_SEPARATOR . $newFilename;

reencode_square_profile_image($temporaryPath, $imageMime, $filePath, $targetExtension);
@chmod($filePath, 0640);

$conn = open_db_connection();
$stmt = $conn->prepare('SELECT photo_path FROM users WHERE id = ?');
if (!$stmt) {
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Database query failed.'], 500);
}
$userId = (int) $sessionUser['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$oldPhotoPath = $row['photo_path'] ?? null;
$stmt->close();

$relativePath = 'profile_photos/' . $newFilename;
$updateStmt = $conn->prepare('UPDATE users SET photo_path = ? WHERE id = ?');
if (!$updateStmt) {
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Database update failed.'], 500);
}
$updateStmt->bind_param('si', $relativePath, $userId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Failed to update user profile.'], 500);
}
$updateStmt->close();

$oldFilename = safe_profile_photo_filename(is_string($oldPhotoPath) ? $oldPhotoPath : null);
if ($oldFilename !== null) {
    $oldPrivate = secure_join_path($storageDir, $oldFilename);
    if ($oldPrivate !== null && is_file($oldPrivate)) {
        @unlink($oldPrivate);
    }

    $legacyDir = realpath(__DIR__ . '/../uploads/photos');
    if ($legacyDir !== false) {
        $oldLegacy = secure_join_path($legacyDir, $oldFilename);
        if ($oldLegacy !== null && is_file($oldLegacy)) {
            @unlink($oldLegacy);
        }
    }
}

$conn->close();
log_file_operation('profile_photo_upload', $newFilename, true);

send_json([
    'success' => true,
    'message' => 'Photo uploaded successfully.',
    'photo_path' => safe_public_photo_path($relativePath),
]);
