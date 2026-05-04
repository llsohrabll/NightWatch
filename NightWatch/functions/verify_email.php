<?php
// ============================================================================
// VERIFY_EMAIL.PHP - FINISH SIGN-UP BY CHECKING THE EMAIL CODE
// ============================================================================
// The user enters the 6-digit code from the email they received after registering.

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// This endpoint changes account state, so it must be a POST request.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// Do not allow unlimited code guesses from one IP address.
enforce_rate_limit('verify_email', 10, 300);

// ============================================================================
// STEP 1: Get and validate form inputs
// ============================================================================
$email = trim($_POST['email'] ?? '');
$verificationCode = trim($_POST['verification_code'] ?? '');

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
// Validate verification code
// ============================================================================
if ($verificationCode === '') {
    $errors[] = 'Verification code is required.';
} elseif (!preg_match('/^\d{6}$/', $verificationCode)) {
    // Code must be exactly 6 digits
    $errors[] = 'Invalid verification code format.';
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
// STEP 3: Load the unverified user row for this email address
$stmt = $conn->prepare("
    SELECT id, username, email, email_verification_code, email_verification_code_expires_at 
    FROM users 
    WHERE email = ? AND email_verified = 0 AND email_verification_code IS NOT NULL
");

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email verification is temporarily unavailable.'], 500);
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

// ============================================================================
// Use a generic error so the response does not reveal account state.
// ============================================================================
if (!$user) {
    $stmt->close();
    $conn->close();
    
    // Generic error message to prevent user enumeration
    send_json([
        'success' => false,
        'error' => 'Invalid email or verification code. Please check and try again.'
    ], 401);
}

// ============================================================================
// Compare the stored code and the typed code safely.
// ============================================================================
if (!hash_equals($user['email_verification_code'], $verificationCode)) {
    $stmt->close();
    $conn->close();
    
    // Log suspicious activity
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
    @file_put_contents($logFile, 
        date('Y-m-d H:i:s') . " | INVALID EMAIL VERIFICATION CODE for user: {$user['id']} | IP: " . request_ip_address() . "\n",
        FILE_APPEND
    );
    
    send_json([
        'success' => false,
        'error' => 'Invalid email or verification code.'
    ], 401);
}

// ============================================================================
// Old codes are rejected even if the number matches.
// ============================================================================
$now = time();
$expiresAtTimestamp = strtotime($user['email_verification_code_expires_at']);

if ($now > $expiresAtTimestamp) {
    $stmt->close();
    $conn->close();
    
    // Code has expired
    send_json([
        'success' => false,
        'error' => 'Verification code has expired. Please register again to receive a new code.'
    ], 401);
}

$stmt->close();

// ============================================================================
// STEP 4: Mark the account as verified and remove the one-time code
// ============================================================================
$updateStmt = $conn->prepare("
    UPDATE users 
    SET email_verified = 1, email_verification_code = NULL, email_verification_code_expires_at = NULL
    WHERE id = ?
");

if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email verification failed.'], 500);
}

$updateStmt->bind_param("i", $user['id']);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Email verification failed.'], 500);
}

$updateStmt->close();

// ============================================================================
// STEP 5: Log successful email verification
// ============================================================================
$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
@file_put_contents($logFile, 
    date('Y-m-d H:i:s') . " | Email verified successfully for user: {$user['id']} ({$user['email']}) | IP: " . request_ip_address() . "\n",
    FILE_APPEND
);

// ============================================================================
// STEP 6: Now that the account is verified, sign the browser in
// ============================================================================
$sessionExpiresAt = create_user_session($user, NIGHTWATCH_SESSION_TTL);

// ============================================================================
// STEP 7: Clean up and send response
// ============================================================================
$conn->close();

send_json([
    'success' => true,
    'message' => 'Email verified successfully! You are now logged in.',
    'expires_in' => NIGHTWATCH_SESSION_TTL,
    'expires_at' => iso8601_from_timestamp($sessionExpiresAt),
    'expires_at_ms' => $sessionExpiresAt * 1000,
    'redirect' => '/panel'
], 200);
