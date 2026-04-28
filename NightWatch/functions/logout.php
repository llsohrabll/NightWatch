<?php
// Enable strict type checking
declare(strict_types=1);

// ============================================================================
// LOGOUT.PHP - HANDLE USER LOGOUT
// ============================================================================
// Destroys user session when they click logout button

// Load common utility functions
require_once(__DIR__ . '/common.php');

// ============================================================================
// SECURITY: Only accept POST requests
// ============================================================================
// Logout should use POST to prevent accidental logout via URL
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// ============================================================================
// Destroy session and clear all user data
// ============================================================================
// This clears:
// - All session variables ($_SESSION)
// - Session cookie from client
// - Session file on server
destroy_app_session();

// ============================================================================
// Send success response
// ============================================================================
// Confirm logout completed successfully
send_json(['success' => true]);
