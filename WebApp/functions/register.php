<?php
// ============================================================================
// REGISTER.PHP - CREATE A NEW USER ACCOUNT
// ============================================================================
// This endpoint validates the form, creates the user row, and starts email verification.

// Load common utility functions and database configuration
require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// Registration changes server data, so it must be a POST request.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// Slow down repeated account creation attempts from the same IP address.
enforce_rate_limit('register', 5, 1800);

// ============================================================================
// STEP 1: Get and validate user input
// ============================================================================
// Get username from form and remove leading/trailing whitespace
$username = trim(strip_null_bytes((string) ($_POST['username'] ?? '')));
// Get email from form and remove whitespace
$email    = strtolower(trim(strip_null_bytes((string) ($_POST['email'] ?? ''))));
// Get password (don't trim - spaces in password matter)
$password = (string) ($_POST['password'] ?? '');

// Collect every validation error and send them back together.
$errors = [];

// ============================================================================
// Validate username
// ============================================================================
if ($username === '') {
    $errors[] = 'Username is required.';
} elseif (!is_valid_utf8($username)) {
    $errors[] = 'Username contains invalid characters.';
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
} elseif (!is_valid_utf8($email)) {
    $errors[] = 'Email contains invalid characters.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Use PHP's built-in email validator
    $errors[] = 'Invalid email format.';
}

// ============================================================================
// Validate password
// ============================================================================
if ($password === '') {
    $errors[] = 'Password is required.';
} elseif (strpos($password, "\0") !== false) {
    $errors[] = 'Password contains invalid characters.';
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
$conn = open_db_connection();

// ============================================================================
// STEP 3: Make sure this username and email are not already taken
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
    // MySQL's default utf8mb4_unicode_ci collation is case-insensitive.
    // Match that behavior here so duplicate usernames/emails get a clean 409
    // instead of falling through to a generic INSERT failure.
    if (strcasecmp((string) $row['username'], $username) === 0) {
        $checkStmt->close();
        $conn->close();
        send_json(['success' => false, 'error' => 'Username already taken.'], 409);
    }
    if (strcasecmp((string) $row['email'], $email) === 0) {
        $checkStmt->close();
        $conn->close();
        send_json(['success' => false, 'error' => 'Email already registered.'], 409);
    }
}

// Close the check statement
$checkStmt->close();

// ============================================================================
// STEP 4: Turn the plain-text password into a one-way hash before saving it
$hashedPassword = password_hash($password, PASSWORD_ARGON2ID);

// ============================================================================
// STEP 5: Create the one-time code the user must type on the verify page
// ============================================================================
// Load mailer functions
require_once(__DIR__ . '/mailer.php');

// Generate random 6-digit code (000000-999999)
$verificationCode = generate_verification_code();

// Calculate email verification code expiration (15 minutes from now)
$emailVerificationExpiresAt = time() + 900;

// ============================================================================
// STEP 6: Save the new user as unverified until the email code is confirmed
$insertStmt = $conn->prepare("
    INSERT INTO users (username, email, password, email_verified, email_verification_code, email_verification_code_expires_at) 
    VALUES (?, ?, ?, 0, ?, FROM_UNIXTIME(?))
");
if (!$insertStmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Registration is temporarily unavailable.'], 500);
}

// Bind parameters: s=string, i=integer
$insertStmt->bind_param("ssssi", $username, $email, $hashedPassword, $verificationCode, $emailVerificationExpiresAt);

// Execute the insert
if ($insertStmt->execute()) {
    // SUCCESS: User created successfully
    
    // Get the auto-generated user ID from the INSERT
    $userId = $insertStmt->insert_id;
    
    // ========================================================================
    // STEP 7: Send verification email
    // ========================================================================
    $emailSent = send_verification_email($email, $verificationCode);
    
    // ========================================================================
    // STEP 8: Log registration event
    // ========================================================================
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_security_log.txt';
    @file_put_contents($logFile, 
        date('Y-m-d H:i:s') . " | New user registered: {$userId} ({$username}, {$email}) | Verification email sent: " . ($emailSent ? 'YES' : 'NO') . " | IP: " . request_ip_address() . "\n",
        FILE_APPEND
    );

    // Registration does not sign the user in yet. The next page is email verification.
    $response = [
        'success' => true,
        'message' => $emailSent
            ? 'Registration successful! Please check your email to verify your account.'
            : 'Registration saved, but the verification email could not be sent. Configure mail and use Resend code on the verification page.',
        'user_id' => $userId,
        'email' => $email,
        'email_sent' => $emailSent,
        'requires_email_verification' => true,
        // URL to redirect to verification page
        'redirect' => '/register/verify'
    ];
} else {
    // FAILURE: Insert failed. A duplicate-key race can happen if two requests
    // submit the same username or email at nearly the same time.
    $isDuplicate = (int) $conn->errno === 1062 || (int) $insertStmt->errno === 1062;
    $response = [
        'success' => false,
        'error' => $isDuplicate ? 'Username or email already registered.' : 'Registration failed. Please try again later.'
    ];
    $statusCode = $isDuplicate ? 409 : 500;
}

// ============================================================================
// STEP 9: Clean up database connections
// ============================================================================
$insertStmt->close();
$conn->close();

// ============================================================================
// Return the JSON response with the matching HTTP status code.
send_json($response, $response['success'] ? 200 : ($statusCode ?? 500));
