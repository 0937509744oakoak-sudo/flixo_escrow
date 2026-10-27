<?php

require_once 'includes/auth.php';
require_once 'includes/order_helper.php';
require_once 'includes/OrderStateMachine.php';
require_once 'includes/OmiseService.php';
require_once 'includes/PromptPay.php';

if (!isset($_SESSION['user']) || !isset($_GET['order_id'])) {
    redirectTo('dashboard.php');
}

$me = $_SESSION['user'];
$order_id = (int)$_GET['order_id'];
$order = getOrderIfOwner($conn, $order_id, $me, 'buyer');

if (!$order) {
    redirectTo('dashboard.php?tab=orders');
}

$orderPaid = ($order['status'] === OrderStateMachine::PAID);
$orderWaitingVerify = ($order['status'] === OrderStateMachine::WAITING_VERIFY);
$orderCanPay = ($order['status'] === OrderStateMachine::PENDING_PAYMENT);

if (!$orderCanPay && !$orderWaitingVerify && !$orderPaid) {
    redirectTo('dashboard.php?tab=orders');
}

$omiseEnabled = OmiseService::isConfigured();
$omisePublicKey = defined('OMISE_PUBLIC_KEY') ? OMISE_PUBLIC_KEY : '';
$showSandbox = $omiseEnabled && OMISE_SANDBOX && (defined('APP_ENV') ? APP_ENV !== 'production' : true);
// Never show sandbox hints when FORCE_HTTPS / production guard implies live
if (defined('FORCE_HTTPS') && FORCE_HTTPS) {
    $showSandbox = false;
}
// Default payment method: PromptPay first
$csrf = getCsrfToken();
$amountFormatted = number_format($order['amount'], 2);

$final_qr_image = '';
if ($order['status'] === OrderStateMachine::PENDING_PAYMENT) {
    try {
        $final_qr_image = promptPayQrImageUrl(buildPromptPayPayload(PROMPTPAY_NUMBER, (float) $order['amount']));
    } catch (InvalidArgumentException $e) {
        error_log('[PromptPay QR] ' . $e->getMessage());
    }
}

