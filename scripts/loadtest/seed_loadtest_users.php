<?php
/**
 * seed_loadtest_users.php  — DEV/LOCAL ONLY
 *
 * สร้าง (หรือลบ) ผู้ใช้สำหรับ Load Testing เพื่อให้ k6 ล็อกอินเข้าใช้งาน
 * SSE (ChatStream) และยิง POST (ChatController action=send) ได้จริง
 *
 * Usage:
 *   php scripts/loadtest/seed_loadtest_users.php                 # สร้าง 100 users
 *   php scripts/loadtest/seed_loadtest_users.php --count=200     # สร้าง 200 users
 *   php scripts/loadtest/seed_loadtest_users.php --cleanup       # ลบ users ทั้งหมด
 *
 * รหัสผ่านเริ่มต้น: LoadTest!2026  (override ด้วย env LOADTEST_PASSWORD)
 * ชื่อผู้ใช้:       loadtest_user_0000 ... loadtest_user_NNNN
 *
 * ปลอดภัย: ปฏิเสธการรันเมื่อ APP_ENV === 'production' และรันได้เฉพาะ CLI
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../models/User.php';

if (defined('APP_ENV') && APP_ENV === 'production') {
    fwrite(STDERR, "REFUSING to run in production (APP_ENV=production).\n");
    exit(1);
}

// ---- args ----
$count   = 100;
$cleanup = false;
foreach ($argv as $arg) {
    if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
        $count = max(1, (int) $m[1]);
    } elseif ($arg === '--cleanup') {
        $cleanup = true;
    }
}

$prefix = 'loadtest_user_';
$likePattern = 'loadtest\\_user\\_%'; // escape underscores for LIKE

// ---- cleanup mode ----
if ($cleanup) {
    // ลบข้อความที่เกี่ยวข้องก่อน แล้วค่อยลบ user (best-effort)
    $conn->query("DELETE FROM messages WHERE sender_name LIKE '{$likePattern}' OR receiver_name LIKE '{$likePattern}'");
    $del = $conn->query("DELETE FROM users WHERE username LIKE '{$likePattern}'");
    $n = $del ? $conn->affected_rows : 0;
    echo "Cleanup done. Removed {$n} load-test users (and their messages).\n";
    exit(0);
}

// ---- create mode ----
$password = getenv('LOADTEST_PASSWORD') ?: 'LoadTest!2026';
$hash     = password_hash($password, PASSWORD_DEFAULT);

$userModel = new User($conn);

$created = 0;
$skipped = 0;
$failed  = 0;

for ($i = 0; $i < $count; $i++) {
    $username = sprintf('%s%04d', $prefix, $i);
    $email    = sprintf('loadtest_%04d@loadtest.local', $i);

    if ($userModel->findByLogin($username)) {
        $skipped++;
        continue;
    }

    try {
        // create($username, $password, $email, $real_name, $phone)
        // ส่ง hash เข้าไปตรง ๆ (login ใช้ password_verify)
        $ok = $userModel->create($username, $hash, $email, 'Load Test ' . $i, '0000000000');
        if ($ok) {
            $created++;
        } else {
            $failed++;
            fwrite(STDERR, "  [fail] create {$username}: {$conn->error}\n");
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "  [fail] create {$username}: {$e->getMessage()}\n");
    }
}

// ให้แน่ใจว่า status = active (login ตรวจ status !== 'active' จะปฏิเสธ)
$hasStatus = false;
$colRes = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if ($colRes && $colRes->num_rows > 0) {
    $hasStatus = true;
}
if ($hasStatus) {
    @$conn->query("UPDATE users SET status='active' WHERE username LIKE '{$likePattern}'");
}

echo "----------------------------------------\n";
echo "Load-test user seeding complete.\n";
echo "  requested : {$count}\n";
echo "  created   : {$created}\n";
echo "  skipped   : {$skipped} (already existed)\n";
echo "  failed    : {$failed}\n";
echo "  username  : {$prefix}0000 .. " . sprintf('%s%04d', $prefix, $count - 1) . "\n";
echo "  password  : {$password}\n";
echo "----------------------------------------\n";
echo "Next: run k6 with BASE_URL pointing at this app.\n";
echo "  k6 run -e BASE_URL=http://localhost/flixo_escrow scripts/loadtest/sse_chat_loadtest.js\n";
