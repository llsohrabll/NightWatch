<?php
// Enable strict type checking for function parameters and return types
declare(strict_types=1);

// ============================================================================
// SESSION.PHP - TELL THE BROWSER WHETHER IT IS STILL SIGNED IN
// ============================================================================
// The frontend calls this when a page loads or when it needs fresh session data.

// Load common utility functions
require_once(__DIR__ . '/common.php');

// ============================================================================
// This endpoint only reads data, so GET is the correct method.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// ============================================================================
// This helper returns null if the session is missing or timed out.
$sessionUser = get_current_session_user();

// ============================================================================
// 401 tells the frontend that the user must sign in again.
if ($sessionUser === null) {
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

// ============================================================================
// Return the small user object the frontend needs.
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
