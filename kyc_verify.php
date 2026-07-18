<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/EkycService.php';
require_once __DIR__ . '/models/User.php';

$me = $_SESSION['user'];
$userModel = new User($conn);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    $u = $userModel->findByUsername($me);
    $userId = (int)($u['id'] ?? 0);
}

$stmt = $conn->prepare("SELECT is_verified, id_card_image, selfie_image, kyc_status, ekyc_provider FROM users WHERE username = ?");
$stmt->bind_param('s', $me);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

$provider = EkycService::activeProvider();
$useSumsub = ($provider === EkycService::PROVIDER_SUMSUB);
$useAigen = ($provider === EkycService::PROVIDER_AIGEN);

if ($useSumsub && $userId > 0) {
    try {
        $ekycService = new EkycService($conn);
        $ekycService->syncReviewFromSumsub($userId);
        $stmt = $conn->prepare("SELECT is_verified, id_card_image, selfie_image, kyc_status, ekyc_provider FROM users WHERE username = ?");
        $stmt->bind_param('s', $me);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        error_log('KYC sync on load: ' . $e->getMessage());
    }
}

if (!empty($user_data['is_verified']) || ($user_data['kyc_status'] ?? '') === 'approved') {
    echo "<script>alert('คุณผ่านการยืนยันตัวตนแล้ว'); window.location='dashboard.php?tab=profile';</script>";
    exit();
}

$kycStatus = (string) ($user_data['kyc_status'] ?? 'none');
$hasManualDocs = !empty($user_data['id_card_image']) || !empty($user_data['selfie_image']);
// pending must mean "actually submitted" — never treat brand-new default as submitted
$awaitingManualReview = !$useSumsub && $kycStatus === 'pending' && $hasManualDocs;
$awaitingSumsubReview = $useSumsub && $kycStatus === 'pending';
$canStartOrContinueKyc = !$awaitingManualReview && !$awaitingSumsubReview;

$csrf = getCsrfToken();

