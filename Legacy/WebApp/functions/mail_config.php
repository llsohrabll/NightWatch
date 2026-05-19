<?php
// ============================================================================
// MAIL_CONFIG.PHP - VALUES USED BY THE EMAIL HELPER
// ============================================================================
// Production deployments should set these values with environment variables.
// Hard-coding SMTP passwords in a web-readable project directory is unsafe.

if (!defined('NIGHTWATCH_INTERNAL')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied');
}

/** Return an environment variable or a safe fallback. */
function nightwatch_mail_env(string $name, string $fallback): string
{
    $value = getenv($name);
    return ($value !== false && $value !== '') ? $value : $fallback;
}

/** Return an integer environment variable or a safe fallback. */
function nightwatch_mail_env_int(string $name, int $fallback): int
{
    $value = getenv($name);
    return ($value !== false && preg_match('/^\d+$/', $value)) ? (int) $value : $fallback;
}

/** Return a boolean environment variable or a safe fallback. */
function nightwatch_mail_env_bool(string $name, bool $fallback): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') return $fallback;
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

// ============================================================================
// SMTP SERVER CONFIGURATION
// ============================================================================

define('SMTP_HOST', nightwatch_mail_env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', nightwatch_mail_env_int('SMTP_PORT', 587));
define('SMTP_USERNAME', nightwatch_mail_env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', nightwatch_mail_env('SMTP_PASSWORD', ''));
define('SMTP_ENCRYPTION', strtolower(nightwatch_mail_env('SMTP_ENCRYPTION', 'tls')));
define('SMTP_VERIFY_CERT', nightwatch_mail_env_bool('SMTP_VERIFY_CERT', true));
define('SMTP_REQUIRE_AUTH', nightwatch_mail_env_bool('SMTP_REQUIRE_AUTH', true));
define('SMTP_ALLOW_INSECURE_AUTH', nightwatch_mail_env_bool('SMTP_ALLOW_INSECURE_AUTH', false));

// ============================================================================
// EMAIL SETTINGS
// ============================================================================

define('MAIL_FROM_ADDRESS', nightwatch_mail_env('MAIL_FROM_ADDRESS', SMTP_USERNAME !== '' ? SMTP_USERNAME : 'noreply@nightwatch.local'));
define('MAIL_FROM_NAME', nightwatch_mail_env('MAIL_FROM_NAME', 'NightWatch System'));

// ============================================================================
// EMAIL CONTENT SETTINGS
// ============================================================================

define('PASSWORD_RESET_TIMEOUT', nightwatch_mail_env_int('PASSWORD_RESET_TIMEOUT', 3600));
define('EMAIL_VERIFICATION_TIMEOUT', nightwatch_mail_env_int('EMAIL_VERIFICATION_TIMEOUT', 900));
