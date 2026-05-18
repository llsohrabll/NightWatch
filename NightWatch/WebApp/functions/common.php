<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = realpath((string) $_SERVER['SCRIPT_FILENAME']);
    $currentFilename = realpath(__FILE__);
    if ($scriptFilename !== false && $currentFilename !== false && $scriptFilename === $currentFilename) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access Denied');
    }
}

const NIGHTWATCH_SESSION_NAME = 'nightwatch_session';
const NIGHTWATCH_TOKEN_COOKIE = 'nightwatch_token';
const NIGHTWATCH_SESSION_TTL = 600;
const NIGHTWATCH_REMEMBER_TTL = 604800;
const NIGHTWATCH_DEFAULT_PROFILE_PHOTO = 'assets/nightwatch_symbol.png';
const NIGHTWATCH_PROFILE_PHOTO_QUERY = 'functions/photo.php?file=';

if (!defined('NIGHTWATCH_INTERNAL')) {
    define('NIGHTWATCH_INTERNAL', true);
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json !== false ? $json : '{"success":false,"error":"Response encoding failed."}';
    exit;
}

function is_valid_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function strip_null_bytes(string $value): string
{
    return str_replace("\0", '', $value);
}


/**
 * Return a SHA-256 hash of a short one-time code before storing it.
 * Plain 6-digit codes are weak if the database leaks, so the app stores hashes only.
 */
function nightwatch_code_hash(string $code): string
{
    return hash('sha256', $code);
}

/**
 * Compare a user-entered one-time code with the stored value.
 * The fallback accepts old plaintext 6-digit rows so existing installs keep working after migration.
 */
function nightwatch_verify_one_time_code(string $storedValue, string $submittedCode): bool
{
    if (preg_match('/\A\d{6}\z/', $storedValue) === 1) {
        return hash_equals($storedValue, $submittedCode);
    }

    return hash_equals($storedValue, nightwatch_code_hash($submittedCode));
}

function set_security_headers(): void
{
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
}

function is_local_development_request(): bool
{
    $host = (string) ($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? ''));
    $host = strtolower(trim(preg_replace('/:\d+$/', '', $host) ?? $host, '[]'));

    return $host === ''
        || $host === 'localhost'
        || $host === '::1'
        || str_starts_with($host, '127.');
}

function insecure_defaults_explicitly_allowed(): bool
{
    return in_array(strtolower((string) (getenv('NIGHTWATCH_ALLOW_INSECURE_DEFAULTS') ?: '0')), ['1', 'true', 'yes', 'on'], true);
}

function enforce_https(): void
{
    // Skip enforcement for localhost/development.
    if (is_local_development_request()) {
        return;
    }

    // Enforce HTTPS on production.
    if (!is_https_request()) {
        send_json(['success' => false, 'error' => 'HTTPS is required for this endpoint'], 403);
    }
}

function default_profile_photo_path(): string
{
    return NIGHTWATCH_DEFAULT_PROFILE_PHOTO;
}

