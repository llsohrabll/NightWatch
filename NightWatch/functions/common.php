<?php
// Enable strict types to ensure proper data type checking throughout the file
declare(strict_types=1);

// ============================================================================
// CONFIGURATION CONSTANTS
// ============================================================================

// Define the session name for this application (different from default PHP session name)
const NIGHTWATCH_SESSION_NAME = 'nightwatch_session';

// Define how long a session lasts in seconds (600 seconds = 10 minutes)
// After this time, user will be logged out automatically
const NIGHTWATCH_SESSION_TTL = 600;

// ============================================================================
// send_json() - Send JSON response to client with HTTP status code
// ============================================================================
// This function handles all JSON responses from the server
// It ensures consistent response format and proper HTTP headers
function send_json(array $payload, int $status = 200): void
{
    // Set the HTTP response status code (200=success, 401=unauthorized, 429=too many requests, etc)
    http_response_code($status);
    
    // Tell browser that response is JSON format
    header('Content-Type: application/json');
    
    // Prevent browser caching to ensure fresh data
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    // Prevent proxy caching (older standard)
    header('Pragma: no-cache');
    
    // Convert PHP array to JSON string and send to client
    echo json_encode($payload);
    
    // Stop script execution after sending response
    exit;
}

// ============================================================================
// is_https_request() - Check if the current connection uses HTTPS (secure)
// ============================================================================
// Returns true if connection is secure (HTTPS), false otherwise
// Used to set secure cookie flags properly
function is_https_request(): bool
{
    // Check if HTTPS environment variable is set and not 'off'
    // This works for Apache servers
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    // Check if server port is 443 (standard HTTPS port)
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    // If none of the above, connection is HTTP (not secure)
    return false;
}

// ============================================================================
// start_app_session() - Initialize or resume the application session
// ============================================================================
// Called at the start of any operation that needs session data
// Handles session creation with security settings
function start_app_session(): void
{
    // Check if a session is already active (avoid starting multiple times)
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Set custom session name (not 'PHPSESSID')
    session_name(NIGHTWATCH_SESSION_NAME);
    
    // Configure session cookie security settings
    session_set_cookie_params([
        // 'lifetime': 0 means cookie dies when browser closes
        'lifetime' => 0,
        // 'path': Only send cookie for requests in root path '/'
        'path' => '/',
        // 'secure': Only send cookie over HTTPS connections
        'secure' => is_https_request(),
        // 'httponly': Prevent JavaScript from accessing the cookie (prevents theft)
        'httponly' => true,
        // 'samesite': Prevent cross-site request forgery attacks
        'samesite' => 'Lax',
    ]);

    // Start the PHP session
    session_start();
}

