<?php
declare(strict_types=1);

require_once(__DIR__ . '/common.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'error' => 'Invalid request method.'], 405);
}

destroy_app_session();
send_json(['success' => true]);
