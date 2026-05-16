<?php
declare(strict_types=1);

require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}
enforce_same_origin_post();

start_app_session();
$userId = $_SESSION['user_id'] ?? null;
$rawToken = current_raw_auth_token();

if (is_numeric($userId) && $rawToken !== null) {
    require_db_config_file();

// Set security headers for this JSON endpoint.
set_security_headers();

// Require HTTPS outside localhost/development.
enforce_https();
    $tokenHash = hash('sha256', $rawToken);
    $conn = open_db_connection();
    $stmt = $conn->prepare('UPDATE users SET token = NULL, token_expires_at = NULL WHERE id = ? AND token = ?');
    if ($stmt) {
        $normalizedUserId = (int) $userId;
        $stmt->bind_param('is', $normalizedUserId, $tokenHash);
        $stmt->execute();
        $stmt->close();
    }
    $conn->close();
}

destroy_app_session();
send_json(['success' => true]);
