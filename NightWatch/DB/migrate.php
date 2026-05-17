<?php
// Run from CLI: php DB/migrate.php
// Applies DB/migrations/*.sql in filename order using the configured DB account.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration runner must be used from CLI.\n");
    exit(1);
}

require_once(__DIR__ . '/db_config.php');

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);
if (!$files) {
    fwrite(STDERR, "No migrations found.\n");
    exit(1);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, "DB connection failed: {$conn->connect_error}\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Could not read {$file}\n");
        exit(1);
    }
    if (str_contains($sql, 'SOURCE ')) {
        echo "Skipping {$file}; use mysql client for SOURCE-based bootstrap.\n";
        continue;
    }
    echo "Applying " . basename($file) . "...\n";
    if (!$conn->multi_query($sql)) {
        fwrite(STDERR, "Migration failed: {$conn->error}\n");
        exit(1);
    }
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->error) {
        fwrite(STDERR, "Migration failed: {$conn->error}\n");
        exit(1);
    }
}
$conn->close();
echo "Migrations completed.\n";
