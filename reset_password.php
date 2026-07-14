<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/User.php';

if (!empty($_SESSION['user'])) {
    redirectTo('dashboard.php');
}

function flixoResetSecret(): string {
    $key = defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '';
    if ($key === '') {
        $key = defined('DB_NAME') ? (string) DB_NAME : 'flixo';
    }
    return hash('sha256', 'flixo-reset|' . $key);
}

function flixoParseResetToken(string $token): ?array {
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false) {
        return null;
    }
    $parts = explode('|', $raw);
    if (count($parts) !== 3) {
        return null;
    }
    [$userId, $exp, $sig] = $parts;
    if (!ctype_digit($userId) || !ctype_digit($exp)) {
        return null;
    }
    if ((int) $exp < time()) {
        return null;
    }
    $payload = $userId . '|' . $exp;
    $expected = hash_hmac('sha256', $payload, flixoResetSecret());
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    return ['user_id' => (int) $userId, 'exp' => (int) $exp];
}

$csrf_token = getCsrfToken();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$parsed = $token !== '' ? flixoParseResetToken($token) : null;
$error = null;
$done = false;

if (!$parsed) {
    $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว';
}

if ($parsed && isPostRequest()) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    if (mb_strlen($password) < 6) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านยืนยันไม่ตรงกัน';
    } else {
        $userModel = new User($conn);
        if ($userModel->updatePassword($parsed['user_id'], password_hash($password, PASSWORD_DEFAULT))) {
            $done = true;
        } else {
            $error = 'ไม่สามารถอัปเดตรหัสผ่านได้ กรุณาลองใหม่';
        }
    }
}

$page_title = 'ตั้งรหัสผ่านใหม่';
$load_flixo_css = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main style="max-width:480px;margin:48px auto;padding:0 16px 48px;">
    <div class="flixo-card" style="padding:28px;">
        <h1 style="font-family:var(--font-heading,'Hanken Grotesk',sans-serif);font-size:22px;margin:0 0 8px;">ตั้งรหัสผ่านใหม่</h1>

        <?php if ($done): ?>
            <div style="padding:14px 16px;background:#e8f5e9;border-radius:12px;margin-bottom:16px;font-size:14px;">
                เปลี่ยนรหัสผ่านสำเร็จแล้ว สามารถเข้าสู่ระบบด้วยรหัสใหม่ได้ทันที
            </div>
            <a href="login.php" class="btn-flixo-primary" style="display:inline-flex;text-decoration:none;padding:12px 20px;border-radius:12px;">เข้าสู่ระบบ</a>
        <?php elseif ($parsed): ?>
            <?php if ($error): ?>
                <div style="padding:12px 14px;background:#ffebee;color:#c62828;border-radius:10px;margin-bottom:14px;font-size:14px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div style="margin-bottom:14px;">
                    <label for="password" style="display:block;font-size:13px;margin-bottom:6px;">รหัสผ่านใหม่</label>
                    <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password"
                           style="width:100%;padding:12px;border:1px solid #dde;border-radius:12px;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:18px;">
                    <label for="password_confirm" style="display:block;font-size:13px;margin-bottom:6px;">ยืนยันรหัสผ่าน</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password"
                           style="width:100%;padding:12px;border:1px solid #dde;border-radius:12px;box-sizing:border-box;">
                </div>
                <button type="submit" class="btn-flixo-primary" style="width:100%;padding:14px;border:none;border-radius:12px;cursor:pointer;font-weight:600;">บันทึกรหัสผ่านใหม่</button>
            </form>
        <?php else: ?>
            <div style="padding:12px 14px;background:#ffebee;color:#c62828;border-radius:10px;margin-bottom:14px;font-size:14px;">
                <?php echo htmlspecialchars($error ?: 'ลิงก์ไม่ถูกต้อง'); ?>
            </div>
            <a href="forgot_password.php" class="btn-flixo-primary" style="display:inline-flex;text-decoration:none;padding:12px 20px;border-radius:12px;">ขอลิงก์ใหม่</a>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
