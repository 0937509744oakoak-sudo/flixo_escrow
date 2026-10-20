<?php
/**
 * Run database migrations for FLIXO.
 *
 * Usage:
 *   php scripts/run_migration.php                  run every pending migration in order
 *   php scripts/run_migration.php 022_x.sql        run one file (still recorded)
 *   php scripts/run_migration.php --status         list applied vs pending
 *   php scripts/run_migration.php --force 022_x.sql  re-run an already applied file
 *   php scripts/run_migration.php --baseline       mark all as applied, running none
 *
 * Applied migrations are recorded in schema_migrations, so "which ones have run
 * here?" is answerable instead of being tracked by hand. Databases that predate
 * the ledger should be baselined once before the first normal run.
 */
require_once __DIR__ . '/../includes/db.php';

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$status = in_array('--status', $args, true);
$baseline = in_array('--baseline', $args, true);
$files = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));

ensureLedger($conn);

$available = availableMigrations();
$applied = appliedMigrations($conn);

if ($baseline) {
    foreach ($available as $name) {
        if (isset($applied[$name])) {
            continue;
        }
        recordMigration($conn, $name, hash_file('sha256', MIGRATIONS_DIR . '/' . $name) ?: '');
        echo "[baseline] {$name}\n";
    }
    echo "Baseline recorded — these migrations are now considered applied.\n";
    exit(0);
}

if ($status) {
    printf("%-60s %s\n", 'MIGRATION', 'APPLIED AT');
    foreach ($available as $name) {
        printf("%-60s %s\n", $name, $applied[$name] ?? '-- pending --');
    }
    exit(0);
}

$queue = $files !== [] ? array_map('basename', $files) : $available;
$ran = 0;

foreach ($queue as $name) {
    $path = MIGRATIONS_DIR . '/' . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "Migration not found: {$path}\n");
        exit(1);
    }
    if (isset($applied[$name]) && !$force) {
        echo "[skip] {$name} (applied {$applied[$name]})\n";
        continue;
    }

    echo "Running migration: {$name}\n";
    applyMigration($conn, $name, $path);
    $ran++;
}

echo $ran === 0 ? "Nothing to do — database is up to date.\n" : "Done. {$ran} migration(s) applied.\n";

function ensureLedger(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(191) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return array<int, string> filenames sorted by their numeric prefix */
function availableMigrations(): array {
    $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_NATURAL);
    return $names;
}

/** @return array<string, string> migration name => applied_at */
function appliedMigrations(mysqli $conn): array {
    $out = [];
    $res = $conn->query('SELECT migration, applied_at FROM schema_migrations');
    while ($res && ($row = $res->fetch_assoc())) {
        $out[$row['migration']] = $row['applied_at'];
    }
    return $out;
}

function applyMigration(mysqli $conn, string $name, string $path): void {
    $sql = (string) file_get_contents($path);
    $checksum = hash('sha256', $sql);
    $statements = splitStatements($sql);

    // DDL in MySQL commits implicitly, so a migration cannot be rolled back as
    // a unit. Stopping at the first genuine error at least leaves a clear
    // failure point instead of a half-applied file recorded as successful.
    foreach ($statements as $statement) {
        if ($conn->query($statement)) {
            echo '  [ok] ' . preview($statement) . "\n";
            continue;
        }

        $err = $conn->error;
        if (isAlreadyApplied($err)) {
            // Only safe for single-change statements. A multi-column ALTER that
            // trips on one existing column is rejected in full by MySQL, so
            // skipping it would silently lose the other columns.
            if (countsAsSingleChange($statement)) {
                echo "  [skip] {$err}\n";
                continue;
            }
            fwrite(STDERR,
                "  [fail] {$err}\n" .
                "  This statement changes several things at once, so skipping it would\n" .
                "  silently drop the rest. Split it into one change per statement, or\n" .
                "  apply the remaining changes by hand.\n" .
                '  SQL: ' . preview($statement, 200) . "\n"
            );
            exit(1);
        }

        fwrite(STDERR, "  [fail] {$err}\n  SQL: " . preview($statement, 200) . "\n");
        exit(1);
    }

    recordMigration($conn, $name, $checksum);
}

function recordMigration(mysqli $conn, string $name, string $checksum): void {
    $stmt = $conn->prepare(
        'INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = NOW()'
    );
    $stmt->bind_param('ss', $name, $checksum);
    $stmt->execute();
}

/**
 * Split on semicolons that are not inside a quoted string. The naive
 * explode(';') the old runner used would break any migration containing a
 * semicolon in a default value or comment string.
 *
 * @return array<int, string>
 */
function splitStatements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $sql[++$i];
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = $current;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statements[] = $current;

    return array_values(array_filter(array_map('trim', $statements), static fn(string $s): bool => $s !== ''));
}

function isAlreadyApplied(string $error): bool {
    foreach (['Duplicate column', 'already exists', 'Duplicate key name', 'Duplicate entry'] as $needle) {
        if (stripos($error, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * True when re-running the statement can only be a no-op — a CREATE, or an
 * ALTER that makes exactly one change.
 */
function countsAsSingleChange(string $statement): bool {
    $normalised = preg_replace('/\s+/', ' ', $statement) ?? $statement;
    if (!preg_match('/^ALTER\s+TABLE/i', trim($normalised))) {
        return true;
    }
    return preg_match_all('/\b(ADD|DROP|MODIFY|CHANGE|RENAME)\b/i', $normalised) <= 1;
}

function preview(string $statement, int $length = 90): string {
    $flat = preg_replace('/\s+/', ' ', trim($statement)) ?? $statement;
    return strlen($flat) > $length ? substr($flat, 0, $length) . '...' : $flat;
}
