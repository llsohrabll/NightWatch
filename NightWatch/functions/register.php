<?php
// ============================================================================
// REGISTER.PHP - HANDLE NEW USER REGISTRATION
// ============================================================================
// This file processes new user registration requests
// It validates input, checks for duplicates, hashes password, and creates session

// Load common utility functions and database configuration
require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// SECURITY: Check request method
// ============================================================================
// Only accept POST requests (not GET, DELETE, etc)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// ============================================================================
// SECURITY: Rate limit to prevent registration spam
// ============================================================================
// Allow maximum 5 registration attempts per 1800 seconds (30 minutes) per IP
// This prevents automated bot registration attacks
enforce_rate_limit('register', 5, 1800);

// ============================================================================
// STEP 1: Get and validate user input
// ============================================================================
// Get username from form and remove leading/trailing whitespace
$username = trim($_POST['username'] ?? '');
// Get email from form and remove whitespace
$email    = trim($_POST['email'] ?? '');
// Get password (don't trim - spaces in password matter)
$password = $_POST['password'] ?? '';

// Array to collect all validation errors
$errors = [];

// ============================================================================
// Validate username
// ============================================================================
if ($username === '') {
    $errors[] = 'Username is required.';
} elseif (strlen($username) < 3 || strlen($username) > 50) {
    // Username must be 3-50 characters
    $errors[] = 'Username must be between 3 and 50 characters.';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    // Username can only contain letters, numbers, and underscores
    $errors[] = 'Username can only contain letters, numbers, and underscores.';
}

// ============================================================================
// Validate email
// ============================================================================
if ($email === '') {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Use PHP's built-in email validator
    $errors[] = 'Invalid email format.';
}

// ============================================================================
// Validate password
// ============================================================================
if ($password === '') {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 8) {
    // Require minimum 8 characters for security
    $errors[] = 'Password must be at least 8 characters.';
}

// If validation errors found, send them back and stop
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
// STEP 3: Check if username or email already exists
// ============================================================================
// Use single query to check both username and email for efficiency
$checkStmt = $conn->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
if (!$checkStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Registration is temporarily unavailable.'], 500);
}

// Bind the username and email to check
$checkStmt->bind_param("ss", $username, $email);

// Execute the query
$checkStmt->execute();

// Get the result set
$result = $checkStmt->get_result();

// Loop through all matching rows
while ($row = $result->fetch_assoc()) {
    // Check if username matches
    if ($row['username'] === $username) {
        $checkStmt->close();
        $conn->close();
        // Username already taken - 409 = Conflict
        send_json(['success' => false, 'error' => 'Username already taken.'], 409);
    }
    // Check if email matches
    if ($row['email'] === $email) {
        $checkStmt->close();
        $conn->close();
        // Email already registered - 409 = Conflict
        send_json(['success' => false, 'error' => 'Email already registered.'], 409);
    }
}

// Close the check statement
$checkStmt->close();

// ============================================================================
// STEP 4: Hash password using Argon2ID algorithm
// ============================================================================
// PASSWORD_ARGON2ID is modern, secure algorithm resistant to GPU/ASIC attacks
// This creates a hash that cannot be reversed - only verified with password_verify()
$hashedPassword = password_hash($password, PASSWORD_ARGON2ID);

// ============================================================================
// STEP 5: Generate session token and expiration time
// ============================================================================
// Create random token (64 hex characters = 32 random bytes)
// Note: token is stored but not validated in authentication; sessions are used instead
$token = bin2hex(random_bytes(32));

// Get session TTL from constant
$expiresIn = NIGHTWATCH_SESSION_TTL;

// Calculate expiration timestamp
$expiresAt = time() + $expiresIn;

// ============================================================================
// STEP 6: Insert new user into database
// ============================================================================
// Prepare INSERT statement with placeholders to prevent SQL injection
$insertStmt = $conn->prepare("
    INSERT INTO users (username, email, password, token, token_expires_at) 
    VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
");
if (!$insertStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Registration is temporarily unavailable.'], 500);
}

// Bind parameters: s=string, i=integer
$insertStmt->bind_param("ssssi", $username, $email, $hashedPassword, $token, $expiresAt);

// Execute the insert
if ($insertStmt->execute()) {
    // SUCCESS: User created successfully
    
    // Get the auto-generated user ID from the INSERT
    $newUser = [
        'id' => $insertStmt->insert_id,
        'username' => $username,
        'email' => $email,
    ];
    
    // Create PHP session for newly registered user
    $sessionExpiresAt = create_user_session($newUser, $expiresIn);

    // Build success response
    $response = [
        'success' => true,
        'message' => 'Registration successful!',
        // Session TTL in seconds
        'expires_in' => $expiresIn,
        // Expiration timestamp in ISO 8601 format
        'expires_at' => iso8601_from_timestamp($sessionExpiresAt),
        // Expiration timestamp in milliseconds for JavaScript
        'expires_at_ms' => $sessionExpiresAt * 1000,
        // URL to redirect after registration
        'redirect' => '/panel'
    ];
} else {
    // FAILURE: Insert failed
    $response = [
        'success' => false,
        'error' => 'Registration failed. Please try again later.'
    ];
}

// ============================================================================
// STEP 7: Clean up database connections
// ============================================================================
$insertStmt->close();
$conn->close();

// ============================================================================
// STEP 8: Send response to client
// ============================================================================
// Send 200 for success, 500 for failure
send_json($response, $response['success'] ? 200 : 500);