// ============================================================================
// destroy_app_session() - Completely remove user session
// ============================================================================
// Called during logout to clear all session data
function destroy_app_session(): void
{
    // Start session if not already active
    start_app_session();

    // Clear all session variables
    $_SESSION = [];

    // If cookies are enabled, also delete the session cookie from client
    if (ini_get('session.use_cookies')) {
        // Get current cookie settings to use for deletion
        $params = session_get_cookie_params();
        
        // Create an empty cookie with expiration time in past (tells browser to delete it)
        setcookie(session_name(), '', [
            'expires' => time() - (7 * 24 * 60 * 60),  // Set to 1 week in past to ensure deletion
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    // Destroy the session on server side
    session_destroy();
}

// ============================================================================
// create_user_session() - Create a new session for an authenticated user
// ============================================================================
// Called after successful login or registration
// Stores user data in session with expiration time
function create_user_session(array $user, int $ttlSeconds = NIGHTWATCH_SESSION_TTL): int
{
    // Ensure session is running
    start_app_session();
    
    // Generate new session ID to prevent session fixation attacks
    // true = delete old session file
    session_regenerate_id(true);

    // Calculate when this session should expire (current time + TTL seconds)
    $expiresAt = time() + $ttlSeconds;
    
    // Store user information in session variables
    // These will be available across page loads via $_SESSION
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['expires_at'] = $expiresAt;

    // Return expiration timestamp so client can show countdown
    return $expiresAt;
}

// ============================================================================
// get_current_session_user() - Retrieve current logged-in user from session
// ============================================================================
// Returns user data if valid session exists, null otherwise
function get_current_session_user(): ?array
{
    // Ensure session is started
    start_app_session();

    // Get user ID from session (null if not set)
    $userId = $_SESSION['user_id'] ?? null;
    
    // Get expiration time from session (null if not set)
    $expiresAt = $_SESSION['expires_at'] ?? null;

    // If either is missing or not numeric, session is invalid
    if (!is_numeric($userId) || !is_numeric($expiresAt)) {
        return null;
    }

    // Convert expiration time to integer
    $normalizedExpiry = (int) $expiresAt;
    
    // Check if current time has passed expiration time
    if (time() >= $normalizedExpiry) {
        // Session expired - destroy it and return null
        destroy_app_session();
        return null;
    }

    // Session is valid - return user data
    return [
        'user_id' => (int) $userId,
        'username' => (string) ($_SESSION['username'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'expires_at' => $normalizedExpiry,
    ];
}

// ============================================================================
// require_authenticated_session() - Verify user is logged in or send error
// ============================================================================
// Used at start of protected pages (like panel.php)
// Sends 401 error and exits if user not authenticated
function require_authenticated_session(): array
{
    // Try to get current session user
    $sessionUser = get_current_session_user();
    
    // If no valid session, send error response and stop
    if ($sessionUser === null) {
        send_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    // Return user data if authenticated
    return $sessionUser;
}

// ============================================================================
// iso8601_from_timestamp() - Convert Unix timestamp to ISO 8601 format
// ============================================================================
// ISO 8601 is standard date format (example: 2026-04-27T15:30:00Z)
// Used for timestamps in JSON responses
function iso8601_from_timestamp(int $timestamp): string
{
    // gmdate with 'c' format converts to ISO 8601 format
    return gmdate('c', $timestamp);
}

// ============================================================================
// require_db_config_file() - Load database configuration file
// ============================================================================
// Looks for db_config.php in standard locations
// Sets up DB_HOST, DB_USER, DB_PASSWORD, DB_NAME constants
function require_db_config_file(): void
{
    // Array of possible locations where db_config.php might be
    $candidates = [
        // Production server location
        '/var/www/config/db_config.php',
        // Development location (relative to project root)
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
    ];

    // Try each location until config file is found
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            // Load the config file (sets DB_HOST, DB_USER, etc constants)
            require_once($candidate);
            return;
        }
    }

    // If no config file found, send error and stop
    send_json(['success' => false, 'error' => 'Database configuration not found.'], 500);
}

// ============================================================================
// request_ip_address() - Get the IP address of the current request
// ============================================================================
// Used for logging and rate limiting by IP address
function request_ip_address(): string
{
    // $_SERVER['REMOTE_ADDR'] contains client's IP address
    // Return 'unknown' if IP cannot be determined (use ?: for empty string check)
    return $_SERVER['REMOTE_ADDR'] ?: 'unknown';
}

// ============================================================================
// rate_limit_storage_dir() - Get directory for storing rate limit data
// ============================================================================
// Creates temporary directory for tracking login/register attempts
function rate_limit_storage_dir(): string
{
    // Build path to temporary directory
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_rate_limits';
    
    // If directory doesn't exist, create it with restricted permissions (0700 = user only)
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    // Return the directory path for rate limit files
    return $directory;
}

// ============================================================================
// enforce_rate_limit() - Prevent brute force attacks by limiting attempts
// ============================================================================
// Allows only X attempts per user per Y seconds
// Example: max 8 login attempts per 300 seconds (5 minutes)
function enforce_rate_limit(string $scope, int $maxAttempts, int $windowSeconds): void
{
    // Create unique filename based on scope (login/register) and user's IP
    // This prevents one user from blocking another user's attempts
    $fileKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope) . '_' . sha1($scope . '|' . request_ip_address());
    
    // Full path to file that stores this user's attempt history
    $filePath = rate_limit_storage_dir() . DIRECTORY_SEPARATOR . $fileKey . '.json';
    
    // Current time in seconds since Unix epoch
    $now = time();
    
    // Calculate start of current time window
    // Example: if window is 300 seconds, remove last 300 seconds of attempts
    $windowStart = $now - $windowSeconds;
    
    // Array to store valid attempts (within current window)
    $attempts = [];

    // If file exists, read previous attempts
    if (is_file($filePath)) {
        // Read file contents and decode JSON
        $decoded = json_decode((string) file_get_contents($filePath), true);
        
        // If decoded successfully, filter out old attempts
        if (is_array($decoded)) {
            // Keep only attempts within the current time window
            foreach ($decoded as $timestamp) {
                // Check if timestamp is numeric and within window
                if (is_numeric($timestamp) && (int) $timestamp >= $windowStart) {
                    $attempts[] = (int) $timestamp;
                }
            }
        }
    }

    // Check if user has exceeded maximum attempts
    if (count($attempts) >= $maxAttempts) {
        // Calculate how long user must wait before trying again
        // retryAfter = window time - time since earliest attempt
        $retryAfter = max(1, $windowSeconds - ($now - min($attempts)));
        
        // Tell client how long to wait before retrying
        header('Retry-After: ' . $retryAfter);
        
        // Send error response and stop execution
        send_json([
            'success' => false,
            'error' => 'Too many attempts. Please wait and try again.',
        ], 429);  // HTTP 429 = Too Many Requests
    }

    // Record this attempt by adding current timestamp
    $attempts[] = $now;
    
    // Save updated attempts list to file with exclusive lock
    // @ suppresses errors if write fails (not critical)
    @file_put_contents($filePath, json_encode($attempts), LOCK_EX);
}
