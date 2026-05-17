<?php
declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once(__DIR__ . '/../WebApp/functions/common.php');

$failures = [];
function assert_true(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

assert_true(safe_public_photo_path(null) === 'assets/nightwatch_symbol.png', 'Null profile photo falls back to NightWatch symbol.');
assert_true(safe_public_photo_path('assets/nightwatch_symbol.png') === 'assets/nightwatch_symbol.png', 'NightWatch symbol is accepted.');
assert_true(safe_public_photo_path('profile_photos/profile_1_abcd.png') === 'functions/photo.php?file=profile_1_abcd.png', 'Private photo path is converted to controlled endpoint.');
assert_true(safe_profile_photo_filename('../bad.php') === null, 'Traversal photo path is rejected.');
assert_true(nightwatch_verify_one_time_code(nightwatch_code_hash('123456'), '123456'), 'Hashed one-time code verifies.');
assert_true(nightwatch_verify_one_time_code('123456', '123456'), 'Legacy plaintext one-time code verifies during migration.');
assert_true(parse_writeup_tags('SQL Injection, Auth, auth, bad tag!') === 'sql-injection,auth,bad-tag', 'Tags are normalized and deduplicated.');
assert_true(normalize_writeup_category('Web Security!') === 'web-security', 'Category is normalized.');
assert_true(password_strength_errors('Weakpass1') !== [], 'Short password is rejected.');
assert_true(password_strength_errors('StrongPass123') === [], 'Strong password is accepted.');

$_SERVER['HTTPS'] = 'off';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
putenv('NIGHTWATCH_TRUSTED_PROXIES');
assert_true(is_https_request() === false, 'Forwarded proto is ignored without trusted proxy.');
putenv('NIGHTWATCH_TRUSTED_PROXIES=127.0.0.1');
assert_true(is_https_request() === true, 'Forwarded proto is accepted from trusted proxy.');
putenv('NIGHTWATCH_TRUSTED_PROXIES');
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

$csrf = csrf_session_token();
assert_true((bool) preg_match('/\A[A-Fa-f0-9]{64}\z/', $csrf), 'CSRF token has expected format.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

require __DIR__ . '/endpoint_contract_tests.php';
echo "All helper tests passed.\n";
