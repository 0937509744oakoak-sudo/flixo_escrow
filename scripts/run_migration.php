<?php
/**
 * Run database migrations for FLIXO.
 * Usage: php scripts/run_migration.php [filename]
 */
require_once __DIR__ . '/../includes/db.php';

$file = $argv[1] ?? '004_create_product_images.sql';
$path = __DIR__ . '/../migrations/' . basename($file);

if (!is_file($path)) {
    fwrite(STDERR, "Migration not found: {$path}\n");
    exit(1);
}

$sql = file_get_contents($path);
$sql = preg_replace('/--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

echo "Running migration: {$file}\n";

foreach ($statements as $statement) {
    if ($statement === '') {
        continue;
    }
    if (!$conn->query($statement)) {
        $err = $conn->error;
        if (stripos($err, 'Duplicate column') !== false || stripos($err, 'already exists') !== false) {
            echo "  [skip] {$err}\n";
            continue;
        }
        fwrite(STDERR, "  [fail] {$err}\nSQL: " . substr($statement, 0, 120) . "...\n");
        exit(1);
    }
    echo "  [ok] " . substr(preg_replace('/\s+/', ' ', $statement), 0, 90) . "...\n";
}

echo "Migration completed.\n";
