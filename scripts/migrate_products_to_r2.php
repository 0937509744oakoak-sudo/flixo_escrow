<?php
/**
 * Backfill existing local product images to Cloud Object Storage (R2/S3).
 *
 * Product images are PUBLIC: we store the full public URL in the DB.
 * Product::imageUrl() already returns http(s) values as-is, so display keeps
 * working for both migrated (URL) and legacy (local filename) rows.
 *
 * Covers BOTH products.image_path (legacy single image) and product_images
 * (gallery). Safe to run repeatedly (idempotent): only touches rows whose value
 * is not already an http URL. Missing local files are skipped, never blanked.
 *
 * Run migration 026 first (widens columns to VARCHAR(512)).
 *
 * Usage:
 *   php scripts/migrate_products_to_r2.php --dry-run
 *   php scripts/migrate_products_to_r2.php
 *   php scripts/migrate_products_to_r2.php --limit=1000
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/ObjectStorage.php';

$dryRun = in_array('--dry-run', $argv, true);
$limit = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$storage = new ObjectStorage();
if (!$storage->isConfigured()) {
    fwrite(STDERR, "Object storage (S3_*) is not configured. Set env vars first.\n");
    exit(1);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

/**
 * Migrate one table/column of public product images. Stores the public URL back.
 * A per-row cache avoids re-uploading the same physical file twice (products
 * and product_images can reference the same filename).
 *
 * @param array<string,string> $urlCache basename => already-uploaded URL
 */
function migrateProductColumn(
    mysqli $conn,
    ObjectStorage $storage,
    finfo $finfo,
    string $table,
    string $col,
    bool $dryRun,
    int $limit,
    array &$urlCache
): void {
    $countRes = $conn->query(
        "SELECT COUNT(*) AS c FROM {$table}
          WHERE {$col} IS NOT NULL AND {$col} <> '' AND {$col} NOT LIKE 'http%'"
    );
    $remaining = $countRes ? (int) $countRes->fetch_assoc()['c'] : 0;
    echo "[{$table}.{$col}] on local storage: {$remaining}\n";

    $rows = $conn->query(
        "SELECT id, {$col} AS ref FROM {$table}
          WHERE {$col} IS NOT NULL AND {$col} <> '' AND {$col} NOT LIKE 'http%'
          ORDER BY id ASC
          LIMIT " . (int) $limit
    );
    if (!$rows || $rows->num_rows === 0) {
        echo "  nothing to migrate.\n";
        return;
    }

    $localDir = rtrim(flixoUploadsSubdir('products'), "/\\");
    $upd = $conn->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ?");

    $migrated = 0; $missing = 0; $failed = 0;
    while ($row = $rows->fetch_assoc()) {
        $id   = (int) $row['id'];
        $name = basename((string) $row['ref']);
        $local = $localDir . DIRECTORY_SEPARATOR . $name;

        if (!is_file($local)) {
            $missing++;
            echo "  [skip] id={$id} missing: {$name}\n";
            continue;
        }

        $key = 'products/' . $name;

        if ($dryRun) {
            echo "  [dry ] id={$id} {$name} -> {$key}\n";
            $migrated++;
            continue;
        }

        try {
            if (isset($urlCache[$name])) {
                $url = $urlCache[$name]; // already uploaded this physical file
            } else {
                $mime = $finfo->file($local) ?: 'application/octet-stream';
                $url = $storage->put($local, $key, $mime);
                $urlCache[$name] = $url;
            }
            $upd->bind_param('si', $url, $id);
            $upd->execute();
            $migrated++;
            echo "  [ok  ] id={$id} -> {$url}\n";
        } catch (Throwable $e) {
            $failed++;
            error_log("migrate_products {$table}#{$id} failed: " . $e->getMessage());
            echo "  [fail] id={$id}: " . $e->getMessage() . "\n";
        }
    }
    echo "  done. migrated={$migrated} missing={$missing} failed={$failed}\n";
}

echo ($dryRun ? "[DRY RUN] " : "") . "Migrating product images to object storage...\n\n";

$urlCache = [];
migrateProductColumn($conn, $storage, $finfo, 'product_images', 'file_path', $dryRun, $limit, $urlCache);
echo "\n";
migrateProductColumn($conn, $storage, $finfo, 'products', 'image_path', $dryRun, $limit, $urlCache);

echo "\nAll done.\n";
