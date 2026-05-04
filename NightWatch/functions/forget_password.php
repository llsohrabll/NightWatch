<?php
// ============================================================================
// FORGET_PASSWORD.PHP - START THE PASSWORD RESET FLOW
// ============================================================================
// The browser sends an email address here. If an account exists, the server
// creates a one-time reset code and sends it by email.

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// This request changes server state, so it must be a POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// Slow down repeated reset requests from the same IP.
enforce_rate_limit('forget_password', 3, 1800);

// ============================================================================
// STEP 1: Get and validate email from form
// ============================================================================
$email = trim($_POST['email'] ?? '');

$errors = [];

// Validate email is provided
if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Use PHP's built-in email validator
    $errors[] = 'Invalid email format.';
}

// Return validation errors
if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

// ============================================================================
// STEP 2: Connect to database and find user by email
// ============================================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

// Prepare statement to find user by email
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ?");

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Password reset is temporarily unavailable.'], 500);
}

// Bind email parameter
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

// ============================================================================
// Always return the same success message, even for unknown emails.
if (!$user) {
    $stmt->close();
    $conn->close();
    
    // Send generic success response (actual email not found, but attacker doesn't know)
    send_json([
        'success' => true,
        'message' => 'If an account exists with this email, a reset code will be sent shortly.',
    ], 200);
}

// Close the select statement
$stmt->close();

// ============================================================================
// STEP 3: Create the temporary code the user will type on the reset page
require_once(__DIR__ . '/mailer.php');

// Generate random 6-digit code (000000-999999)
$resetCode = generate_verification_code();

// Calculate expiration time (1 hour from now)
$expiresAt = time() + 3600;

// ============================================================================
// STEP 4: Update database with reset code and expiration
// ============================================================================
$updateStmt = $conn->prepare("
    UPDATE users 
    SET reset_code = ?, reset_code_expires_at = FROM_UNIXTIME(?)
    WHERE id = ?
");

if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Password reset is temporarily unavailable.'], 500);
}

// Bind parameters: s=string, i=integer
$updateStmt->bind_param("sii", $resetCode, $expiresAt, $user['id']);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Failed to generate reset code.'], 500);
}

$updateStmt->close();

// ============================================================================
// STEP 5: Send the reset code by email
$emailSent = send_password_reset_email($user['email'], $resetCode);

// ============================================================================
// STEP 6: Log event (for security audit trail)
// ============================================================================
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
@file_put_contents($logFile, 
    date('Y-m-d H:i:s') . " | Password reset requested for user: {$user['id']} ({$user['email']}) | Email sent: " . ($emailSent ? 'YES' : 'NO') . "\n",
    FILE_APPEND
);

// ============================================================================
// STEP 7: Clean up and send response
// ============================================================================
$conn->close();

// Send success response
send_json([
    'success' => true,
    'message' => 'If an account exists with this email, a reset code has been sent to your email.',
], 200);
