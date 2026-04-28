<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This diagnostic can only be run from the command line.' . PHP_EOL);
}

echo "=== Database Connection Test ===\n";

// 1. Check if config file exists
$configCandidates = [
    '/var/www/config/db_config.php',
    __DIR__ . DIRECTORY_SEPARATOR . 'db_config.php',
];

$configPath = null;
foreach ($configCandidates as $candidate) {
    if (file_exists($candidate)) {
        $configPath = $candidate;
        break;
    }
}

if ($configPath === null) {
    die("ERROR: Config file not found.\n");
}

echo "✓ Config file found at: $configPath\n";

// 2. Include config
require_once($configPath);
echo "✓ Config file included.\n";

// 3. Verify constants are defined
$required = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];

foreach ($required as $const) {
    if (!defined($const)) {
        die("ERROR: Constant '$const' is not defined.\n");
    }

    $value = $const === 'DB_PASSWORD'
        ? '[hidden]'
        : (string) constant($const);
    echo "✓ $const = " . $value . "\n";
}

// 4. Attempt MySQL connection
echo "\n--- Attempting MySQL connection ---\n";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// 5. Check connection result
if ($conn->connect_error) {
    die("ERROR: Connection failed - " . $conn->connect_error . "\n");
}

echo "✓ Connected successfully to database '" . DB_NAME . "'\n";

// 6. Optional: Run a simple query to confirm
$result = $conn->query("SELECT DATABASE() AS db, USER() AS user, VERSION() AS version");

if ($result) {
    $row = $result->fetch_assoc();
    echo "✓ Current database: " . $row['db'] . "\n";
    echo "✓ Connected as user: " . $row['user'] . "\n";
    echo "✓ MySQL version: " . $row['version'] . "\n";
}

// Close connection
$conn->close();

echo "\n=== Test completed successfully ===\n";

?>
