<?php
// Enable strict type checking
declare(strict_types=1);

// ============================================================================
// LOGOUT.PHP - SIGN THE CURRENT BROWSER OUT
// ============================================================================
// Logging out means deleting the current session on both server and browser.

// Load common utility functions
require_once(__DIR__ . '/common.php');

// ============================================================================
// A logout action changes auth state, so it should not run through a simple link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

// ============================================================================
// One helper clears the session array, expires the cookie, and destroys the session.
destroy_app_session();

// ============================================================================
// The frontend does not need extra data here.
send_json(['success' => true]);
