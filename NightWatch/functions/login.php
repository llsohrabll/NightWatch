<?php
// ============================================================================
// LOGIN.PHP - HANDLE USER AUTHENTICATION
// ============================================================================

require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// SECURITY: Check request method
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// ============================================================================
// SECURITY: Rate limit
// ============================================================================
enforce_rate_limit('login', 8, 300);

// ============================================================================
// STEP 1: Get and validate user input
// ============================================================================
$identifier = trim($_POST['username'] ?? '');
$password   = $_POST['password'] ?? '';

$errors = [];

// Validate identifier
if ($identifier === '') {
    $errors[] = 'Username or email is required.';
}

// Validate password
if ($password === '') {
    $errors[] = 'Password is required.';
}

// Determine identifier type
$isEmail = false;

if ($identifier !== '' && strpos($identifier, '@') !== false) {
    // Email validation
    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    } else {
        $isEmail = true;
    }

} else {
    // Username validation
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
// STEP 3: Query user
// ============================================================================
if ($isEmail) {
    $stmt = $conn->prepare("SELECT id, username, email, password FROM users WHERE email = ?");
} else {
    $stmt = $conn->prepare("SELECT id, username, email, password FROM users WHERE username = ?");
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
// STEP 4: Verify password
// ============================================================================
if (!$user || !password_verify($password, $user['password'])) {
    $stmt->close();
    $conn->close();
    send_json(['success' => false, 'error' => 'Invalid credentials.'], 401);
}

// ============================================================================
// STEP 5: Create session
// ============================================================================
$token = bin2hex(random_bytes(32));
$expiresIn = NIGHTWATCH_SESSION_TTL;
$expiresAt = create_user_session($user, $expiresIn);

// ============================================================================
// STEP 6: Save token to database (Note: token not currently used in authentication)
// ============================================================================
// Token is stored but not validated on future requests; authentication uses PHP sessions only
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
// STEP 7: Cleanup
// ============================================================================
$updateStmt->close();
$stmt->close();
$conn->close();

// ============================================================================
// STEP 8: Response
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