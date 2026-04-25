<?php
declare(strict_types=1);

const NIGHTWATCH_SESSION_NAME = 'nightwatch_session';
const NIGHTWATCH_SESSION_TTL = 600;

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload);
    exit;
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    return false;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(NIGHTWATCH_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function destroy_app_session(): void
{
    start_app_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function create_user_session(array $user, int $ttlSeconds = NIGHTWATCH_SESSION_TTL): int
{
    start_app_session();
    session_regenerate_id(true);

    $expiresAt = time() + $ttlSeconds;
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['expires_at'] = $expiresAt;

    return $expiresAt;
}

function get_current_session_user(): ?array
{
    start_app_session();

    $userId = $_SESSION['user_id'] ?? null;
    $expiresAt = $_SESSION['expires_at'] ?? null;

    if (!is_numeric($userId) || !is_numeric($expiresAt)) {
        return null;
    }

    $normalizedExpiry = (int) $expiresAt;
    if (time() >= $normalizedExpiry) {
        destroy_app_session();
        return null;
    }

    return [
        'user_id' => (int) $userId,
        'username' => (string) ($_SESSION['username'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'expires_at' => $normalizedExpiry,
    ];
}

function require_authenticated_session(): array
{
    $sessionUser = get_current_session_user();
    if ($sessionUser === null) {
        send_json(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    return $sessionUser;
}

function iso8601_from_timestamp(int $timestamp): string
{
    return gmdate('c', $timestamp);
}

function require_db_config_file(): void
{
    $candidates = [
        '/var/www/config/db_config.php',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require_once($candidate);
            return;
        }
    }

    send_json(['success' => false, 'error' => 'Database configuration not found.'], 500);
}

function request_ip_address(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limit_storage_dir(): string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_rate_limits';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    return $directory;
}

function enforce_rate_limit(string $scope, int $maxAttempts, int $windowSeconds): void
{
    $fileKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope) . '_' . sha1($scope . '|' . request_ip_address());
    $filePath = rate_limit_storage_dir() . DIRECTORY_SEPARATOR . $fileKey . '.json';
    $now = time();
    $windowStart = $now - $windowSeconds;
    $attempts = [];

    if (is_file($filePath)) {
        $decoded = json_decode((string) file_get_contents($filePath), true);
        if (is_array($decoded)) {
            foreach ($decoded as $timestamp) {
                if (is_numeric($timestamp) && (int) $timestamp >= $windowStart) {
                    $attempts[] = (int) $timestamp;
                }
            }
        }
    }

    if (count($attempts) >= $maxAttempts) {
        $retryAfter = max(1, $windowSeconds - ($now - min($attempts)));
        header('Retry-After: ' . $retryAfter);
        send_json([
            'success' => false,
            'error' => 'Too many attempts. Please wait and try again.',
        ], 429);
    }

    $attempts[] = $now;
    @file_put_contents($filePath, json_encode($attempts), LOCK_EX);
}