$page_title = 'ชำระเงิน';
$load_flixo_css = true;
include 'includes/header.php';
include 'includes/navbar.php';
?>
<style>
.pay-wrap { max-width: 560px; margin: 24px auto 48px; padding: 0 16px; }
.pay-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.pay-tab { flex: 1; min-width: 120px; padding: 12px; border: 1px solid var(--flixo-hairline); background: var(--flixo-card-bg, #fff); border-radius: var(--flixo-radius); cursor: pointer; font-weight: 600; text-align: center; font-size: 14px; color: var(--flixo-on-surface); transition: border-color .15s, background .15s, color .15s; }
.pay-tab.active { border-color: var(--flixo-primary); background: rgba(0, 80, 203, 0.08); color: var(--flixo-primary); }
.pay-panel { display: none; }
.pay-panel.active { display: block; }
.card-field { width: 100%; padding: 12px; border: 1px solid var(--flixo-hairline); border-radius: var(--flixo-radius-sm); margin-bottom: 10px; box-sizing: border-box; background: var(--flixo-card-bg, #fff); color: var(--flixo-on-surface); }
.card-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.omise-qr-wrap { text-align: center; padding: 16px; background: var(--flixo-muted-bg); border-radius: var(--flixo-radius); }
.sandbox-hint { background: #fff8e5; border: 1px solid #f0d78c; padding: 10px 14px; border-radius: var(--flixo-radius-sm); font-size: 13px; margin-bottom: 16px; color: #7a5b00; }
.pay-stepper { display: flex; gap: 4px; margin-bottom: 20px; }
.pay-step { flex: 1; text-align: center; font-size: 11px; padding: 8px 4px; border-radius: var(--flixo-radius-sm); background: var(--flixo-muted-bg); color: var(--flixo-on-surface-variant); }
.pay-step.done, .pay-step.current { background: rgba(0, 80, 203, 0.10); color: var(--flixo-primary); font-weight: 600; }
.pay-trust { padding: 14px 16px; background: rgba(0, 102, 69, 0.08); border: 1px solid rgba(0, 102, 69, 0.15); border-radius: var(--flixo-radius); margin-bottom: 20px; font-size: 14px; }
.pay-trust strong { display: block; margin-bottom: 4px; color: var(--flixo-tertiary); }
.pay-amount { text-align: center; margin-bottom: 8px; font-size: 28px; font-weight: 700; color: var(--flixo-on-surface); }
.pay-meta { text-align: center; color: var(--flixo-on-surface-variant); margin-bottom: 20px; font-size: 14px; }
</style>

<div class="pay-wrap">
    <div class="pay-stepper" aria-label="ขั้นตอนการซื้อ">
        <div class="pay-step done">สร้างออเดอร์</div>
        <div class="pay-step current">ชำระเงิน</div>
        <div class="pay-step">จัดส่ง</div>
        <div class="pay-step">รับสินค้า</div>
        <div class="pay-step">ปล่อยเงิน</div>
    </div>

    <div class="pay-trust">
        <strong>เงินพักไว้กับ FLIXO</strong>
        ผู้ขายจะได้รับเงินเมื่อคุณยืนยันว่ารับสินค้าครบแล้วเท่านั้น
    </div>

    <?php if ($orderPaid): ?>
        <div class="flixo-card" style="padding: 36px; text-align: center;">
            <h2>ชำระเงินแล้ว</h2>
            <p style="color: #7f8c8d;">ออเดอร์ #<?php echo (int)$order['id']; ?> ชำระเรียบร้อย — รอผู้ขายจัดส่ง</p>
            <a href="dashboard.php?tab=orders" class="btn-flixo-primary" style="display:inline-flex;margin-top:16px;padding:12px 20px;text-decoration:none;border-radius:12px;">ดูรายการของฉัน</a>
        </div>
    <?php elseif ($orderWaitingVerify): ?>
        <?php
            $slipUrl = !empty($order['slip_image']) ? orderSlipUrl($order['slip_image'], $order_id) : '';
            $slipMissing = !empty($order['slip_image']) && orderSlipPath($order['slip_image']) === null;
        ?>
        <div class="flixo-card" style="padding: 28px;">
            <h2 style="text-align:center;margin-top:0;">กำลังตรวจสอบยอดเงิน</h2>
            <p style="color:#7f8c8d;text-align:center;">ออเดอร์ #<?php echo (int)$order_id; ?> — รอระบบ/แอดมินตรวจสลิป</p>

            <?php if ($slipUrl !== '' && !$slipMissing): ?>
                <div style="text-align:center;margin:16px 0;">
                    <a href="<?php echo htmlspecialchars($slipUrl); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo htmlspecialchars($slipUrl); ?>" alt="สลิปที่ส่งแล้ว" style="max-width:min(280px,100%);border-radius:12px;border:1px solid #eee;">
                    </a>
                    <p style="font-size:13px;margin-top:8px;"><a href="<?php echo htmlspecialchars($slipUrl); ?>" target="_blank" rel="noopener">เปิดดูสลิปเต็ม</a></p>
                </div>
            <?php elseif ($slipMissing): ?>
                <p style="color:#c0392b;text-align:center;font-size:14px;">ไฟล์สลิปไม่พบบนเซิร์ฟเวอร์ — กรุณาอัปโหลดใหม่ด้านล่าง</p>
            <?php endif; ?>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
                <p style="font-weight:600;margin-bottom:8px;">อัปโหลดสลิปใหม่ (ถ้าต้องการ)</p>
                <form id="slipForm">
                    <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf); ?>">
                    <input type="hidden" name="action" value="upload_slip">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <input type="file" name="slip_image" accept="image/jpeg,image/png" required class="form-control" style="margin:8px 0;">
                    <button type="submit" class="btn-flixo-primary" style="width:100%;padding:14px;border:none;border-radius:12px;font-weight:600;cursor:pointer;">ส่งสลิปใหม่</button>
                </form>
            </div>
            <p style="text-align:center;margin-top:16px;">
                <a href="dashboard.php?tab=orders" class="btn-flixo-outline" style="display:inline-flex;padding:10px 16px;text-decoration:none;border-radius:12px;">กลับรายการของฉัน</a>
            </p>
        </div>
    <?php else: ?>
        <div class="flixo-card" style="padding: 24px;">
            <div class="pay-amount">฿<?php echo $amountFormatted; ?></div>
            <p class="pay-meta">ออเดอร์ #<?php echo (int)$order['id']; ?> — <?php echo safeText($order['item_name']); ?></p>

            <?php if ($showSandbox): ?>
            <div class="sandbox-hint">
                <strong>โหมดทดสอบ:</strong> บัตรทดสอบใช้ <code>4242 4242 4242 4242</code> วันหมดอายุอนาคต CVV 3 หลัก
            </div>
            <?php endif; ?>

            <div class="pay-tabs">
                <?php if ($omiseEnabled): ?>
                <button type="button" class="pay-tab active" data-tab="omise-pp">PromptPay</button>
                <button type="button" class="pay-tab" data-tab="card">บัตรเครดิต</button>
                <?php endif; ?>
                <button type="button" class="pay-tab <?php echo $omiseEnabled ? '' : 'active'; ?>" data-tab="slip"><?php echo $omiseEnabled ? 'โอน + สลิป' : 'PromptPay / สลิป'; ?></button>
            </div>

            <?php if ($omiseEnabled): ?>
            <div class="pay-panel active" id="panel-omise-pp">
                <div class="omise-qr-wrap">
                    <p style="margin:0 0 12px;font-weight:600;">สแกน QR PromptPay</p>
                    <div id="omiseQrBox">
                        <p style="color:#666;font-size:14px;">กำลังสร้าง QR...</p>
                    </div>
                    <p id="omisePollStatus" style="font-size:13px;color:#666;margin-top:10px;"></p>
                </div>
            </div>

            <div class="pay-panel" id="panel-card">
                <form id="cardForm">
                    <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf); ?>">
                    <input type="hidden" name="action" value="pay_omise_card">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <input type="hidden" name="omise_token" id="omiseToken">
                    <label>หมายเลขบัตร</label>
                    <input type="text" id="cardNumber" class="card-field" placeholder="หมายเลขบัตร" maxlength="19" autocomplete="cc-number" required>
                    <div class="card-row">
                        <div>
                            <label>วันหมดอายุ (MM/YY)</label>
                            <input type="text" id="cardExpiry" class="card-field" placeholder="12/28" maxlength="5" autocomplete="cc-exp" required>
                        </div>
                        <div>
                            <label>CVV</label>
                            <input type="text" id="cardCvv" class="card-field" placeholder="123" maxlength="4" autocomplete="cc-csc" required>
                        </div>
                    </div>
                    <label>ชื่อบนบัตร</label>
                    <input type="text" id="cardName" class="card-field" placeholder="NAME ON CARD" required>
                    <button type="submit" class="btn-flixo-primary" style="width:100%;margin-top:12px;padding:14px;border:none;border-radius:12px;font-weight:bold;cursor:pointer;">
                        ชำระ ฿<?php echo $amountFormatted; ?> ด้วยบัตร
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="pay-panel <?php echo $omiseEnabled ? '' : 'active'; ?>" id="panel-slip">
                <div style="text-align: center; margin-bottom: 20px; padding: 15px; background: #fdfdfd; border-radius: 12px; border: 1px solid #eaeaea;">
                    <p style="margin:0 0 8px;">สแกน QR หรือโอนเข้า PromptPay</p>
                    <h3 style="font-size: 24px;margin:0 0 12px;">฿<?php echo $amountFormatted; ?></h3>
                    <?php if ($final_qr_image): ?>
                        <img src="<?php echo safeText($final_qr_image); ?>" alt="QR PromptPay" style="max-width: 220px; border-radius: 8px;">
                    <?php else: ?>
                        <p style="color:#c0392b;font-size:13px;">โอนตามเลข PromptPay: <strong><?php echo safeText(PROMPTPAY_NUMBER); ?></strong></p>
                    <?php endif; ?>
                    <p style="font-size:12px;color:#888;margin-top:8px;"><?php echo safeText(PROMPTPAY_NAME); ?></p>
                </div>
                <form id="slipForm">
                    <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf); ?>">
                    <input type="hidden" name="action" value="upload_slip">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <label style="font-weight:bold;">อัปโหลดสลิป</label>
                    <input type="file" name="slip_image" accept="image/jpeg,image/png" required class="form-control" style="margin:8px 0;">
                    <button type="submit" class="btn-flixo-primary" style="width:100%;padding:14px;border:none;border-radius:12px;font-weight:600;cursor:pointer;">ยืนยันการชำระเงิน</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($omiseEnabled): ?>
<script src="https://cdn.omise.co/omise.js"></script>
<?php endif; ?>
<script>
const ORDER_ID = <?php echo (int)$order['id']; ?>;
const CSRF = <?php echo json_encode($csrf); ?>;
const API_URL = <?php echo json_encode(BASE_URL . '/controllers/OrderController.php'); ?>;
const OMISE_ENABLED = <?php echo $omiseEnabled ? 'true' : 'false'; ?>;
let omiseSourceId = null;
let omisePollTimer = null;
let omiseQrRequested = false;

document.querySelectorAll('.pay-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.pay-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const panel = document.getElementById('panel-' + btn.dataset.tab);
        if (panel) panel.classList.add('active');
        if (btn.dataset.tab === 'omise-pp') {
            ensureOmiseQr();
        }
    });
});