function safe_profile_photo_filename(?string $path): ?string
{
    if ($path === null) return null;

    $normalized = trim(strip_null_bytes($path));
    if ($normalized === '') return null;

    $normalized = str_replace('\\', '/', $normalized);
    $normalized = ltrim($normalized, '/');

    $decoded = rawurldecode($normalized);
    if (is_string($decoded)) {
        $normalized = str_replace('\\', '/', ltrim($decoded, '/'));
    }

    if (strpos($normalized, '..') !== false || strpos($normalized, '//') !== false) {
        return null;
    }

    if (str_starts_with($normalized, NIGHTWATCH_PROFILE_PHOTO_QUERY)) {
        $query = parse_url('/' . $normalized, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);
        $normalized = 'profile_photos/' . (string) ($params['file'] ?? '');
    }

    if (preg_match('#\A(?:profile_photos|uploads/photos)/([A-Za-z0-9._-]+\.(?:jpe?g|png))\z#i', $normalized, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}


function profile_photo_public_base_url(): ?string
{
    $configured = getenv('NIGHTWATCH_PHOTO_PUBLIC_BASE_URL');
    if ($configured === false || trim($configured) === '') {
        return null;
    }

    $baseUrl = rtrim(trim((string) $configured), '/');
    $parts = parse_url($baseUrl);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }

    $allowHttp = in_array(strtolower((string) (getenv('NIGHTWATCH_ALLOW_INSECURE_PHOTO_BASE_URL') ?: '0')), ['1', 'true', 'yes', 'on'], true);
    if ($scheme !== 'https' && !($allowHttp && $scheme === 'http' && is_local_development_request())) {
        return null;
    }

    return $baseUrl;
}

function profile_photo_public_path_from_filename(string $filename): string
{
    if (preg_match('#\A[A-Za-z0-9._-]+\.(?:jpe?g|png)\z#i', $filename) !== 1) {
        return NIGHTWATCH_DEFAULT_PROFILE_PHOTO;
    }

    $baseUrl = profile_photo_public_base_url();
    if ($baseUrl !== null) {
        return $baseUrl . '/' . rawurlencode($filename);
    }

    return NIGHTWATCH_PROFILE_PHOTO_QUERY . rawurlencode($filename);
}

function profile_photo_storage_directory(): string
{
    $configured = getenv('NIGHTWATCH_PHOTO_STORAGE_DIR');
    if ($configured !== false && trim($configured) !== '') {
        return rtrim((string) $configured, DIRECTORY_SEPARATOR);
    }

    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'photos';
}

function safe_public_photo_path(?string $path): string
{
    if ($path === null || trim(strip_null_bytes($path)) === '') {
        return NIGHTWATCH_DEFAULT_PROFILE_PHOTO;
    }

    $normalized = str_replace('\\', '/', ltrim(trim(strip_null_bytes($path)), '/'));
    if ($normalized === NIGHTWATCH_DEFAULT_PROFILE_PHOTO) {
        return NIGHTWATCH_DEFAULT_PROFILE_PHOTO;
    }

    $filename = safe_profile_photo_filename($normalized);
    if ($filename !== null) {
        return profile_photo_public_path_from_filename($filename);
    }

    return NIGHTWATCH_DEFAULT_PROFILE_PHOTO;
}

function secure_join_path(string $baseDir, string $filename): ?string
{
    if (preg_match('#\A[A-Za-z0-9._-]+\z#', $filename) !== 1) {
        return null;
    }

    $baseReal = realpath($baseDir);
    if ($baseReal === false) return null;

    $candidate = $baseReal . DIRECTORY_SEPARATOR . $filename;
    $candidateReal = realpath($candidate);
    if ($candidateReal === false) return null;

    if (!str_starts_with($candidateReal, $baseReal . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $candidateReal;
}

function is_request_from_trusted_proxy(): bool
{
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        return false;
    }

    $trustedProxyList = getenv('NIGHTWATCH_TRUSTED_PROXIES') ?: '';
    $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxyList)));

    return in_array($remoteAddress, $trustedProxies, true);
}

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) return true;

    // X-Forwarded-Proto is client-controlled unless the direct peer is a trusted reverse proxy.
    if (is_request_from_trusted_proxy()) {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto !== '') {
            $firstProto = strtolower(trim(explode(',', $forwardedProto)[0]));
            return $firstProto === 'https';
        }
    }

    return false;
}

function request_origin_is_same_site(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return true;

    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') return true;

    $sourceParts = parse_url((string) $source);
    if (!is_array($sourceParts)) return false;

    $requestScheme = is_https_request() ? 'https' : 'http';
    $requestParts = parse_url($requestScheme . '://' . $host);
    if (!is_array($requestParts)) return false;

    $sourceHost = $sourceParts['host'] ?? '';
    $requestHost = $requestParts['host'] ?? '';
    $sourceScheme = $sourceParts['scheme'] ?? '';

    if (!is_string($sourceHost) || !is_string($requestHost) || !is_string($sourceScheme)) return false;

    $sourcePort = (int) ($sourceParts['port'] ?? ($sourceScheme === 'https' ? 443 : 80));
    $requestPort = (int) ($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));

    return hash_equals(strtolower($requestHost), strtolower($sourceHost))
        && hash_equals(strtolower($requestScheme), strtolower($sourceScheme))
        && $requestPort === $sourcePort;
}

function enforce_same_origin_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (!request_origin_is_same_site()) {
        send_json(['success' => false, 'error' => 'Invalid request origin.'], 403);
    }
}

