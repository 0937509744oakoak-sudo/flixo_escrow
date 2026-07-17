<?php
/**
 * Backfill existing local chat images to Cloud Object Storage (Cloudflare R2 / S3).
 *
 * Safe to run repeatedly (idempotent): only touches rows whose image_path is
 * still a local filename (NOT LIKE 'http%'). Missing local files are skipped,
 * never blanked, so no data is lost.
 *
 * Usage:
 *   php scripts/migrate_images_to_r2.php            # migrate up to 500 rows
 *   php scripts/migrate_images_to_r2.php --dry-run  # preview only, no writes
 *   php scripts/migrate_images_to_r2.php --limit=1000
 *
 * Recommended rollout:
 *   Phase 1: deploy code (dual-read already works — old files still display)
 *   Phase 2: run this script in batches until "remaining" reaches 0
 *   Phase 3: verify sample URLs, then archive the local uploads/ folder
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/ObjectStorage.php';

// --- parse args ---
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

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

// Count remaining before we start.
$remainRes = $conn->query(
    "SELECT COUNT(*) AS c FROM messages WHERE image_path <> '' AND image_path IS NOT NULL AND image_path NOT LIKE 'http%'"
);
$remaining = $remainRes ? (int) $remainRes->fetch_assoc()['c'] : 0;
echo "Rows still on local storage: {$remaining}\n";
echo ($dryRun ? "[DRY RUN] " : "") . "Processing up to {$limit} rows...\n\n";

$rows = $conn->query(
    "SELECT id, image_path FROM messages
      WHERE image_path <> '' AND image_path IS NOT NULL AND image_path NOT LIKE 'http%'
      ORDER BY id ASC
      LIMIT " . (int) $limit
);

if (!$rows || $rows->num_rows === 0) {
    echo "Nothing to migrate. Done.\n";
    exit(0);
}

$upd = $conn->prepare("UPDATE messages SET image_path = ? WHERE id = ?");

$migrated = 0;
$missing  = 0;
$failed   = 0;
$uploadsRoot = rtrim(flixoUploadsRoot(), "/\\");

while ($row = $rows->fetch_assoc()) {
    $id   = (int) $row['id'];
    $name = basename((string) $row['image_path']);       // local chat images live in uploads root
    $local = $uploadsRoot . DIRECTORY_SEPARATOR . $name;

    if (!is_file($local)) {
        $missing++;
        echo "  [skip] id={$id} file missing: {$name}\n";
        continue;
    }

    $mime = $finfo->file($local);
    if (!isset($mimeExt[$mime])) {
        $missing++;
        echo "  [skip] id={$id} unsupported mime: {$mime}\n";
        continue;
    }

    $objectKey = 'chat/' . pathinfo($name, PATHINFO_FILENAME) . '.' . $mimeExt[$mime];

    if ($dryRun) {
        echo "  [dry ] id={$id} {$name} -> {$objectKey}\n";
        $migrated++;
        continue;
    }

    try {
        $url = $storage->put($local, $objectKey, $mime);
        $upd->bind_param('si', $url, $id);
        $upd->execute();
        $migrated++;
        echo "  [ok  ] id={$id} -> {$url}\n";
    } catch (Throwable $e) {
        $failed++;
        error_log("migrate_images_to_r2 id={$id} failed: " . $e->getMessage());
        echo "  [fail] id={$id}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. migrated={$migrated} missing={$missing} failed={$failed}\n";
if (!$dryRun && $remaining > $migrated) {
    echo "Run again to continue (remaining ~" . ($remaining - $migrated) . ").\n";
}
