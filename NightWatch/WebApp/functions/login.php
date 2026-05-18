<?php
// ============================================================================
// LOGIN.PHP - SIGN A USER IN WITH RATE LIMITING AND PROGRESSIVE LOCKOUT
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
enforce_rate_limit('login', 8, 300);

$identifier = trim(strip_null_bytes((string) ($_POST['username'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$errors = [];

if ($identifier === '') {
    $errors[] = 'Username or email is required.';
} elseif (!is_valid_utf8($identifier)) {
    $errors[] = 'Username or email contains invalid characters.';
}

if ($password === '') {
    $errors[] = 'Password is required.';
} elseif (strpos($password, "\0") !== false) {
    $errors[] = 'Password contains invalid characters.';
}

$isEmail = false;
if ($identifier !== '' && strpos($identifier, '@') !== false) {
    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    } else {
        $isEmail = true;
        $identifier = strtolower($identifier);
    }
} else {
    if (strlen($identifier) < 3 || strlen($identifier) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }
}

if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

function register_failed_login(mysqli $conn, int $userId, int $currentFailures): void
{
    $nextFailures = min(20, $currentFailures + 1);
    $lockSeconds = $nextFailures >= 5 ? min(3600, (2 ** min(6, $nextFailures - 5)) * 60) : 0;

    if ($lockSeconds > 0) {
        $lockUntil = time() + $lockSeconds;
        $stmt = $conn->prepare('UPDATE users SET login_failures = ?, locked_until = FROM_UNIXTIME(?) WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('iii', $nextFailures, $lockUntil, $userId);
            $stmt->execute();
            $stmt->close();
        }
        header('Retry-After: ' . $lockSeconds);
    } else {
        $stmt = $conn->prepare('UPDATE users SET login_failures = ?, locked_until = NULL WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $nextFailures, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$conn = open_db_connection();
$sql = $isEmail
    ? 'SELECT id, username, email, password, email_verified, is_admin, login_failures, locked_until FROM users WHERE email = ? LIMIT 1'
    : 'SELECT id, username, email, password, email_verified, is_admin, login_failures, locked_until FROM users WHERE username = ? LIMIT 1';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}
$stmt->bind_param('s', $identifier);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    usleep(random_int(150000, 350000));
    $conn->close();
    log_auth_event('login', $identifier, false, 'unknown account');
    send_json(['success' => false, 'error' => 'Invalid credentials.'], 401);
}

$lockedUntil = isset($user['locked_until']) ? strtotime((string) $user['locked_until']) : false;
if ($lockedUntil !== false && $lockedUntil > time()) {
    $retryAfter = max(1, $lockedUntil - time());
    header('Retry-After: ' . $retryAfter);
    $conn->close();
    log_auth_event('login', (string) $user['username'], false, 'account locked');
    send_json(['success' => false, 'error' => 'Too many failed login attempts. Try again later.'], 429);
}

if (!password_verify($password, (string) $user['password'])) {
    register_failed_login($conn, (int) $user['id'], (int) ($user['login_failures'] ?? 0));
    $conn->close();
    log_auth_event('login', (string) $user['username'], false, 'bad password');
    send_json(['success' => false, 'error' => 'Invalid credentials.'], 401);
}

if (password_needs_rehash((string) $user['password'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($password, PASSWORD_ARGON2ID);
    if (is_string($newHash)) {
        $rehashStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        if ($rehashStmt) {
            $uid = (int) $user['id'];
            $rehashStmt->bind_param('si', $newHash, $uid);
            $rehashStmt->execute();
            $rehashStmt->close();
        }
    }
}

if ((int) $user['email_verified'] !== 1) {
    $conn->close();
    log_auth_event('login', (string) $user['username'], false, 'email not verified');
    send_json([
        'success' => false,
        'error' => 'Please verify your email first. Check your inbox for the verification code.',
        'requires_email_verification' => true,
        'email' => (string) $user['email'],
        'redirect' => '/register/verify',
    ], 403);
}

$resetStmt = $conn->prepare('UPDATE users SET login_failures = 0, locked_until = NULL, last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
if ($resetStmt) {
    $uid = (int) $user['id'];
    $resetStmt->bind_param('i', $uid);
    $resetStmt->execute();
    $resetStmt->close();
}

$rememberMe = isset($_POST['remember_me']) && in_array((string) $_POST['remember_me'], ['on', 'true', '1'], true);
$expiresIn = $rememberMe ? NIGHTWATCH_REMEMBER_TTL : NIGHTWATCH_SESSION_TTL;
$expiresAt = issue_user_token($conn, $user, $expiresIn);

$conn->close();
log_auth_event('login', (string) $user['username'], true);

send_json([
    'success' => true,
    'message' => 'Login successful!',
    'user' => [
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'is_admin' => !empty($user['is_admin']),
    ],
    'expires_in' => $expiresIn,
    'expires_at' => iso8601_from_timestamp($expiresAt),
    'expires_at_ms' => $expiresAt * 1000,
    'redirect' => '/panel',
]);