function postForm(data) {
    return fetch(API_URL, { method: 'POST', body: data }).then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch { throw new Error('ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง'); }
    });
}

document.getElementById('slipForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    Swal.fire({ title: 'กำลังส่งสลิป...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    postForm(new FormData(this)).then(data => {
        if (data.status === 'success') Swal.fire('สำเร็จ', data.message, 'success').then(() => location.reload());
        else Swal.fire('ชำระไม่สำเร็จ', data.message || 'กรุณาลองใหม่', 'error');
    }).catch(() => Swal.fire('ชำระไม่สำเร็จ', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'));
});

<?php if ($omiseEnabled): ?>
Omise.setPublicKey(<?php echo json_encode($omisePublicKey); ?>);

function ensureOmiseQr() {
    if (omiseQrRequested) return;
    omiseQrRequested = true;
    const box = document.getElementById('omiseQrBox');
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'create_omise_promptpay');
    fd.append('order_id', ORDER_ID);
    postForm(fd).then(data => {
        if (data.status !== 'success') {
            omiseQrRequested = false;
            if (box) box.innerHTML = '<p style="color:#c0392b;">สร้าง QR ไม่ได้ — ลองแท็บโอน + สลิป หรือรีเฟรชหน้า</p>';
            return;
        }
        omiseSourceId = data.source_id;
        if (box && data.qr_url) {
            box.innerHTML = '<img src="' + data.qr_url + '" alt="PromptPay QR" style="max-width:240px;border-radius:8px;"><p style="font-size:12px;color:#666;margin-top:8px;">สแกนแล้วรอระบบตรวจสอบอัตโนมัติ</p>';
        }
        startOmisePoll();
    }).catch(() => {
        omiseQrRequested = false;
        if (box) box.innerHTML = '<p style="color:#c0392b;">สร้าง QR ไม่ได้ กรุณารีเฟรชหน้า</p>';
    });
}

