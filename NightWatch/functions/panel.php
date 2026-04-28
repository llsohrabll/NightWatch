<?php
// ============================================================================
// PANEL.PHP - USER DASHBOARD ENDPOINT
// ============================================================================
// Returns user data and system statistics for the dashboard
// Requires valid session to access

// Load common utilities and database config
require_once(__DIR__ . '/common.php');
require_db_config_file();

// ============================================================================
// Helper function: getServerUptimeSeconds()
// ============================================================================
// Retrieves how long the server has been running
// Returns seconds of uptime, or null if unavailable
function getServerUptimeSeconds() {
    // Try Linux method first (/proc/uptime exists on Linux systems)
    $uptimeRaw = @file_get_contents('/proc/uptime');
    if ($uptimeRaw !== false) {
        // /proc/uptime contains "uptime idle_time", get first number
        $parts = preg_split('/\s+/', trim($uptimeRaw));
        if (isset($parts[0]) && is_numeric($parts[0])) {
            // Convert to integer seconds
            return (int) floor((float) $parts[0]);
        }
    }

    // Try Windows method if on Windows OS
    if (PHP_OS_FAMILY === 'Windows') {
        // Method 1: Use wmic (Windows Management Instrumentation Command-line)
        if (function_exists('shell_exec')) {
            // First try wmic with timeout
            $lastBootRaw = @shell_exec('wmic os get lastbootuptime /value 2>nul');
            if (is_string($lastBootRaw) && !empty($lastBootRaw)) {
                // Example output: LastBootUpTime=20260427153000.000000+000
                if (preg_match('/LastBootUpTime=([0-9]{14})/', $lastBootRaw, $matches)) {
                    // Parse timestamp: YYYYMMDDHHmmss format
                    $bootTime = DateTime::createFromFormat('YmdHis', $matches[1], new DateTimeZone('UTC'));
                    if ($bootTime instanceof DateTime) {
                        // Calculate seconds since boot
                        return max(0, time() - $bootTime->getTimestamp());
                    }
                }
            }
        }
        
        // Method 2: Use systeminfo command as fallback
        if (function_exists('shell_exec')) {
            $systemInfoRaw = @shell_exec('systeminfo 2>nul | find "System Boot Time"');
            if (is_string($systemInfoRaw) && !empty($systemInfoRaw)) {
                // Extract date and time, parse it, and calculate uptime
                // Example: "System Boot Time:        4/27/2026, 3:30:00 PM"
                if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4}),\s+(\d{1,2}:\d{2}:\d{2}\s+[AP]M)/i', $systemInfoRaw, $matches)) {
                    $dateStr = $matches[1] . ' ' . $matches[2];
                    try {
                        $bootTime = DateTime::createFromFormat('m/d/Y g:i:s A', $dateStr, new DateTimeZone('UTC'));
                        if ($bootTime instanceof DateTime) {
                            return max(0, time() - $bootTime->getTimestamp());
                        }
                    } catch (Exception $e) {
                        // Parse error, fall through
                    }
                }
            }
        }
    }

    // Uptime not available
    return null;
}

// ============================================================================
// SECURITY: Require authenticated session
// ============================================================================
// Check if user is logged in - if not, send 401 error and stop
$sessionUser = require_authenticated_session();

// ============================================================================
// Connect to database
// ============================================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
}

// ============================================================================
// Fetch current user details from database
// ============================================================================
// Use prepared statement to safely query user data
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Panel is temporarily unavailable.'], 500);
}

// Bind user ID from session
$stmt->bind_param("i", $sessionUser['user_id']);

// Execute query
$stmt->execute();

// Get result
$result = $stmt->get_result();

// Fetch user data
$user = $result->fetch_assoc();

// Check if user still exists (might have been deleted)
if (!$user) {
    $stmt->close();
    $conn->close();
    // User deleted - destroy session and force logout
    destroy_app_session();
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

// Close statement
$stmt->close();

// ============================================================================
// Count total registered users
// ============================================================================
// Get count of all users in database
$totalUsers = 0;
$countResult = $conn->query("SELECT COUNT(*) AS total_users FROM users");
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    // Get the count or default to 0
    $totalUsers = (int) ($countRow['total_users'] ?? 0);
    $countResult->free();
}

// ============================================================================
// Get server uptime
// ============================================================================
$serverUptimeSeconds = getServerUptimeSeconds();

// ============================================================================
// Build response with dashboard data
// ============================================================================
$response = [
    'success' => true,
    // Current user info
    'username' => $user['username'],
    'email' => $user['email'],
    // Session expiration time in ISO 8601 format
    'expires_at' => iso8601_from_timestamp($sessionUser['expires_at']),
    // Session expiration in milliseconds (for JavaScript countdown)
    'expires_at_ms' => $sessionUser['expires_at'] * 1000,
    // Total registered users in the system
    'registered_users' => $totalUsers,
    // User honor level (placeholder for future feature)
    'honor_level' => 0,
    // Server uptime in seconds
    'server_uptime_seconds' => $serverUptimeSeconds
];

// ============================================================================
// Clean up and send response
// ============================================================================
$conn->close();
send_json($response);
