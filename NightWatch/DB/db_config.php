<?php
// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
// Prefer environment variables in production. The defaults match README.md.

function nightwatch_env_value(string $name, string $fallback): string
{
    $value = getenv($name);
    return ($value !== false && $value !== '') ? $value : $fallback;
}

define('DB_HOST', nightwatch_env_value('NIGHTWATCH_DB_HOST', 'localhost'));
define('DB_USER', nightwatch_env_value('NIGHTWATCH_DB_USER', 'nightwatch_app'));
define('DB_PASSWORD', nightwatch_env_value('NIGHTWATCH_DB_PASSWORD', 'CHANGE_ME_STRONG_PASSWORD'));
define('DB_NAME', nightwatch_env_value('NIGHTWATCH_DB_NAME', 'NightWatchDB'));