document.getElementById('cardForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const exp = document.getElementById('cardExpiry').value.split('/');
    if (exp.length !== 2) { Swal.fire('ข้อมูลไม่ครบ', 'รูปแบบวันหมดอายุต้องเป็น MM/YY', 'error'); return; }
    Swal.fire({ title: 'กำลังชำระเงิน...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    Omise.createToken('card', {
        name: document.getElementById('cardName').value,
        number: document.getElementById('cardNumber').value.replace(/\s/g, ''),
        expiration_month: exp[0].trim(),
        expiration_year: '20' + exp[1].trim(),
        security_code: document.getElementById('cardCvv').value
    }, (statusCode, response) => {
        if (statusCode !== 200) {
            const msg = response.message || 'ไม่สามารถใช้บัตรนี้ได้';
            Swal.fire('ชำระไม่สำเร็จ', msg, 'error');
            return;
        }
        const fd = new FormData(document.getElementById('cardForm'));
        fd.set('omise_token', response.id);
        postForm(fd).then(data => {
            if (data.status === 'success') Swal.fire('สำเร็จ', data.message, 'success').then(() => location.href = 'dashboard.php?tab=orders');
            else Swal.fire('ชำระไม่สำเร็จ', data.message || 'กรุณาลองใหม่', 'error');
        }).catch(err => Swal.fire('ชำระไม่สำเร็จ', err.message || 'ชำระไม่สำเร็จ', 'error'));
    });
});

function startOmisePoll() {
    if (omisePollTimer) clearInterval(omisePollTimer);
    const statusEl = document.getElementById('omisePollStatus');
    if (statusEl) statusEl.textContent = 'กำลังตรวจสอบการชำระเงินอัตโนมัติ...';
    omisePollTimer = setInterval(() => {
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'check_omise_promptpay');
        fd.append('order_id', ORDER_ID);
        fd.append('source_id', omiseSourceId);
        postForm(fd).then(data => {
            if (data.paid || data.status === 'success') {
                clearInterval(omisePollTimer);
                Swal.fire('สำเร็จ', data.message || 'ชำระเงินแล้ว', 'success').then(() => location.href = 'dashboard.php?tab=orders');
            }
        }).catch(() => {});
    }, 4000);
}

// Auto-create PromptPay QR on page load (default tab)
if (document.getElementById('panel-omise-pp')?.classList.contains('active')) {
    ensureOmiseQr();
}
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
