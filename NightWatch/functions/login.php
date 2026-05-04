<?php
// ============================================================================
// LOGIN.PHP - SIGN A USER IN
// ============================================================================

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// This endpoint changes authentication state, so it only accepts POST.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// Slow down repeated guesses from one IP address.
// ============================================================================
enforce_rate_limit('login', 8, 300);

// ============================================================================
// STEP 1: Get and validate user input
// ============================================================================
$identifier = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';

$errors = [];

// The login form uses one field that can hold either a username or an email.
if ($identifier === '') {
    $errors[] = 'Username or email is required.';
}

// Validate password
if ($password === '') {
    $errors[] = 'Password is required.';
}

// Decide which database column to search.
$isEmail = false;

if ($identifier !== '' && strpos($identifier, '@') !== false) {
    // If it looks like an email, validate it as an email address.
    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    } else {
        $isEmail = true;
    }

} else {
    // Otherwise treat it as a username.
    if (strlen($identifier) < 3 || strlen($identifier) > 50) {
        $errors[] = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }
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
// STEP 3: Load the matching user record from the database
// ============================================================================
if ($isEmail) {
    $stmt = $conn->prepare("SELECT id, username, email, password, email_verified FROM users WHERE email = ?");
} else {
    $stmt = $conn->prepare("SELECT id, username, email, password, email_verified FROM users WHERE username = ?");
}

if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}

$stmt->bind_param("s", $identifier);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

// ============================================================================
// STEP 4: Check if user exists
// ============================================================================
if (!$user) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Invalid credentials.'], 401);
}

// ============================================================================
// STEP 5: Check the stored password hash
// ============================================================================
if (!password_verify($password, $user['password'])) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Invalid credentials.'], 401);
}

// ============================================================================
// STEP 5.5: Only after the password is correct do we reveal that email verification is still needed
// ============================================================================
if (!$user['email_verified']) {
    $stmt->close();
    $conn->close();
    send_json([
        'success' => false,
        'error' => 'Please verify your email first. Check your inbox for the verification code.',
        'requires_email_verification' => true
    ], 403);
}

// ============================================================================
// STEP 6: Check if "Remember me" was checked
// ============================================================================
$rememberMe = isset($_POST['remember_me']) && ($_POST['remember_me'] === 'on' || $_POST['remember_me'] === 'true');

// If remember me is checked, use 1 week (604800 seconds), otherwise use default 10 minutes
$expiresIn = $rememberMe ? (7 * 24 * 60 * 60) : NIGHTWATCH_SESSION_TTL;

// ============================================================================
// STEP 7: Start a signed-in session for this browser
// ============================================================================
$token = bin2hex(random_bytes(32));
$expiresAt = create_user_session($user, $expiresIn);

// ============================================================================
// STEP 8: Keep writing the legacy token columns even though the real auth check uses PHP sessions
$updateStmt = $conn->prepare(
    "UPDATE users SET token = ?, token_expires_at = FROM_UNIXTIME(?) WHERE id = ?"
);

if (!$updateStmt) {
    $stmt->close();
    $conn->close();
    destroy_app_session();
    send_json(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}

$updateStmt->bind_param("sii", $token, $expiresAt, $user['id']);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    $stmt->close();
    $conn->close();
    destroy_app_session();
    send_json(['success' => false, 'error' => 'Login is temporarily unavailable.'], 500);
}

// ============================================================================
// STEP 8: Cleanup
// ============================================================================
$updateStmt->close();
$stmt->close();
$conn->close();

// ============================================================================
// STEP 9: Response
// ============================================================================
send_json([
    'success' => true,
    'message' => 'Login successful!',
    'user' => [
        'username' => $user['username'],
        'email' => $user['email']
    ],
    'expires_in' => $expiresIn,
    'expires_at' => iso8601_from_timestamp($expiresAt),
    'expires_at_ms' => $expiresAt * 1000,
    'redirect' => '/panel'
]);
