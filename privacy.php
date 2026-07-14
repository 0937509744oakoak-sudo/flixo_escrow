<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Consent.php';

$page_title = 'ความเป็นส่วนตัว (PDPA)';
$action = $_GET['action'] ?? 'settings';
$userModel = new User($conn);
$user = $userModel->findByUsername($me);
$consentModel = new Consent($conn);
$consents = $consentModel->getByUser((int) $user['id']);

$consentLabels = [
    'terms_of_service' => 'ข้อกำหนดการใช้งาน',
    'privacy_policy' => 'นโยบายความเป็นส่วนตัว',
    'kyc_data_collection' => 'ความยินยอมเก็บข้อมูล KYC',
    'marketing' => 'การตลาด',
];

include 'includes/header.php';
include 'includes/navbar.php';
?>

<style>
.privacy-page { max-width: 640px; margin: 32px auto; padding: 0 16px; }
.privacy-card { background: var(--card-bg, #fff); border-radius: 12px; padding: 24px; margin-bottom: 16px; border: 1px solid var(--border-color, #e5e7eb); }
.privacy-card h2 { margin: 0 0 12px; font-size: 1.1rem; }
.privacy-lead { margin: 0 0 16px; color: #5c647a; font-size: 14px; line-height: 1.5; }
.privacy-actions { display: flex; flex-direction: column; gap: 10px; }
.privacy-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 16px; border-radius: 10px; border: 1px solid #ccc; background: #fff; cursor: pointer; text-decoration: none; color: inherit; font-size: 14px; font-weight: 600; }
.privacy-btn-primary { background: var(--primary-color, #2563eb); color: #fff; border-color: transparent; }
.privacy-btn-danger { color: #ba1a1a; border-color: #ba1a1a; }
.consent-row { font-size: 13px; padding: 10px 0; border-bottom: 1px solid #eee; }
.privacy-links { margin-top: 12px; font-size: 13px; }
.privacy-links a { color: var(--primary-color, #2563eb); font-weight: 600; text-decoration: none; }
</style>

<div class="privacy-page">
    <?php if ($action === 'reconsent'): ?>
    <div class="privacy-card">
        <h2>อัปเดตนโยบายความเป็นส่วนตัว</h2>
        <p class="privacy-lead">เราได้ปรับปรุงข้อกำหนดหรือนโยบายความเป็นส่วนตัว กรุณายอมรับเพื่อใช้งานต่อ</p>
        <form id="reconsent-form">
            <label style="display:block;margin-bottom:12px;"><input type="checkbox" name="consent_terms" required> ยอมรับ <a href="terms_of_service.php" target="_blank">ข้อกำหนดการใช้งาน</a> (v<?php echo TERMS_VERSION; ?>)</label>
            <label style="display:block;margin-bottom:16px;"><input type="checkbox" name="consent_privacy" required> ยอมรับ <a href="privacy_policy.php" target="_blank">นโยบายความเป็นส่วนตัว</a> (v<?php echo PRIVACY_VERSION; ?>)</label>
            <button type="submit" class="privacy-btn privacy-btn-primary">ยืนยันและดำเนินการต่อ</button>
        </form>
    </div>
    <?php else: ?>
    <div class="privacy-card">
        <h2>การตั้งค่าความเป็นส่วนตัว (PDPA)</h2>
        <p class="privacy-lead">จัดการสิทธิของคุณตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล — ส่งออกข้อมูล ถอนความยินยอม หรือขอลบบัญชีได้จากที่นี่</p>
        <div class="privacy-actions">
            <button type="button" class="privacy-btn privacy-btn-primary" id="btn-export">ดาวน์โหลดข้อมูลของฉัน</button>
            <button type="button" class="privacy-btn" id="btn-withdraw-kyc">ถอนความยินยอม KYC</button>
            <button type="button" class="privacy-btn privacy-btn-danger" id="btn-delete">ขอลบบัญชี</button>
        </div>
        <p class="privacy-links">
            อ่านรายละเอียด: <a href="privacy_policy.php" target="_blank" rel="noopener">นโยบายความเป็นส่วนตัว</a>
            · <a href="terms_of_service.php" target="_blank" rel="noopener">ข้อกำหนดการใช้งาน</a>
        </p>
    </div>

    <div class="privacy-card">
        <h2>ประวัติความยินยอม</h2>
        <?php if ($consents === []): ?>
            <p class="tx-meta">ยังไม่มีข้อมูล</p>
        <?php else: ?>
            <?php foreach ($consents as $c):
                $type = $c['consent_type'] ?? '';
                $label = $consentLabels[$type] ?? $type;
            ?>
                <div class="consent-row">
                    <strong><?php echo htmlspecialchars($label); ?></strong>
                    <span class="tx-meta">v<?php echo htmlspecialchars($c['version']); ?></span>
                    — <?php echo date('d/m/Y H:i', strtotime($c['consented_at'])); ?>
                    <?php if (!empty($c['withdrawn_at'])): ?>
                        <span style="color:#ba1a1a;">(ถอนแล้ว <?php echo date('d/m/Y', strtotime($c['withdrawn_at'])); ?>)</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p style="text-align:center;"><a href="dashboard.php?tab=profile">← กลับโปรไฟล์</a></p>
</div>

<script>
async function privacyPost(action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF_TOKEN);
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch('controllers/PrivacyController.php', { method: 'POST', body: fd });
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
        return res.json();
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'flixo_export.json';
    a.click();
    URL.revokeObjectURL(url);
    return { status: 'success' };
}

document.getElementById('btn-export')?.addEventListener('click', async () => {
    await privacyPost('export_data');
    Swal.fire('สำเร็จ', 'ดาวน์โหลดข้อมูลแล้ว', 'success');
});

document.getElementById('btn-withdraw-kyc')?.addEventListener('click', async () => {
    const r = await Swal.fire({ title: 'ถอนความยินยอม KYC?', input: 'textarea', inputPlaceholder: 'เหตุผล (ไม่บังคับ)', showCancelButton: true });
    if (!r.isConfirmed) return;
    const data = await privacyPost('withdraw_consent', { consent_type: 'kyc_data_collection', reason: r.value || '' });
    Swal.fire(data.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', data.message, data.status);
    if (data.status === 'success') location.reload();
});

document.getElementById('btn-delete')?.addEventListener('click', async () => {
    const r = await Swal.fire({ title: 'ขอลบบัญชี?', text: 'ทีมงานจะติดต่อยืนยันภายใน 30 วัน', icon: 'warning', showCancelButton: true });
    if (!r.isConfirmed) return;
    const data = await privacyPost('request_deletion');
    Swal.fire(data.status === 'success' ? 'ส่งคำขอแล้ว' : 'ผิดพลาด', data.message, data.status);
});

document.getElementById('reconsent-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'reconsent');
    fd.append('csrf_token', CSRF_TOKEN);
    if (!fd.get('consent_terms') || !fd.get('consent_privacy')) {
        Swal.fire('กรุณายอมรับ', 'ต้องยอมรับทั้งข้อกำหนดและนโยบาย', 'warning');
        return;
    }
    fd.append('consent_terms', '1');
    fd.append('consent_privacy', '1');
    const res = await fetch('controllers/PrivacyController.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.status === 'success') {
        window.location = data.redirect || 'dashboard.php';
    } else {
        Swal.fire('ผิดพลาด', data.message, 'error');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
