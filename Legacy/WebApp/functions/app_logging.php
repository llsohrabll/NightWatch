<?php
// ============================================================================
// APP_LOGGING.PHP - CENTRALIZED APPLICATION LOGGING
// ============================================================================
// This file writes security, audit, mail, validation, and database events to
// daily log files outside the public WebApp document root whenever possible.

declare(strict_types=1);

if (!defined('NIGHTWATCH_INTERNAL')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied');
}

/**
 * Remove CR/LF/control characters from values before they are written to logs.
 * This prevents log-forging attacks where user input creates fake log lines.
 */
function log_safe_value(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return trim($value);
}

/**
 * Locate or create the private directory that stores NightWatch log files.
 * The preferred location is ../logs beside WebApp, not inside the document root.
 */
function get_secure_log_directory(): string
{
    $configured = getenv('NIGHTWATCH_LOG_DIR');
    $candidates = [];
    if ($configured !== false && trim((string) $configured) !== '') {
        $candidates[] = rtrim((string) $configured, DIRECTORY_SEPARATOR);
    }
    $candidates[] = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'logs';
    $candidates[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_logs';

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    $fallback = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nightwatch_logs';
    @mkdir($fallback, 0700, true);
    return $fallback;
}

/**
 * Write one security/audit event to the current daily log file.
 * The log includes timestamp, severity, IP, optional user id, method, URI, and event text.
 */
function log_security_event(string $event, string $level = 'INFO', ?string $userId = null, array $context = []): void
{
    $logDir = get_secure_log_directory();
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'security_' . date('Y-m-d') . '.jsonl';

    $safeContext = [];
    foreach ($context as $key => $value) {
        $safeKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $key) ?? 'key';
        if (is_scalar($value) || $value === null) {
            $safeContext[$safeKey] = log_safe_value((string) $value);
        }
    }

    $record = [
        'timestamp' => gmdate('c'),
        'level' => log_safe_value($level),
        'event' => log_safe_value($event),
        'ip' => function_exists('request_ip_address') ? request_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        'user_id' => $userId !== null ? log_safe_value($userId) : null,
        'method' => log_safe_value((string) ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN')),
        'uri' => log_safe_value((string) ($_SERVER['REQUEST_URI'] ?? 'unknown')),
        'request_id' => bin2hex(random_bytes(8)),
        'context' => $safeContext,
    ];

    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        @file_put_contents($logFile, $json . "\n", FILE_APPEND | LOCK_EX);
        @chmod($logFile, 0600);
    }
}

/** Log authentication success/failure without exposing passwords. */
function log_auth_event(string $type, string $username, bool $success, ?string $message = null): void
{
    $result = $success ? 'SUCCESS' : 'FAILED';
    $level = $success ? 'AUTH' : 'AUTH_FAIL';
    $msg = 'Type: ' . log_safe_value($type) . ' | User: ' . log_safe_value($username) . ' | Result: ' . $result;
    if ($message) $msg .= ' | Details: ' . log_safe_value($message);

    log_security_event($msg, $level);
}

/** Log behavior that may indicate probing, brute force, or abuse. */
function log_suspicious_activity(string $description, ?string $userId = null): void
{
    log_security_event('SUSPICIOUS: ' . log_safe_value($description), 'WARNING', $userId);
}

/** Log database operations that fail; avoid putting SQL values in the message. */
function log_database_error(string $operation, string $error): void
{
    log_security_event('DATABASE ERROR | Op: ' . log_safe_value($operation) . ' | Error: ' . log_safe_value($error), 'ERROR');
}

/** Log file upload/delete operations. */
function log_file_operation(string $operation, string $filename, bool $success): void
{
    $result = $success ? 'OK' : 'FAILED';
    log_security_event('FILE_OP: ' . log_safe_value($operation) . ' | File: ' . log_safe_value($filename) . ' | Result: ' . $result, 'INFO');
}

/** Log validation failures without echoing large or sensitive form values. */
function log_validation_error(string $field, string $reason): void
{
    log_security_event('VALIDATION_ERROR | Field: ' . log_safe_value($field) . ' | Reason: ' . log_safe_value($reason), 'WARNING');
}

/** Log when a rate-limit rule blocks the request. */
function log_rate_limit_exceeded(string $scope): void
{
    log_security_event('RATE_LIMIT | Scope: ' . log_safe_value($scope), 'WARNING');
}

/** Log mail delivery results without logging mail credentials or message bodies. */
function log_mail_event(string $recipient, string $subject, bool $success, string $details = ''): void
{
    $result = $success ? 'SUCCESS' : 'FAILED';
    $level = $success ? 'MAIL' : 'MAIL_FAIL';
    $msg = 'To: ' . log_safe_value($recipient) . ' | Subject: ' . log_safe_value($subject) . ' | Result: ' . $result;

    if ($details !== '') {
        $msg .= ' | ' . log_safe_value($details);
    }

    log_security_event($msg, $level);
}

/** Delete rotated log files older than 90 days. */
function cleanup_old_logs(): void
{
    $logDir = get_secure_log_directory();
    if (!is_dir($logDir)) return;

    $cutoffTime = time() - (90 * 24 * 60 * 60);

    foreach (glob($logDir . DIRECTORY_SEPARATOR . '*.{log,jsonl}', GLOB_BRACE) as $file) {
        if (is_file($file) && filemtime($file) !== false && filemtime($file) < $cutoffTime) {
            @unlink($file);
        }
    }
}

// Run cleanup occasionally so normal traffic keeps old logs under control.
if (random_int(1, 1000) === 1) {
    cleanup_old_logs();
}
