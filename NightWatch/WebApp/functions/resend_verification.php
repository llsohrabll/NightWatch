<?php
// ============================================================================
// RESEND_VERIFICATION.PHP - SEND A FRESH EMAIL VERIFICATION CODE
// ============================================================================
// The verify page calls this when the user did not receive the first code.

declare(strict_types=1);

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/app_logging.php');
require_db_config_file();

// Set security headers for this JSON endpoint.
set_security_headers();

// Require HTTPS outside localhost/development.
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();
enforce_csrf_token();

// Keep this tighter than registration. A normal user only needs a small number
// of resend attempts while fixing spam-folder or mail-delay issues.
enforce_rate_limit('resend_verification', 3, 900);

$email = strtolower(trim(strip_null_bytes((string) ($_POST['email'] ?? ''))));

if ($email === '') {
    send_json(['success' => false, 'error' => 'Email is required.'], 422);
}
if (!is_valid_utf8($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['success' => false, 'error' => 'Invalid email format.'], 422);
}

$conn = open_db_connection();

$stmt = $conn->prepare('SELECT id, email, email_verified FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Verification resend is temporarily unavailable.'], 500);
}

$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $conn->close();
    // Do not reveal whether the email exists.
    send_json([
        'success' => true,
        'message' => 'If an unverified account exists for this email, a fresh code has been sent.',
    ], 200);
}

if ((int) $user['email_verified'] === 1) {
    $conn->close();
    send_json([
        'success' => false,
        'error' => 'This email is already verified. Please log in.',
        'redirect' => '/login',
    ], 409);
}

require_once(__DIR__ . '/mailer.php');
$verificationCode = generate_verification_code();
$expiresAt = time() + EMAIL_VERIFICATION_TIMEOUT;
$verificationCodeHash = nightwatch_code_hash($verificationCode);

$updateStmt = $conn->prepare('
    UPDATE users
    SET email_verification_code = ?, email_verification_code_expires_at = FROM_UNIXTIME(?)
    WHERE id = ? AND email_verified = 0
');
if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Verification resend is temporarily unavailable.'], 500);
}

$userId = (int) $user['id'];
$updateStmt->bind_param('sii', $verificationCodeHash, $expiresAt, $userId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Could not create a fresh verification code.'], 500);
}
$updateStmt->close();

$emailSent = send_verification_email((string) $user['email'], $verificationCode);

log_security_event(
    "VERIFICATION_RESEND | User: {$userId} | Email: {$user['email']} | MailSent: " . ($emailSent ? 'YES' : 'NO'),
    'INFO',
    (string) $userId
);

$conn->close();

if (!$emailSent) {
    send_json([
        'success' => false,
        'error' => 'A fresh code was created, but the email could not be sent. Check the server mail configuration.',
    ], 500);
}

send_json([
    'success' => true,
    'message' => 'A fresh verification code has been sent. It expires in 15 minutes.',
    'expires_in' => EMAIL_VERIFICATION_TIMEOUT,
], 200);
