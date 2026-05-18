<?php
declare(strict_types=1);

/**
 * Forward NightWatch JSONL security events to a webhook or stdout.
 * Intended for cron/systemd timers, for example every minute.
 */

$options = getopt('', ['once', 'dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$projectDir = dirname(__DIR__);
$logDir = getenv('NIGHTWATCH_LOG_DIR') ?: $projectDir . DIRECTORY_SEPARATOR . 'logs';
$stateDir = getenv('NIGHTWATCH_LOG_FORWARDER_STATE_DIR') ?: $projectDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'log-forwarder';
$webhookUrl = trim((string) (getenv('NIGHTWATCH_ALERT_WEBHOOK_URL') ?: ''));
$allowedLevels = array_filter(array_map('trim', explode(',', (string) (getenv('NIGHTWATCH_ALERT_LEVELS') ?: 'WARNING,ERROR,AUTH_FAIL,MAIL_FAIL'))));
$allowInsecureWebhook = in_array(strtolower((string) (getenv('NIGHTWATCH_ALLOW_INSECURE_WEBHOOK') ?: '0')), ['1', 'true', 'yes', 'on'], true);

if (!is_dir($logDir)) {
    fwrite(STDERR, "Log directory not found: {$logDir}\n");
    exit(1);
}
if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
    fwrite(STDERR, "Could not create state directory: {$stateDir}\n");
    exit(1);
}

if ($webhookUrl !== '') {
    $parts = parse_url($webhookUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'https' && !$allowInsecureWebhook) {
        fwrite(STDERR, "Webhook URL must use HTTPS unless NIGHTWATCH_ALLOW_INSECURE_WEBHOOK=1.\n");
        exit(1);
    }
}

function state_file_for(string $stateDir, string $logFile): string
{
    return $stateDir . DIRECTORY_SEPARATOR . hash('sha256', realpath($logFile) ?: $logFile) . '.offset';
}

function should_forward(array $event, array $allowedLevels): bool
{
    $level = strtoupper((string) ($event['level'] ?? ''));
    if ($allowedLevels === []) return true;
    return in_array($level, array_map('strtoupper', $allowedLevels), true);
}

function post_json(string $url, array $payload): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: NightWatchLogForwarder/1.0'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') fwrite(STDERR, "Webhook error: {$error}\n");
        return $status >= 200 && $status < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: NightWatchLogForwarder/1.0\r\n",
            'content' => $json,
            'timeout' => 10,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    return $result !== false;
}

$files = glob($logDir . DIRECTORY_SEPARATOR . 'security_*.jsonl') ?: [];
sort($files);
$forwarded = 0;

foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file)) continue;

    $stateFile = state_file_for($stateDir, $file);
    $offset = is_file($stateFile) ? max(0, (int) trim((string) file_get_contents($stateFile))) : 0;
    $size = filesize($file);
    if ($size === false || $offset > $size) $offset = 0;

    $handle = fopen($file, 'rb');
    if ($handle === false) continue;
    fseek($handle, $offset);

    while (($line = fgets($handle)) !== false) {
        $newOffset = ftell($handle);
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded) || !should_forward($decoded, $allowedLevels)) {
            if (is_int($newOffset)) file_put_contents($stateFile, (string) $newOffset, LOCK_EX);
            continue;
        }

        $payload = [
            'source' => 'nightwatch',
            'environment' => getenv('NIGHTWATCH_ENV') ?: 'production',
            'event' => $decoded,
        ];

        $ok = true;
        if ($webhookUrl !== '' && !$dryRun) {
            $ok = post_json($webhookUrl, $payload);
        } else {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        }

        if (!$ok) {
            fclose($handle);
            fwrite(STDERR, "Failed to forward event from {$file}; offset was not advanced.\n");
            exit(2);
        }

        $forwarded++;
        if (is_int($newOffset)) file_put_contents($stateFile, (string) $newOffset, LOCK_EX);
    }

    fclose($handle);
}

echo "Forwarded {$forwarded} security event(s).\n";