function csrf_session_token(): string
{
    start_app_session();

    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[A-Fa-f0-9]{64}\z/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function request_csrf_token(): string
{
    $headerValue = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($headerValue !== '') return $headerValue;

    return (string) ($_POST['csrf_token'] ?? '');
}

function enforce_csrf_token(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    start_app_session();
    $expected = $_SESSION['csrf_token'] ?? null;
    $submitted = request_csrf_token();

    if (!is_string($expected) || !preg_match('/\A[A-Fa-f0-9]{64}\z/', $expected)) {
        send_json(['success' => false, 'error' => 'CSRF token is missing. Refresh the page and try again.'], 403);
    }

    if (!is_string($submitted) || !hash_equals($expected, $submitted)) {
        send_json(['success' => false, 'error' => 'Invalid CSRF token. Refresh the page and try again.'], 403);
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(NIGHTWATCH_SESSION_NAME);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.sid_length', '48');
    @ini_set('session.sid_bits_per_character', '6');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function auth_cookie_options(int $expiresAt): array
{
    return [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function set_auth_token_cookie(string $token, int $expiresAt): void
{
    setcookie(NIGHTWATCH_TOKEN_COOKIE, $token, auth_cookie_options($expiresAt));
    $_COOKIE[NIGHTWATCH_TOKEN_COOKIE] = $token;
}

function clear_auth_token_cookie(): void
{
    setcookie(NIGHTWATCH_TOKEN_COOKIE, '', auth_cookie_options(time() - 3600));
    unset($_COOKIE[NIGHTWATCH_TOKEN_COOKIE]);
}

function destroy_app_session(): void
{
    start_app_session();
    $_SESSION = [];
    clear_auth_token_cookie();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 604800,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function create_user_session(array $user, int $expiresAt, string $tokenHash): void
{
    start_app_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['is_admin'] = !empty($user['is_admin']);
    $_SESSION['expires_at'] = $expiresAt;
    $_SESSION['token_hash'] = $tokenHash;
}

function issue_user_token(mysqli $conn, array $user, int $ttlSeconds): int
{
    $ttlSeconds = max(60, min($ttlSeconds, NIGHTWATCH_REMEMBER_TTL));
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = time() + $ttlSeconds;
    $userId = (int) $user['id'];

    $stmt = $conn->prepare('UPDATE users SET token = ?, token_expires_at = FROM_UNIXTIME(?) WHERE id = ?');
    if (!$stmt) {
        send_json(['success' => false, 'error' => 'Authentication is temporarily unavailable.'], 500);
    }

    $stmt->bind_param('sii', $tokenHash, $expiresAt, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        send_json(['success' => false, 'error' => 'Authentication is temporarily unavailable.'], 500);
    }
    $stmt->close();

    create_user_session($user, $expiresAt, $tokenHash);
    set_auth_token_cookie($rawToken, $expiresAt);

    return $expiresAt;
}

function current_raw_auth_token(): ?string
{
    $token = (string) ($_COOKIE[NIGHTWATCH_TOKEN_COOKIE] ?? '');
    if (!preg_match('/\A[A-Fa-f0-9]{64}\z/', $token)) return null;
    return $token;
}

function get_current_session_user(): ?array
{
    start_app_session();

    $rawToken = current_raw_auth_token();
    if ($rawToken === null) {
        destroy_app_session();
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $sessionUserId = $_SESSION['user_id'] ?? null;
    $sessionTokenHash = (string) ($_SESSION['token_hash'] ?? '');

    if ($sessionTokenHash !== '' && !hash_equals($sessionTokenHash, $tokenHash)) {
        destroy_app_session();
        return null;
    }

    require_db_config_file();
    $conn = open_db_connection();

    if (is_numeric($sessionUserId)) {
        $stmt = $conn->prepare(
            "SELECT id, username, email, is_admin, token_expires_at FROM users WHERE id = ? AND token = ? AND token_expires_at > NOW() AND email_verified = 1 LIMIT 1"
        );
        if (!$stmt) {
            $conn->close();
            destroy_app_session();
            return null;
        }
        $userId = (int) $sessionUserId;
        $stmt->bind_param('is', $userId, $tokenHash);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, email, is_admin, token_expires_at FROM users WHERE token = ? AND token_expires_at > NOW() AND email_verified = 1 LIMIT 1"
        );
        if (!$stmt) {
            $conn->close();
            destroy_app_session();
            return null;
        }
        $stmt->bind_param('s', $tokenHash);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$user) {
        destroy_app_session();
        return null;
    }

    $expiresAt = strtotime((string) $user['token_expires_at']);
    if ($expiresAt === false || $expiresAt <= time()) {
        destroy_app_session();
        return null;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['is_admin'] = !empty($user['is_admin']);
    $_SESSION['expires_at'] = $expiresAt;
    $_SESSION['token_hash'] = $tokenHash;

    return [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'is_admin' => !empty($user['is_admin']),
        'expires_at' => $expiresAt,
        'token_hash' => $tokenHash,
    ];
}

function current_user_is_admin(array $sessionUser): bool
{
    return !empty($sessionUser['is_admin']);
}

function require_admin_session(): array
{
    $sessionUser = require_authenticated_session();
    if (!current_user_is_admin($sessionUser)) {
        send_json(['success' => false, 'error' => 'Administrator access required.'], 403);
    }
    return $sessionUser;
}

function password_strength_errors(string $password): array
{
    $errors = [];
    if ($password === '') {
        $errors[] = 'Password is required.';
        return $errors;
    }
    if (strpos($password, "\0") !== false) {
        $errors[] = 'Password contains invalid characters.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must include uppercase, lowercase, and a number.';
    }
    return $errors;
}

function sanitize_markdown_input(string $markdown): string
{
    $markdown = strip_null_bytes($markdown);
    $markdown = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $markdown) ?? '';
    return trim($markdown);
}

function parse_writeup_tags(string $rawTags): string
{
    $rawTags = strip_null_bytes($rawTags);
    $pieces = preg_split('/[,#]+/', $rawTags) ?: [];
    $tags = [];
    foreach ($pieces as $piece) {
        $tag = strtolower(trim((string) $piece));
        $tag = preg_replace('/[^a-z0-9_-]+/', '-', $tag) ?? '';
        $tag = trim($tag, '-_');
        if ($tag !== '' && strlen($tag) <= 32 && !in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
        if (count($tags) >= 8) break;
    }
    return implode(',', $tags);
}

function normalize_writeup_category(string $category): string
{
    $category = strtolower(trim(strip_null_bytes($category)));
    $category = preg_replace('/[^a-z0-9_-]+/', '-', $category) ?? '';
    $category = trim($category, '-_');
    return strlen($category) <= 40 ? $category : substr($category, 0, 40);
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
        '/var/www/DB/db_config.php',
        '/var/www/DB/DB/db_config.php',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
        dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'db_config.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require_once($candidate);
            return;
        }
    }

    send_json(['success' => false, 'error' => 'Database configuration not found.'], 500);
}

function open_db_connection(): mysqli
{
    require_db_config_file();

    if (!class_exists('mysqli')) {
        send_json(['success' => false, 'error' => 'Database extension is unavailable. Enable the PHP mysqli extension.'], 500);
    }

    if (defined('DB_PASSWORD') && DB_PASSWORD === 'CHANGE_ME_STRONG_PASSWORD' && !insecure_defaults_explicitly_allowed()) {
        send_json(['success' => false, 'error' => 'Database password is still set to the insecure default. Configure NIGHTWATCH_DB_PASSWORD.'], 500);
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if ($conn->connect_error) {
        send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
    }

    if (!$conn->set_charset('utf8mb4')) {
        $conn->close();
        send_json(['success' => false, 'error' => 'Database connection failed.'], 500);
    }

    return $conn;
}

function request_ip_address(): string
{
    // Trust REMOTE_ADDR by default. X-Forwarded-For is trusted only when the
    // direct peer is explicitly listed in NIGHTWATCH_TRUSTED_PROXIES.
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (is_request_from_trusted_proxy()) {
        $forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (explode(',', $forwardedFor) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    if (filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        return $remoteAddress;
    }

    return 'unknown';
}

function enforce_rate_limit(string $scope, int $maxAttempts, int $windowSeconds): void
{
    require_db_config_file();
    $conn = open_db_connection();
    $ip = request_ip_address();
    $now = time();

    // Clean old attempts outside the rolling window before counting current attempts.
    $cleanStmt = $conn->prepare(
        "DELETE FROM rate_limits WHERE scope = ? AND ip_address = ? AND window_end < NOW()"
    );
    if ($cleanStmt) {
        $cleanStmt->bind_param('ss', $scope, $ip);
        $cleanStmt->execute();
        $cleanStmt->close();
    }
    
    // Count current attempts in the rolling window and calculate the earliest reset time.
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt, MIN(UNIX_TIMESTAMP(window_end)) AS reset_at
         FROM rate_limits
         WHERE scope = ? AND ip_address = ? AND window_end > NOW()"
    );
    if ($countStmt) {
        $countStmt->bind_param('ss', $scope, $ip);
        $countStmt->execute();
        $result = $countStmt->get_result();
        $row = $result->fetch_assoc();
        $attempts = (int) ($row['cnt'] ?? 0);
        $resetAt = isset($row['reset_at']) ? (int) $row['reset_at'] : 0;
        $countStmt->close();
    } else {
        $attempts = 0;
        $resetAt = 0;
    }

    if ($attempts >= $maxAttempts) {
        $conn->close();
        $retryAfter = $resetAt > $now ? max(1, $resetAt - $now) : max(1, $windowSeconds);
        header('Retry-After: ' . $retryAfter);
        if (function_exists('log_rate_limit_exceeded')) {
            log_rate_limit_exceeded($scope);
        }
        send_json(['success' => false, 'error' => 'Too many attempts. Please wait and try again.'], 429);
    }

    // Record this attempt with its own rolling expiry.
    $windowEnd = $now + $windowSeconds;
    $insertStmt = $conn->prepare(
        "INSERT INTO rate_limits (scope, ip_address, attempt_count, window_start, window_end)
         VALUES (?, ?, 1, FROM_UNIXTIME(?), FROM_UNIXTIME(?))"
    );
    if ($insertStmt) {
        $insertStmt->bind_param('ssii', $scope, $ip, $now, $windowEnd);
        $insertStmt->execute();
        $insertStmt->close();
    }
    
    $conn->close();
}
