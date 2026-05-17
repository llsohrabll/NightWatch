<?php
declare(strict_types=1);

$endpointDir = __DIR__ . '/../WebApp/functions';
$postEndpoints = [
    'login.php',
    'logout.php',
    'register.php',
    'verify_email.php',
    'resend_verification.php',
    'forget_password.php',
    'reset_password.php',
    'create_writeup.php',
    'upload_photo.php',
    'request_email_change.php',
    'verify_email_change.php',
    'moderate_writeup.php',
];

$failures = [];
foreach ($postEndpoints as $endpoint) {
    $path = $endpointDir . '/' . $endpoint;
    $source = file_get_contents($path) ?: '';
    if (!str_contains($source, "REQUEST_METHOD'] !== 'POST'")) {
        $failures[] = "{$endpoint} does not enforce POST.";
    }
    if (!str_contains($source, 'enforce_same_origin_post();')) {
        $failures[] = "{$endpoint} does not enforce same-origin POST.";
    }
    if (!str_contains($source, 'enforce_csrf_token();')) {
        $failures[] = "{$endpoint} does not enforce CSRF.";
    }
    if (!str_contains($source, 'set_security_headers();')) {
        $failures[] = "{$endpoint} does not set security headers.";
    }
}

$getEndpoints = ['session.php', 'panel.php', 'get_writeups.php', 'get_users.php', 'get_user_profile.php', 'csrf.php', 'photo.php', 'admin_writeups.php'];
foreach ($getEndpoints as $endpoint) {
    $path = $endpointDir . '/' . $endpoint;
    $source = file_get_contents($path) ?: '';
    if (!str_contains($source, "REQUEST_METHOD'] !== 'GET'")) {
        $failures[] = "{$endpoint} does not enforce GET.";
    }
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Endpoint contract tests passed.\n";
