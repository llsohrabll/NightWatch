<?php
// ============================================================================
// CSRF.PHP - ISSUE A SAME-ORIGIN CSRF TOKEN FOR STATE-CHANGING REQUESTS
// ============================================================================

declare(strict_types=1);

require_once(__DIR__ . '/common.php');

set_security_headers();
enforce_https();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

send_json([
    'success' => true,
    'csrf_token' => csrf_session_token(),
]);
