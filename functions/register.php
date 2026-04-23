<?php
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

require_once('/var/www/config/db_config.php');

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';


// --- Validation ---
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
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// --- Database operations ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Check for existing username or email
$checkStmt = $conn->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
$checkStmt->bind_param("ss", $username, $email);
$checkStmt->execute();
$result = $checkStmt->get_result();

$conflicts = [];
while ($row = $result->fetch_assoc()) {
    if ($row['username'] === $username) {
        $conflicts[] = 'Username already taken.';
        break;
    }
    if ($row['email'] === $email) {
        $conflicts[] = 'Email already registered.';
        break;
    }
}

$checkStmt->close();

if (!empty($conflicts)) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => implode(' ', $conflicts)]);
    exit;
}



$hashedPassword = password_hash($password, PASSWORD_ARGON2ID);

// Generate secure random token
$token = bin2hex(random_bytes(32)); // 64-char token

// Set expiration time (10 minutes from now)
$expiresAt = date('Y-m-d H:i:s', time() + 600);

$insertStmt = $conn->prepare("
    INSERT INTO users (username, email, password, token, token_expires_at) 
    VALUES (?, ?, ?, ?, ?)
");

$insertStmt->bind_param("sssss", $username, $email, $hashedPassword, $token, $expiresAt);

if ($insertStmt->execute()) {
    $response = [
        'success' => true,
        'message' => 'Registration successful!',
        'token' => $token,
        'expires_at' => $expiresAt
    ];
} else {
    $response = [
        'success' => false,
        'error' => 'Registration failed. Please try again later.'
    ];
}

$insertStmt->close();
$conn->close();

echo json_encode($response);
?>