#!/usr/bin/env php
<?php
/**
 * Phase 0 smoke test — core escrow flow components (no live payment).
 *
 * Usage:
 *   php scripts/test_e2e_flow.php
 *   php scripts/test_e2e_flow.php --verbose
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../includes/PiiEncryption.php';
require_once __DIR__ . '/../includes/WalletService.php';

$verbose = in_array('--verbose', $argv ?? [], true);
$passed = 0;
$failed = 0;

function pass(string $msg): void {
    global $passed;
    echo "  ✓ {$msg}\n";
    $passed++;
}

function fail(string $msg, string $detail = ''): void {
    global $failed, $verbose;
    echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    if ($verbose && $detail !== '') {
        echo "    {$detail}\n";
    }
    $failed++;
}

function tableExists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

echo "========================================\n";
echo "FLIXO E2E Flow Smoke Test (Phase 0)\n";
echo "========================================\n\n";

// --- 1. Database tables ---
echo "1. REQUIRED TABLES\n";
$requiredTables = [
    'users', 'orders', 'products', 'messages', 'reviews',
    'order_status_logs', 'transaction_logs', 'slip_upload_attempts',
    'wallets', 'wallet_transactions', 'disputes',
    'user_consents', 'pii_audit_logs',
];
foreach ($requiredTables as $table) {
    if (tableExists($conn, $table)) {
        pass("table `{$table}` exists");
    } else {
        fail("table `{$table}` missing", 'Run pending migrations');
    }
}

// --- 2. Order state machine ---
echo "\n2. ORDER STATE MACHINE\n";
$validPath = [
    [OrderStateMachine::CREATED, OrderStateMachine::PENDING_PAYMENT],
    [OrderStateMachine::PENDING_PAYMENT, OrderStateMachine::WAITING_VERIFY],
    [OrderStateMachine::WAITING_VERIFY, OrderStateMachine::PAID],
    [OrderStateMachine::PAID, OrderStateMachine::SHIPPED],
    [OrderStateMachine::SHIPPED, OrderStateMachine::COMPLETED],
];
foreach ($validPath as [$from, $to]) {
    if (OrderStateMachine::isValidTransition($from, $to)) {
        pass("{$from} → {$to}");
    } else {
        fail("{$from} → {$to}");
    }
}

$invalid = OrderStateMachine::isValidTransition(OrderStateMachine::COMPLETED, OrderStateMachine::PAID);
$invalid ? fail('COMPLETED → PAID should be blocked') : pass('COMPLETED → PAID blocked');

$buyerCanDispute = OrderStateMachine::canUserPerformAction(OrderStateMachine::DISPUTE, 'buyer');
$buyerCanDispute ? pass('buyer can open DISPUTE') : fail('buyer cannot open DISPUTE');

// --- 3. Role permissions sample ---
echo "\n3. ROLE PERMISSIONS\n";
OrderStateMachine::canUserPerformAction(OrderStateMachine::SHIPPED, 'seller')
    ? pass('seller can mark SHIPPED')
    : fail('seller cannot mark SHIPPED');
OrderStateMachine::canUserPerformAction(OrderStateMachine::PAID, 'admin')
    ? pass('admin can confirm PAID')
    : fail('admin cannot confirm PAID');
!OrderStateMachine::canUserPerformAction(OrderStateMachine::PAID, 'buyer')
    ? pass('buyer cannot confirm PAID')
    : fail('buyer should not confirm PAID');

// --- 4. PII encryption ---
echo "\n4. PII ENCRYPTION\n";
try {
    $plain = '0812345678';
    $enc = PiiEncryption::encrypt($plain);
    $dec = PiiEncryption::decrypt($enc);
    ($dec === $plain && str_starts_with($enc, 'enc:'))
        ? pass('phone encryption round-trip')
        : fail('phone encryption round-trip');
} catch (Throwable $e) {
    fail('phone encryption', $e->getMessage());
}

// --- 5. Reviews schema ---
echo "\n5. REVIEWS SCHEMA\n";
$res = $conn->query("SHOW COLUMNS FROM reviews LIKE 'order_id'");
if ($res && $res->num_rows > 0) {
    pass('reviews.order_id column exists');
    $idx = $conn->query("SHOW INDEX FROM reviews WHERE Key_name = 'uniq_review_order'");
    ($idx && $idx->num_rows > 0)
        ? pass('reviews unique constraint on order_id')
        : fail('reviews missing uniq_review_order index');
} else {
    fail('reviews table/column missing', 'Run: php scripts/run_migration.php 010_create_reviews_table.sql');
}

// --- 6. Wallet service wiring (dry logic) ---
echo "\n6. WALLET SERVICE\n";
class_exists('WalletService') ? pass('WalletService loaded') : fail('WalletService not found');
$ws = new WalletService($conn);
method_exists($ws, 'creditEscrow') ? pass('creditEscrow method') : fail('creditEscrow missing');
method_exists($ws, 'releaseToSeller') ? pass('releaseToSeller method') : fail('releaseToSeller missing');
method_exists($ws, 'refundBuyer') ? pass('refundBuyer method') : fail('refundBuyer missing');

// --- 7. Sample order lifecycle in DB (read-only) ---
echo "\n7. DATA SANITY (read-only)\n";
$orderRes = $conn->query("SELECT id, status FROM orders ORDER BY id DESC LIMIT 1");
if ($orderRes && ($row = $orderRes->fetch_assoc())) {
    pass("latest order #{$row['id']} status={$row['status']}");
} else {
    pass('no orders yet (empty DB is OK for new install)');
}

$badStatus = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status NOT IN ('CREATED','PENDING_PAYMENT','WAITING_VERIFY','PAID','SHIPPED','COMPLETED','CANCELLED','DISPUTE')");
if ($badStatus && ($bad = $badStatus->fetch_assoc()) && (int)$bad['c'] === 0) {
    pass('all order statuses are valid enum values');
} else {
    fail('found orders with unknown status values');
}

echo "\n========================================\n";
echo "Result: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
