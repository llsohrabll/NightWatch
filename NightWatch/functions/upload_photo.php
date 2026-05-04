<?php
// ============================================================================
// UPLOAD_PHOTO.PHP - SAVE A USER'S PROFILE PHOTO
// ============================================================================
// The browser sends a file here using multipart/form-data. This endpoint
// validates the file, stores it on disk, and saves the path in the database.

declare(strict_types=1);

// Load common utilities and database config
require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// File uploads change server state, so they must be POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// Only signed-in users can upload a photo for their own profile.
$sessionUser = require_authenticated_session();

// ============================================================================
// VALIDATION: Check file was actually uploaded
// ============================================================================
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
    $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error.';
    send_json(['success' => false, 'error' => $errorMsg], 400);
}

// ============================================================================
// The filename extension is the first quick filter.
// ============================================================================
$uploadedFile = $_FILES['photo'];
$fileType = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

// PNG is allowed too, not just JPEG.
$allowedTypes = ['jpg', 'jpeg', 'png'];

if (!in_array($fileType, $allowedTypes, true)) {
    send_json([
        'success' => false,
        'error' => 'Invalid file type. Only JPG, JPEG and PNG files are allowed.'
    ], 400);
}

// ============================================================================
// Large files are rejected before anything is written to disk.
// ============================================================================
$maxFileSize = 500 * 1024; // 500KB in bytes
$fileSize = (int) $uploadedFile['size'];

if ($fileSize > $maxFileSize) {
    send_json([
        'success' => false,
        'error' => 'File size exceeds 500KB limit. Please choose a smaller file.'
    ], 400);
}

// ============================================================================
// A fake extension is not enough; inspect the uploaded temp file itself.
$imageInfo = @getimagesize($uploadedFile['tmp_name']);

if ($imageInfo === false) {
    send_json([
        'success' => false,
        'error' => 'Uploaded file is not a valid image.'
    ], 400);
}

// The detected MIME type must also match an approved image type.
$allowedMimes = ['image/jpeg', 'image/jpg', 'image/png'];
if (!in_array($imageInfo['mime'] ?? '', $allowedMimes, true)) {
    send_json([
        'success' => false,
        'error' => 'Invalid image type. Only JPG/JPEG/PNG images are allowed.'
    ], 400);
}

// ============================================================================
// Build the filesystem path where uploaded photos live.
$uploadDir = __DIR__ . '/../uploads/photos/';

// Create the folder if the deployment does not have it yet.
if (!is_dir($uploadDir)) {
    // Try to create directory if it doesn't exist
    if (!@mkdir($uploadDir, 0755, true)) {
        send_json([
            'success' => false,
            'error' => 'Upload directory could not be created.'
        ], 500);
    }
}

// Verify directory is writable
if (!is_writable($uploadDir)) {
    send_json([
        'success' => false,
        'error' => 'Upload directory is not writable. Please contact the administrator.'
    ], 500);
}

// ============================================================================
// Use a random filename so two uploads do not overwrite each other.
$newFilename = $sessionUser['user_id'] . '_' . bin2hex(random_bytes(16)) . '.' . $fileType;
$filePath = $uploadDir . $newFilename;

// ============================================================================
// PHP first stores uploads in a temp folder. move_uploaded_file() makes it permanent.
// ============================================================================
if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
    send_json([
        'success' => false,
        'error' => 'Failed to save the uploaded file.'
    ], 500);
}

// ============================================================================
// After the file is saved, update the matching database row.
// ============================================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    // Clean up uploaded file if database connection fails
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

// ============================================================================
// Read the previous photo path so the old file can be deleted after the update.
// ============================================================================
$stmt = $conn->prepare("SELECT photo_path FROM users WHERE id = ?");
if (!$stmt) {
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Database query failed.'], 500);
}

$stmt->bind_param("i", $sessionUser['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$oldPhotoPath = $row['photo_path'] ?? null;
$stmt->close();

// ============================================================================
// Save a relative path, not the server's absolute filesystem path.
$relativePath = 'uploads/photos/' . $newFilename;

$updateStmt = $conn->prepare("UPDATE users SET photo_path = ? WHERE id = ?");
if (!$updateStmt) {
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Database update failed.'], 500);
}

$updateStmt->bind_param("si", $relativePath, $sessionUser['user_id']);

if (!$updateStmt->execute()) {
    $conn->close();
    @unlink($filePath);
    send_json(['success' => false, 'error' => 'Failed to update user profile.'], 500);
}

$updateStmt->close();

// ============================================================================
// Remove the old file only after confirming the path still points inside uploads/photos.
// ============================================================================
if ($oldPhotoPath !== null && $oldPhotoPath !== '') {
    // Construct full path to old photo
    $oldFilePath = __DIR__ . '/../' . $oldPhotoPath;
    
    // This safety check blocks accidental deletion outside the upload folder.
    $oldRealPath = realpath($oldFilePath);
    $uploadRealPath = realpath($uploadDir);

    if (
        $oldRealPath !== false
        && $uploadRealPath !== false
        && strpos($oldRealPath, $uploadRealPath . DIRECTORY_SEPARATOR) === 0
        && is_file($oldRealPath)
    ) {
        @unlink($oldFilePath);
    }
}

$conn->close();

// ============================================================================
// The browser uses this path to refresh the avatar immediately.
// ============================================================================
send_json([
    'success' => true,
    'message' => 'Photo uploaded successfully.',
    'photo_path' => $relativePath
]);
?>
