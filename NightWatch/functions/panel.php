<?php
require_once(__DIR__ . '/common.php');
require_db_config_file();

function getServerUptimeSeconds() {
    $uptimeRaw = @file_get_contents('/proc/uptime');
    if ($uptimeRaw !== false) {
        $parts = preg_split('/\s+/', trim($uptimeRaw));
        if (isset($parts[0]) && is_numeric($parts[0])) {
            return (int) floor((float) $parts[0]);
        }
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $lastBootRaw = @shell_exec('wmic os get lastbootuptime /value');
        if (is_string($lastBootRaw) && preg_match('/LastBootUpTime=([0-9]{14})/', $lastBootRaw, $matches)) {
            $bootTime = DateTime::createFromFormat('YmdHis', $matches[1], new DateTimeZone('UTC'));
            if ($bootTime instanceof DateTime) {
                return max(0, time() - $bootTime->getTimestamp());
            }
        }
    }

    return null;
}

$sessionUser = require_authenticated_session();

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    send_json(['success' => false, 'error' => 'DB error'], 500);
}

$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
if (!$stmt) {
    $conn->close();
    send_json(['success' => false, 'error' => 'Panel is temporarily unavailable.'], 500);
}

$stmt->bind_param("i", $sessionUser['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    $stmt->close();
    $conn->close();
    destroy_app_session();
    send_json(['success' => false, 'error' => 'Unauthorized'], 401);
}

$stmt->close();

$totalUsers = 0;
$countResult = $conn->query("SELECT COUNT(*) AS total_users FROM users");
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $totalUsers = (int) ($countRow['total_users'] ?? 0);
    $countResult->free();
}

$serverUptimeSeconds = getServerUptimeSeconds();

$response = [
    'success' => true,
    'username' => $user['username'],
    'email' => $user['email'],
    'expires_at' => iso8601_from_timestamp($sessionUser['expires_at']),
    'expires_at_ms' => $sessionUser['expires_at'] * 1000,
    'registered_users' => $totalUsers,
    'honor_level' => 0,
    'server_uptime_seconds' => $serverUptimeSeconds
];

$conn->close();
send_json($response);
