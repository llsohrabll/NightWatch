<?php
// ============================================================================
// RESET_PASSWORD.PHP - CHECK THE RESET CODE AND SAVE THE NEW PASSWORD
// ============================================================================
// This is the second half of password reset. The user sends the email,
// the one-time code, and the new password.

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// Resetting a password changes server data, so this must be POST.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// Limit how fast one IP can try reset codes.
enforce_rate_limit('reset_password', 5, 600);

// ============================================================================
// STEP 1: Get and validate form inputs
// ============================================================================
$email        = trim($_POST['email'] ?? '');
$resetCode    = trim($_POST['reset_code'] ?? '');
$newPassword  = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$errors = [];

// ============================================================================
// Validate email
// ============================================================================
if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format.';
}

// ============================================================================
// Validate reset code
// ============================================================================
if ($resetCode === '') {
    $errors[] = 'Reset code is required.';
} elseif (!preg_match('/^\d{6}$/', $resetCode)) {
    // Code must be exactly 6 digits
    $errors[] = 'Invalid reset code format.';
}

// ============================================================================
// Validate passwords
// ============================================================================
if ($newPassword === '') {
    $errors[] = 'New password is required.';
} elseif (strlen($newPassword) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}

if ($confirmPassword === '') {
    $errors[] = 'Password confirmation is required.';
} elseif ($newPassword !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

// Return validation errors
if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

// ============================================================================
// STEP 2: Connect to database
// ============================================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

// ============================================================================
// STEP 3: Load the user row that currently has a reset code
$stmt = $conn->prepare("
    SELECT id, username, email, reset_code, reset_code_expires_at 
    FROM users 
    WHERE email = ? AND reset_code IS NOT NULL
");

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Password reset is temporarily unavailable.'], 500);
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

// ============================================================================
// SECURITY: Verify user exists
// ============================================================================
if (!$user) {
    $stmt->close();
    $conn->close();
    
    // Generic error message to prevent user enumeration
    send_json([
        'success' => false,
        'error' => 'Invalid email or reset code. Please try again.'
    ], 401);
}

// ============================================================================
// Compare the stored code and the typed code safely.
if (!hash_equals($user['reset_code'], $resetCode)) {
    $stmt->close();
    $conn->close();
    
    // Log suspicious activity
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
    @file_put_contents($logFile, 
        date('Y-m-d H:i:s') . " | INVALID RESET CODE for user: {$user['id']} | IP: " . request_ip_address() . "\n",
        FILE_APPEND
    );
    
    send_json([
        'success' => false,
        'error' => 'Invalid email or reset code. Please try again.'
    ], 401);
}

// ============================================================================
// Old reset codes cannot be reused.
$now = time();

// Parse reset code expiration from database
$expiresAtTimestamp = strtotime($user['reset_code_expires_at']);

if ($now > $expiresAtTimestamp) {
    $stmt->close();
    $conn->close();
    
    // Code has expired
    send_json([
        'success' => false,
        'error' => 'Reset code has expired. Please request a new one.'
    ], 401);
}

$stmt->close();

// ============================================================================
// STEP 4: Hash the new password before saving it
$hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);

// ============================================================================
// STEP 5: Save the new password and remove the one-time code
// ============================================================================
$updateStmt = $conn->prepare("
    UPDATE users 
    SET password = ?, reset_code = NULL, reset_code_expires_at = NULL
    WHERE id = ?
");

if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Password reset failed.'], 500);
}

$updateStmt->bind_param("si", $hashedPassword, $user['id']);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Password reset failed.'], 500);
}

$updateStmt->close();

// ============================================================================
// STEP 6: Log successful password reset
// ============================================================================
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
@file_put_contents($logFile, 
    date('Y-m-d H:i:s') . " | Password reset successful for user: {$user['id']} ({$user['email']}) | IP: " . request_ip_address() . "\n",
    FILE_APPEND
);

// ============================================================================
// STEP 7: Clean up and send response
// ============================================================================
$conn->close();

send_json([
    'success' => true,
    'message' => 'Password has been reset successfully. You can now login with your new password.',
], 200);
