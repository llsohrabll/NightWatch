<?php
require_once(__DIR__ . '/common.php');
require_db_config_file();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

enforce_rate_limit('register', 5, 1800);

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$errors = [];

if ($username === '') {
    $errors[] = 'Username is required.';
} elseif (strlen($username) < 3 || strlen($username) > 50) {
    $errors[] = 'Username must be between 3 and 50 characters.';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    $errors[] = 'Username can only contain letters, numbers, and underscores.';
}

if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format.';
}

if ($password === '') {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}

if (!empty($errors)) {
    send_json(['success' => false, 'error' => implode(' ', $errors)], 422);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

$checkStmt = $conn->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
if (!$checkStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Registration is temporarily unavailable.'], 500);
}

$checkStmt->bind_param("ss", $username, $email);
$checkStmt->execute();
$result = $checkStmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row['username'] === $username) {
        $checkStmt->close();
        $conn->close();
        send_json(['success' => false, 'error' => 'Username already taken.'], 409);
    }
    if ($row['email'] === $email) {
        $checkStmt->close();
        $conn->close();
        send_json(['success' => false, 'error' => 'Email already registered.'], 409);
    }
}

$checkStmt->close();

$hashedPassword = password_hash($password, PASSWORD_ARGON2ID);
$token = bin2hex(random_bytes(32));
$expiresIn = NIGHTWATCH_SESSION_TTL;
$expiresAt = time() + $expiresIn;

$insertStmt = $conn->prepare("\n    INSERT INTO users (username, email, password, token, token_expires_at) \n    VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))\n");
if (!$insertStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Registration is temporarily unavailable.'], 500);
}

$insertStmt->bind_param("ssssi", $username, $email, $hashedPassword, $token, $expiresAt);

if ($insertStmt->execute()) {
    $newUser = [
        'id' => $insertStmt->insert_id,
        'username' => $username,
        'email' => $email,
    ];
    $sessionExpiresAt = create_user_session($newUser, $expiresIn);

    $response = [
        'success' => true,
        'message' => 'Registration successful!',
        'expires_in' => $expiresIn,
        'expires_at' => iso8601_from_timestamp($sessionExpiresAt),
        'expires_at_ms' => $sessionExpiresAt * 1000,
        'redirect' => '/panel'
    ];
} else {
    $response = [
        'success' => false,
        'error' => 'Registration failed. Please try again later.'
    ];
}

$insertStmt->close();
$conn->close();

send_json($response, $response['success'] ? 200 : 500);
