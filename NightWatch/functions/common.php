<?php
// Strict types help PHP catch type mistakes earlier.
declare(strict_types=1);

// ============================================================================
// CONFIGURATION CONSTANTS
// ============================================================================

// This becomes the name of the session cookie stored in the browser.
const NIGHTWATCH_SESSION_NAME = 'nightwatch_session';

// A signed-in session lasts 600 seconds (10 minutes).
const NIGHTWATCH_SESSION_TTL = 600;

// ============================================================================
// INTERNAL FLAG FOR SECURITY
// ============================================================================
// Some helper files should only run after the main app has loaded them.
if (!defined('NIGHTWATCH_INTERNAL')) {
    define('NIGHTWATCH_INTERNAL', true);
}

// ============================================================================
// send_json() - Send JSON response to client with HTTP status code
// ============================================================================
// Most backend files answer with JSON so browser JavaScript can read the result.
function send_json(array $payload, int $status = 200): void
{
    // The status code tells the browser what kind of result this was.
    http_response_code($status);
    
    // Tell the browser that the body contains JSON text.
    header('Content-Type: application/json');

    // These headers add a few browser-side safety rules.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    
    // Auth responses should not be cached.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    // Older caches still understand this header.
    header('Pragma: no-cache');
    
    // Convert the PHP array into JSON and print it.
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    
    // Stop here so no extra output is appended after the JSON.
    exit;
}

// ============================================================================
// is_https_request() - Check if the current connection uses HTTPS (secure)
// ============================================================================
// We use this to decide whether the session cookie can be marked Secure.
function is_https_request(): bool
{
    // Common Apache-style HTTPS flag.
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    // Port 443 is the normal HTTPS port.
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    // Common reverse proxy header. Only trust this when the proxy is controlled.
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    // If none of the checks matched, treat the request as plain HTTP.
    return false;
}

// ============================================================================
// request_origin_is_same_site() - Validate Origin/Referer for POST requests
// ============================================================================
// SameSite cookies already help with CSRF, but checking Origin or Referer gives
// the server one more way to reject cross-site POST requests.
function request_origin_is_same_site(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return true;
    }

    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') {
        return true;
    }

    $sourceHost = parse_url((string) $source, PHP_URL_HOST);
    if (!is_string($sourceHost) || $sourceHost === '') {
        return false;
    }

    $requestHost = parse_url('http://' . $host, PHP_URL_HOST);
    if (!is_string($requestHost) || $requestHost === '') {
        return false;
    }

    return hash_equals(strtolower($requestHost), strtolower($sourceHost));
}

// ============================================================================
// enforce_same_origin_post() - Reject POST requests coming from another site
// ============================================================================
function enforce_same_origin_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    if (!request_origin_is_same_site()) {
        send_json(['success' => false, 'error' => 'Invalid request origin.'], 403);
    }
}

// ============================================================================
// start_app_session() - Initialize or resume the application session
// ============================================================================
// Start a new session or reopen the current one for this browser.
function start_app_session(): void
{
    // Do nothing if PHP already opened the session for this request.
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Use our own cookie name instead of PHPSESSID.
    session_name(NIGHTWATCH_SESSION_NAME);
    
    // These values control how the browser stores and sends the session cookie.
    session_set_cookie_params([
        // 0 means "until the browser closes".
        'lifetime' => 0,
        // Send the cookie to the whole site.
        'path' => '/',
        // Only send this cookie over HTTPS when HTTPS is in use.
        'secure' => is_https_request(),
        // JavaScript cannot read HttpOnly cookies.
        'httponly' => true,
        // Lax blocks many cross-site POST cases while keeping normal navigation working.
        'samesite' => 'Lax',
    ]);

    // Start the PHP session
    session_start();
}

// ============================================================================
// destroy_app_session() - Completely remove user session
// ============================================================================
// Logging out means removing both the server-side session and the browser cookie.
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
// Called after login or after email verification succeeds.
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
// Returns the signed-in user, or null when no valid session exists.
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
// Protected endpoints call this helper instead of repeating the same auth check.
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
// ISO 8601 is a standard timestamp format that JavaScript can parse easily.
function iso8601_from_timestamp(int $timestamp): string
{
    // gmdate with 'c' format converts to ISO 8601 format
    return gmdate('c', $timestamp);
}

// ============================================================================
// require_db_config_file() - Load database configuration file
// ============================================================================
// Check the production config path first, then the local project copy.
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
// The IP is used for simple rate limiting and security logs.
function request_ip_address(): string
{
    // $_SERVER['REMOTE_ADDR'] contains client's IP address
    // Return 'unknown' if IP cannot be determined (use ?: for empty string check)
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// ============================================================================
// rate_limit_storage_dir() - Get directory for storing rate limit data
// ============================================================================
// Rate limiting is file-based, so we need a temp folder to store attempt lists.
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
// Example: login may allow 8 attempts in a 5 minute window.
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
