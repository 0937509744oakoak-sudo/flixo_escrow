<?php
/**
 * Password reset request — HMAC token (no DB table required)
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/includes/EmailService.php';

if (!empty($_SESSION['user'])) {
    redirectTo('dashboard.php');
}

$csrf_token = getCsrfToken();
$sent = false;

function flixoResetSecret(): string {
    $key = defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '';
    if ($key === '') {
        $key = defined('DB_NAME') ? (string) DB_NAME : 'flixo';
    }
    return hash('sha256', 'flixo-reset|' . $key);
}

function flixoMakeResetToken(int $userId): string {
    $exp = time() + 3600;
    $payload = $userId . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, flixoResetSecret());
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

if (isPostRequest()) {
    verifyCsrfToken($_POST['csrf_token'] ?? '', 'html');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
    $sent = true;

    if ($email !== '') {
        $userModel = new User($conn);
        $user = $userModel->findByEmail($email);
        if ($user && ($user['status'] ?? '') === 'active') {
            $token = flixoMakeResetToken((int) $user['id']);
            $resetUrl = rtrim(BASE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
            $mailer = new EmailService();
            $body = '<p>สวัสดี คุณ' . htmlspecialchars($user['real_name'] ?? $user['username']) . '</p>'
                . '<p>กดลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ (ใช้ได้ 1 ชั่วโมง):</p>'
                . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                . '<p>หากคุณไม่ได้ขอรีเซ็ต ไม่ต้องทำอะไรกับอีเมลนี้</p>';
            $ok = $mailer->send($email, 'รีเซ็ตรหัสผ่าน FLIXO', $body, true);
            if (!$ok) {
                $_SESSION['flixo_dev_reset_url'] = $resetUrl;
            }
        }
    }
}

$page_title = 'ลืมรหัสผ่าน';
$load_flixo_css = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';

$supportEmail = (defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS) ? MAIL_FROM_ADDRESS : 'support@flixo.app';
$devUrl = $_SESSION['flixo_dev_reset_url'] ?? '';
if ($devUrl) {
    unset($_SESSION['flixo_dev_reset_url']);
}
?>
<main style="max-width:480px;margin:48px auto;padding:0 16px 48px;">
    <div class="flixo-card" style="padding:28px;">
        <h1 style="font-family:var(--font-heading,'Hanken Grotesk',sans-serif);font-size:22px;margin:0 0 8px;">ลืมรหัสผ่าน</h1>
        <p class="tx-meta" style="margin:0 0 20px;">กรอกอีเมลที่สมัครไว้ เราจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ (ถ้ามีบัญชีในระบบ)</p>

        <?php if ($sent): ?>
            <div style="padding:14px 16px;background:#e8f5e9;border-radius:12px;margin-bottom:16px;font-size:14px;">
                หากอีเมลนี้มีในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่านในไม่ช้า ตรวจสอบทั้งกล่องจดหมายและสแปม
            </div>
            <?php if ($devUrl): ?>
            <div style="padding:12px;background:#fff8e1;border-radius:10px;font-size:13px;margin-bottom:16px;word-break:break-all;">
                <strong>โหมดพัฒนา:</strong> อีเมลยังไม่พร้อม — ใช้ลิงก์นี้:<br>
                <a href="<?php echo htmlspecialchars($devUrl); ?>"><?php echo htmlspecialchars($devUrl); ?></a>
            </div>
            <?php endif; ?>
            <a href="login.php" class="btn-flixo-primary" style="display:inline-flex;text-decoration:none;padding:12px 20px;border-radius:12px;">กลับไปเข้าสู่ระบบ</a>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                <div style="margin-bottom:16px;">
                    <label for="email" style="display:block;font-size:13px;margin-bottom:6px;">อีเมล</label>
                    <input type="email" id="email" name="email" required placeholder="example@mail.com" autocomplete="email"
                           style="width:100%;padding:12px;border:1px solid #dde;border-radius:12px;box-sizing:border-box;">
                </div>
                <button type="submit" class="btn-flixo-primary" style="width:100%;padding:14px;border:none;border-radius:12px;cursor:pointer;font-weight:600;">ส่งลิงก์รีเซ็ต</button>
            </form>
            <p class="tx-meta" style="margin-top:16px;text-align:center;">
                จำรหัสได้แล้ว? <a href="login.php">เข้าสู่ระบบ</a>
                · หรือ <a href="mailto:<?php echo htmlspecialchars($supportEmail); ?>">ติดต่อฝ่ายสนับสนุน</a>
            </p>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