$page_title = 'ยืนยันตัวตน (eKYC)';
include 'includes/header.php';
include 'includes/navbar.php';
?>
<style>
.kyc-wrap { max-width: 560px; margin: 40px auto; padding: 24px; background: var(--card-bg,#fff); border-radius: 16px; border: 1px solid var(--border-color,#eee); }
.kyc-steps { display: flex; gap: 8px; margin-bottom: 20px; }
.kyc-step { flex: 1; text-align: center; padding: 8px; border-radius: 8px; background: #f5f5f5; font-size: 12px; }
.kyc-step.active { background: #e3f2fd; color: #1565c0; font-weight: 600; }
#sumsub-websdk-container { min-height: 480px; }
#cameraPreview { width: 100%; max-width: 320px; border-radius: 12px; background: #000; display: none; }
#selfieCanvas { display: none; }
.capture-btns { display: flex; gap: 10px; justify-content: center; margin-top: 12px; flex-wrap: wrap; }
</style>

<div class="kyc-wrap">
    <h2 style="text-align:center;margin-bottom:8px;">ยืนยันตัวตน (eKYC)</h2>
    <p style="text-align:center;color:#666;margin-bottom:24px;">
        <?php if ($useSumsub): ?>
            ระบบอัตโนมัติ — ถ่ายบัตรประชาชน + สแกนใบหน้า (Liveness)
        <?php elseif ($useAigen): ?>
            ระบบอัตโนมัติ (AiGen) — อัปโหลดบัตรประชาชน + ถ่ายใบหน้า ตรวจ OCR และจับคู่ใบหน้าทันที
        <?php else: ?>
            อัปโหลดบัตรประชาชน + ถ่ายรูปใบหน้า (Selfie)
        <?php endif; ?>
    </p>

    <?php if ($kycStatus === 'rejected'): ?>
        <div style="padding:14px;background:#ffebee;color:#c62828;border-radius:10px;margin-bottom:16px;">
            <strong>การยืนยันตัวตนไม่ผ่าน</strong><br>กรุณาลองใหม่อีกครั้ง
        </div>
    <?php endif; ?>

    <?php if ($awaitingManualReview): ?>
        <div style="padding:20px;background:#fff3cd;border-radius:10px;text-align:center;">
            <strong>เอกสารถูกส่งแล้ว</strong><br>รอแอดมินตรวจสอบ (ภายใน 24 ชม.)
        </div>
        <p style="text-align:center;margin-top:16px;"><a href="dashboard.php?tab=profile">กลับหน้าโปรไฟล์</a></p>

    <?php elseif ($awaitingSumsubReview): ?>
        <div style="padding:20px;background:#e3f2fd;border-radius:10px;text-align:center;">
            <strong>ส่งเอกสารแล้ว — กำลังตรวจสอบ</strong><br>
            <span style="font-size:14px;color:#555;">ระบบจะอัปเดตสถานะอัตโนมัติ</span>
        </div>
        <button type="button" id="btnSyncKyc" class="ripple-btn" style="width:100%;margin-top:16px;padding:12px;background:var(--primary-color);color:#fff;border:none;border-radius:50px;cursor:pointer;">
            ตรวจสอบผลอีกครั้ง
        </button>
        <p style="text-align:center;margin-top:16px;"><a href="dashboard.php?tab=profile">กลับหน้าโปรไฟล์</a></p>

    <?php elseif ($useSumsub && $canStartOrContinueKyc): ?>
        <div id="sumsub-websdk-container"></div>
        <p style="font-size:12px;color:#888;text-align:center;margin-top:12px;">
            ขับเคลื่อนโดย Sumsub — ข้อมูลเข้ารหัสและเป็นไปตาม PDPA
        </p>

    <?php elseif ($canStartOrContinueKyc): ?>
        <div class="kyc-steps">
            <div class="kyc-step active" id="stepLabel1">1. บัตรประชาชน</div>
            <div class="kyc-step" id="stepLabel2">2. รูปใบหน้า</div>
        </div>

        <form id="kycForm">
            <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf); ?>">
            <input type="hidden" name="action" value="submit_kyc">

            <div id="step1">
                <p style="font-size:14px;color:#555;margin-bottom:12px;">ถ่ายหรืออัปโหลดรูปบัตรประชาชนให้ชัดเจน</p>
                <div style="border:2px dashed #ddd;padding:32px;border-radius:12px;text-align:center;cursor:pointer;" onclick="document.getElementById('fileInput').click()">
                    <div style="font-size:36px;">🪪</div>
                    <div style="color:#888;margin-top:8px;">เลือกรูปบัตรประชาชน</div>
                    <input type="file" name="id_card" id="fileInput" accept="image/*" capture="environment" style="display:none" onchange="onIdSelected(this)">
                </div>
                <div id="idFileName" style="margin:12px 0;font-weight:600;color:var(--primary-color);"></div>
                <button type="button" class="ripple-btn" id="btnToStep2" style="width:100%;padding:12px;background:var(--primary-color);color:#fff;border:none;border-radius:50px;cursor:pointer;" disabled>
                    ถัดไป — ถ่ายรูปใบหน้า
                </button>
            </div>

            <div id="step2" style="display:none;">
                <p style="font-size:14px;color:#555;margin-bottom:12px;">ถ่ายรูปใบหน้าตรง (Liveness) — หันหน้าเข้ากล้อง</p>
                <div style="text-align:center;">
                    <video id="cameraPreview" autoplay playsinline></video>
                    <canvas id="selfieCanvas" width="640" height="480"></canvas>
                    <img id="selfiePreview" style="max-width:320px;border-radius:12px;display:none;" alt="selfie preview">
                </div>
                <div class="capture-btns">
                    <button type="button" id="btnStartCam" class="btn-outline" style="padding:10px 16px;">📷 เปิดกล้อง</button>
                    <button type="button" id="btnCapture" class="btn-outline" style="padding:10px 16px;display:none;">ถ่ายรูป</button>
                    <button type="button" id="btnRetake" class="btn-outline" style="padding:10px 16px;display:none;">ถ่ายใหม่</button>
                </div>
                <input type="file" name="selfie" id="selfieFile" accept="image/*" style="display:none;">

                <label style="display:flex;align-items:flex-start;gap:10px;margin:20px 0 16px;padding:12px;background:#f8f9fa;border-radius:10px;cursor:pointer;">
                    <input type="checkbox" name="consent_kyc" value="1" required style="margin-top:4px;">
                    <span style="font-size:14px;">ยินยอมให้จัดเก็บข้อมูล KYC ตาม <a href="privacy_policy.php" target="_blank">นโยบายความเป็นส่วนตัว</a> (v<?php echo safeText(KYC_CONSENT_VERSION); ?>)</span>
                </label>

                <button type="submit" class="ripple-btn" id="btnSubmitKyc" style="width:100%;padding:12px;background:var(--primary-color);color:#fff;border:none;border-radius:50px;cursor:pointer;" disabled>
                    <?php echo $useAigen ? '🔍 ตรวจสอบและยืนยันตัวตน' : '📤 ส่งเอกสารยืนยันตัวตน'; ?>
                </button>
                <button type="button" id="btnBack1" style="width:100%;margin-top:8px;padding:10px;background:transparent;border:1px solid #ddd;border-radius:50px;cursor:pointer;">← กลับ</button>
            </div>
        </form>
        <?php if ($useAigen): ?>
        <p style="font-size:12px;color:#888;text-align:center;margin-top:12px;">
            ขับเคลื่อนโดย AiGen — OCR บัตรประชาชน + Face Compare
        </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($useSumsub): ?>
<script src="https://static.sumsub.com/idensic/static/sns-websdk-builder.js"></script>
<script>
(function() {
    const TOKEN_URL = <?php echo json_encode(BASE_URL . '/controllers/EkycController.php?action=sumsub_token'); ?>;
    const SYNC_URL = <?php echo json_encode(BASE_URL . '/controllers/EkycController.php'); ?>;
    const HAS_SDK = <?php echo $awaitingSumsubReview ? 'false' : 'true'; ?>;

    function syncKycStatus() {
        const fd = new FormData();
        fd.append('action', 'sync_status');
        fd.append('csrf_token', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
        return fetch(SYNC_URL, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.status !== 'success' || !res.data) {
                    throw new Error(res.message || 'sync failed');
                }
                return res.data;
            });
    }

    function handleSyncResult(data, showAlert) {
        if (data.verified || data.status === 'approved') {
            if (showAlert) {
                Swal.fire('สำเร็จ', 'ยืนยันตัวตน (eKYC) ผ่านแล้ว', 'success').then(function() {
                    window.location.href = 'dashboard.php?tab=profile';
                });
            } else {
                window.location.href = 'dashboard.php?tab=profile';
            }
            return true;
        }
        if (data.status === 'rejected' || data.answer === 'RED') {
            Swal.fire('ไม่ผ่าน', 'การยืนยันตัวตนไม่ผ่าน กรุณาลองใหม่', 'error').then(function() {
                window.location.reload();
            });
            return true;
        }
        return false;
    }

    function pollSync(maxAttempts, intervalMs) {
        var attempts = 0;
        var timer = setInterval(function() {
            attempts++;
            syncKycStatus()
                .then(function(data) {
                    if (handleSyncResult(data, false)) {
                        clearInterval(timer);
                    } else if (attempts >= maxAttempts) {
                        clearInterval(timer);
                    }
                })
                .catch(function() {
                    if (attempts >= maxAttempts) {
                        clearInterval(timer);
                    }
                });
        }, intervalMs);
    }

    document.getElementById('btnSyncKyc')?.addEventListener('click', function() {
        Swal.fire({ title: 'กำลังตรวจสอบ...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        syncKycStatus()
            .then(function(data) {
                if (!handleSyncResult(data, true)) {
                    Swal.fire('ยังไม่เสร็จ', 'ระบบยังตรวจสอบอยู่ กรุณารอสักครู่แล้วลองใหม่', 'info');
                }
            })
            .catch(function(err) {
                Swal.fire('ผิดพลาด', err.message || 'เชื่อมต่อไม่ได้', 'error');
            });
    });

    if (!HAS_SDK) {
        pollSync(20, 3000);
        return;
    }

    function fetchAccessToken() {
        return fetch(TOKEN_URL)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.status !== 'success' || !res.data || !res.data.token) {
                    throw new Error(res.message || 'ไม่สามารถสร้าง access token ได้');
                }
                return res.data.token;
            });
    }

    function launchWebSdk(accessToken) {
        if (typeof snsWebSdk === 'undefined') {
            throw new Error('Sumsub WebSDK โหลดไม่สำเร็จ');
        }

        var snsWebSdkInstance = snsWebSdk
            .init(accessToken, function() {
                return fetchAccessToken();
            })
            .withConf({
                lang: 'th',
            })
            .on('onError', function(error) {
                console.error('Sumsub onError', error);
                Swal.fire('ข้อผิดพลาด', (error && error.message) ? error.message : 'เกิดข้อผิดพลาดใน eKYC', 'error');
            })
            .onMessage(function(type, payload) {
                console.log('Sumsub onMessage', type, payload);
                if (type === 'idCheck.onApplicantSubmitted') {
                    Swal.fire('ส่งเอกสารแล้ว', 'ระบบกำลังตรวจสอบ กรุณารอผลยืนยันตัวตน', 'info');
                    pollSync(20, 3000);
                }
                if (type === 'idCheck.onApplicantStatusChanged' && payload && payload.reviewStatus === 'completed') {
                    syncKycStatus()
                        .then(function(data) {
                            if (!handleSyncResult(data, true)) {
                                var answer = payload.reviewResult && payload.reviewResult.reviewAnswer;
                                if (answer === 'GREEN') {
                                    Swal.fire('สำเร็จ', 'ยืนยันตัวตน (eKYC) ผ่านแล้ว', 'success').then(function() {
                                        window.location.href = 'dashboard.php?tab=profile';
                                    });
                                } else if (answer === 'RED') {
                                    Swal.fire('ไม่ผ่าน', 'การยืนยันตัวตนไม่ผ่าน กรุณาลองใหม่', 'error').then(function() {
                                        window.location.reload();
                                    });
                                } else {
                                    Swal.fire('รอตรวจสอบ', 'ส่งเอกสารแล้ว กรุณารอผลจากระบบ', 'info').then(function() {
                                        window.location.reload();
                                    });
                                }
                            }
                        })
                        .catch(function() {
                            Swal.fire('รอตรวจสอบ', 'ส่งเอกสารแล้ว กรุณากดตรวจสอบผลอีกครั้ง', 'info').then(function() {
                                window.location.reload();
                            });
                        });
                }
            })
            .build();

        snsWebSdkInstance.launch('#sumsub-websdk-container');
    }

    fetchAccessToken()
        .then(launchWebSdk)
        .catch(function(err) {
            console.error(err);
            document.getElementById('sumsub-websdk-container').innerHTML =
                '<p style="color:red;text-align:center;padding:24px;">' + (err.message || 'เชื่อมต่อ Sumsub ไม่ได้ — ตรวจสอบ API keys และ levelName') + '</p>';
        });
})();
</script>
<?php else: ?>
<script>
let stream = null;
function onIdSelected(input) {
    if (input.files[0]) {
        document.getElementById('idFileName').textContent = input.files[0].name;
        document.getElementById('btnToStep2').disabled = false;
    }
}
document.getElementById('btnToStep2')?.addEventListener('click', () => {
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = 'block';
    document.getElementById('stepLabel1').classList.remove('active');
    document.getElementById('stepLabel2').classList.add('active');
});
document.getElementById('btnBack1')?.addEventListener('click', () => {
    document.getElementById('step2').style.display = 'none';
    document.getElementById('step1').style.display = 'block';
    document.getElementById('stepLabel2').classList.remove('active');
    document.getElementById('stepLabel1').classList.add('active');
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
});
document.getElementById('btnStartCam')?.addEventListener('click', async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        const v = document.getElementById('cameraPreview');
        v.srcObject = stream;
        v.style.display = 'block';
        document.getElementById('btnCapture').style.display = 'inline-block';
        document.getElementById('selfiePreview').style.display = 'none';
    } catch (e) {
        Swal.fire('ผิดพลาด', 'ไม่สามารถเปิดกล้องได้ กรุณาอนุญาตการใช้กล้อง', 'error');
    }
});
document.getElementById('btnCapture')?.addEventListener('click', () => {
    const v = document.getElementById('cameraPreview');
    const c = document.getElementById('selfieCanvas');
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    c.toBlob(blob => {
        const file = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('selfieFile').files = dt.files;
        document.getElementById('selfiePreview').src = c.toDataURL('image/jpeg');
        document.getElementById('selfiePreview').style.display = 'block';
        v.style.display = 'none';
        document.getElementById('btnSubmitKyc').disabled = false;
        document.getElementById('btnRetake').style.display = 'inline-block';
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    }, 'image/jpeg', 0.9);
});
document.getElementById('btnRetake')?.addEventListener('click', () => {
    document.getElementById('selfiePreview').style.display = 'none';
    document.getElementById('btnSubmitKyc').disabled = true;
    document.getElementById('btnStartCam').click();
});
document.getElementById('kycForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    if (!document.getElementById('selfieFile').files.length) {
        Swal.fire('ผิดพลาด', 'กรุณาถ่ายรูปใบหน้า', 'error');
        return;
    }
    const useAigen = <?php echo $useAigen ? 'true' : 'false'; ?>;
    Swal.fire({
        title: useAigen ? 'กำลังตรวจสอบด้วย AiGen...' : 'กำลังอัปโหลด...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    fetch('<?php echo BASE_URL; ?>/controllers/UserController.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success' && data.verified) {
                Swal.fire('สำเร็จ', data.message || 'ยืนยันตัวตนผ่านแล้ว', 'success').then(() => {
                    window.location.href = 'dashboard.php?tab=profile';
                });
            } else if (data.status === 'success') {
                Swal.fire('สำเร็จ', data.message || 'ส่งเอกสารแล้ว รอแอดมินตรวจสอบ', 'success').then(() => location.reload());
            } else {
                Swal.fire('ไม่ผ่าน', data.message || 'อัปโหลดไม่สำเร็จ', 'error').then(() => {
                    if (data.kyc_status === 'rejected') location.reload();
                });
            }
        })
        .catch(() => Swal.fire('ผิดพลาด', 'เชื่อมต่อไม่ได้', 'error'));
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
