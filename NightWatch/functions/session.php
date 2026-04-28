<?php
// Enable strict type checking for function parameters and return types
declare(strict_types=1);

// ============================================================================
// SESSION.PHP - CHECK CURRENT SESSION STATUS
// ============================================================================
// Returns user session data if logged in, 401 error if not
// This endpoint allows frontend to verify login status

// Load common utility functions
require_once(__DIR__ . '/common.php');

// ============================================================================
// SECURITY: Only accept GET requests
// ============================================================================
// Session check is read-only operation (uses GET, not POST)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// ============================================================================
// Get current session user data
// ============================================================================
// Returns user data if session is valid and not expired, null otherwise
$sessionUser = get_current_session_user();

// ============================================================================
// Verify session exists
// ============================================================================
// If no valid session, send unauthorized error
if ($sessionUser === null) {
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

// ============================================================================
// Send session data to client
// ============================================================================
// Return user info and session expiration for frontend to display
send_json([
    'success' => true,
    // Username of logged-in user
    'username' => $sessionUser['username'],
    // Email of logged-in user
    'email' => $sessionUser['email'],
    // When session expires (ISO 8601 format)
    'expires_at' => iso8601_from_timestamp($sessionUser['expires_at']),
    // When session expires (milliseconds since epoch, for JavaScript)
    'expires_at_ms' => $sessionUser['expires_at'] * 1000,
]);
