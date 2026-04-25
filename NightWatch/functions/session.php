<?php
declare(strict_types=1);

require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$sessionUser = get_current_session_user();
if ($sessionUser === null) {
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

send_json([
    'success' => true,
    'username' => $sessionUser['username'],
    'email' => $sessionUser['email'],
    'expires_at' => iso8601_from_timestamp($sessionUser['expires_at']),
    'expires_at_ms' => $sessionUser['expires_at'] * 1000,
]);
