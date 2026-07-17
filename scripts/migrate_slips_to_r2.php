<?php
/**
 * Backfill existing local payment/payout slips to Cloud Object Storage (R2/S3).
 *
 * Slips are PRIVATE: we store the object KEY (e.g. "slips/<name>.jpg") in the DB
 * and stream bytes through the authorized SlipController / WithdrawalSlipController.
 * We never expose a public URL.
 *
 * Safe to run repeatedly (idempotent): only touches rows whose stored value is
 * still a bare local filename (no "/" and not an http URL). Missing local files
 * are skipped, never blanked — no data is lost.
 *
 * Usage:
 *   php scripts/migrate_slips_to_r2.php --dry-run
 *   php scripts/migrate_slips_to_r2.php
 *   php scripts/migrate_slips_to_r2.php --limit=1000
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
 * Migrate one table/column of slips. Stores the object key back into the column.
 *
 * @param string $subdir  local uploads subdir AND cloud key prefix (e.g. "slips")
 */
function migrateSlipColumn(
    mysqli $conn,
    ObjectStorage $storage,
    finfo $finfo,
    string $table,
    string $col,
    string $subdir,
    bool $dryRun,
    int $limit
): void {
    // Rows still on local storage: bare filename (no "/"), not an http URL.
    $countRes = $conn->query(
        "SELECT COUNT(*) AS c FROM {$table}
          WHERE {$col} IS NOT NULL AND {$col} <> ''
            AND {$col} NOT LIKE 'http%' AND {$col} NOT LIKE '%/%'"
    );
    $remaining = $countRes ? (int) $countRes->fetch_assoc()['c'] : 0;
    echo "[{$table}.{$col}] on local storage: {$remaining}\n";

    $rows = $conn->query(
        "SELECT id, {$col} AS ref FROM {$table}
          WHERE {$col} IS NOT NULL AND {$col} <> ''
            AND {$col} NOT LIKE 'http%' AND {$col} NOT LIKE '%/%'
          ORDER BY id ASC
          LIMIT " . (int) $limit
    );
    if (!$rows || $rows->num_rows === 0) {
        echo "  nothing to migrate.\n";
        return;
    }

    $localDir = rtrim(flixoUploadsSubdir($subdir), "/\\");
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

        $mime = $finfo->file($local) ?: 'application/octet-stream';
        $key  = $subdir . '/' . $name; // keep the original filename as the key

        if ($dryRun) {
            echo "  [dry ] id={$id} {$name} -> {$key}\n";
            $migrated++;
            continue;
        }

        try {
            $storage->put($local, $key, $mime); // ignore returned URL; store the KEY
            $upd->bind_param('si', $key, $id);
            $upd->execute();
            $migrated++;
            echo "  [ok  ] id={$id} -> {$key}\n";
        } catch (Throwable $e) {
            $failed++;
            error_log("migrate_slips {$table}#{$id} failed: " . $e->getMessage());
            echo "  [fail] id={$id}: " . $e->getMessage() . "\n";
        }
    }
    echo "  done. migrated={$migrated} missing={$missing} failed={$failed}\n";
}

echo ($dryRun ? "[DRY RUN] " : "") . "Migrating slips to object storage...\n\n";

migrateSlipColumn($conn, $storage, $finfo, 'orders', 'slip_image', 'slips', $dryRun, $limit);
echo "\n";
migrateSlipColumn($conn, $storage, $finfo, 'withdrawal_requests', 'payout_slip', 'withdrawal_slips', $dryRun, $limit);

echo "\nAll done.\n";
