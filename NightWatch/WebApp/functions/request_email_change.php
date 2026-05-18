<?php
// ============================================================================
// REQUEST_EMAIL_CHANGE.PHP - START VERIFIED EMAIL CHANGE FLOW
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
enforce_rate_limit('request_email_change', 3, 1800);

$sessionUser = require_authenticated_session();
$newEmail = strtolower(trim(strip_null_bytes((string) ($_POST['new_email'] ?? ''))));
$currentPassword = (string) ($_POST['current_password'] ?? '');
$errors = [];

if ($newEmail === '') {
    $errors[] = 'New email is required.';
} elseif (!is_valid_utf8($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid new email format.';
}
if ($currentPassword === '' || strpos($currentPassword, "\0") !== false) {
    $errors[] = 'Current password is required.';
}
if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

$conn = open_db_connection();
$userId = (int) $sessionUser['user_id'];

$stmt = $conn->prepare('SELECT id, email, password FROM users WHERE id = ? AND email_verified = 1 LIMIT 1');
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change is temporarily unavailable.'], 500);
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($currentPassword, (string) $user['password'])) {
    $conn->close();
    log_auth_event('email_change', (string) $sessionUser['username'], false, 'bad current password');
    send_json(['success' => false, 'error' => 'Current password is incorrect.'], 401);
}

if (strcasecmp((string) $user['email'], $newEmail) === 0) {
    $conn->close();
    send_json(['success' => false, 'error' => 'New email must be different from your current email.'], 422);
}

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

require_once(__DIR__ . '/mailer.php');
$code = generate_verification_code();
$codeHash = nightwatch_code_hash($code);
$expiresAt = time() + EMAIL_VERIFICATION_TIMEOUT;

$updateStmt = $conn->prepare('UPDATE users SET pending_email = ?, email_change_code = ?, email_change_code_expires_at = FROM_UNIXTIME(?) WHERE id = ?');
if (!$updateStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Email change is temporarily unavailable.'], 500);
}
$updateStmt->bind_param('ssii', $newEmail, $codeHash, $expiresAt, $userId);
if (!$updateStmt->execute()) {
    $updateStmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Could not start email change.'], 500);
}
$updateStmt->close();

$emailSent = send_email_change_verification_email($newEmail, $code);
log_security_event('EMAIL_CHANGE_REQUESTED | PendingEmail: ' . $newEmail . ' | MailSent: ' . ($emailSent ? 'YES' : 'NO'), 'INFO', (string) $userId);
$conn->close();

send_json([
    'success' => true,
    'message' => $emailSent
        ? 'A confirmation code has been sent to your new email address.'
        : 'Email change was saved, but the confirmation email could not be sent. Check mail configuration.',
    'email_sent' => $emailSent,
]);
