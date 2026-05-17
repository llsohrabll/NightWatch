<?php
// ============================================================================
// VERIFY_EMAIL_CHANGE.PHP - FINISH VERIFIED EMAIL CHANGE FLOW
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
enforce_rate_limit('verify_email_change', 5, 900);

$sessionUser = require_authenticated_session();
$code = trim(strip_null_bytes((string) ($_POST['verification_code'] ?? '')));
if (!preg_match('/^\d{6}$/', $code)) {
    send_json(['success' => false, 'error' => 'Verification code must be 6 digits.'], 422);
}

$conn = open_db_connection();
$userId = (int) $sessionUser['user_id'];
$stmt = $conn->prepare('SELECT id, pending_email, email_change_code, email_change_code_expires_at FROM users WHERE id = ? AND pending_email IS NOT NULL AND email_change_code IS NOT NULL LIMIT 1');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change is temporarily unavailable.'], 500);
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !nightwatch_verify_one_time_code((string) $user['email_change_code'], $code)) {
    $conn->close();
    log_suspicious_activity('INVALID_EMAIL_CHANGE_CODE', (string) $userId);
    send_json(['success' => false, 'error' => 'Invalid email-change code.'], 401);
}

$expiresAt = strtotime((string) $user['email_change_code_expires_at']);
if ($expiresAt === false || $expiresAt < time()) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email-change code has expired. Request a fresh code.'], 401);
}

$newEmail = (string) $user['pending_email'];
$checkStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
if (!$checkStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change is temporarily unavailable.'], 500);
}
$checkStmt->bind_param('si', $newEmail, $userId);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();
if ($existing) {
    $conn->close();
    send_json(['success' => false, 'error' => 'That email address is already in use.'], 409);
}

$updateStmt = $conn->prepare('UPDATE users SET email = ?, pending_email = NULL, email_change_code = NULL, email_change_code_expires_at = NULL WHERE id = ?');
if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change failed.'], 500);
}
$updateStmt->bind_param('si', $newEmail, $userId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change failed.'], 500);
}
$updateStmt->close();
$conn->close();

$_SESSION['email'] = $newEmail;
log_security_event('EMAIL_CHANGED | NewEmail: ' . $newEmail, 'INFO', (string) $userId);

send_json([
    'success' => true,
    'message' => 'Email address updated successfully.',
    'email' => $newEmail,
]);
