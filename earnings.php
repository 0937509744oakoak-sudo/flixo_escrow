<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/OrderStateMachine.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Wallet.php';
require_once __DIR__ . '/models/User.php';

$user = $_SESSION['user'];
$userModel = new User($conn);
$profile = $userModel->findByUsername($user);
$userId = (int)($profile['id'] ?? 0);
$orderModel = new Order($conn);
$walletModel = new Wallet($conn);

$earnings_stats = $orderModel->getEarningsStats($user);
$available = $earnings_stats['total'];
$escrow = $earnings_stats['pending'];
$total_earned = $earnings_stats['total_earned'] ?? 0;
$total_withdrawn = $earnings_stats['total_withdrawn'] ?? 0;
$balance_breakdown = $userId ? $walletModel->getAvailableBreakdown($userId) : ['from_sales' => 0, 'from_refunds' => 0, 'available' => 0];

$transactions = $userId ? $walletModel->getTransactions($userId, 40) : [];
$bank_accounts = $userId ? $walletModel->getBankAccounts($userId) : [];
$pending_withdrawals = $userId ? $walletModel->getPendingWithdrawals($userId) : [];
$res_history = $orderModel->getCompletedHistory($user);

$is_verified = !empty($profile['is_verified']);
$kyc_name = trim((string)($profile['real_name'] ?? ''));
$display_name = User::resolveDisplayName($kyc_name, $user);

$csrf_token = getCsrfToken();
$page_title = 'กระเป๋าเงิน';
$load_flixo_css = true;

$thai_banks = [
    ['code' => '002', 'name' => 'ธนาคารกรุงเทพ'],
    ['code' => '004', 'name' => 'ธนาคารกสิกรไทย'],
    ['code' => '006', 'name' => 'ธนาคารกรุงไทย'],
    ['code' => '011', 'name' => 'ธนาคารทหารไทยธนชาต'],
    ['code' => '014', 'name' => 'ธนาคารไทยพาณิชย์'],
    ['code' => '025', 'name' => 'ธนาคารกรุงศรีอยุธยา'],
];

$txTypeLabels = [
    'escrow_in' => 'รับเข้า Escrow',
    'escrow_release' => 'ปล่อยให้ถอนได้',
    'escrow_debit' => 'หัก Escrow',
    'refund_credit' => 'คืนเงิน (ผู้ซื้อ)',
    'split_refund' => 'คืนเงิน (แบ่ง)',
    'split_release' => 'รายได้ (แบ่ง)',
    'refund_reversal' => 'แก้ไขคืนเงินผิดพลาด',
    'withdrawal_hold' => 'ระงับรอถอน',
    'withdrawal_complete' => 'ถอนสำเร็จ',
    'withdrawal_reject' => 'คืนเงินถอน',
];

include 'includes/header.php';
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/earnings.css?v=<?php echo time(); ?>">
<?php include 'includes/navbar.php'; ?>

<div class="earnings-page">
    <a href="dashboard.php?tab=profile" class="earnings-back">
        <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
        กลับโปรไฟล์
    </a>

    <header class="earnings-hero">
        <h1>กระเป๋าเงิน</h1>
        <p>ยอดถอนได้ เงินใน Escrow และประวัติรายการของ @<?php echo safeText($user); ?></p>
    </header>

    <div class="earnings-stats">
        <div class="earnings-stat earnings-stat--available">
            <p class="earnings-stat-label">ถอนได้</p>
            <div class="earnings-stat-value">฿<?php echo number_format($available, 2); ?></div>
            <?php if ($balance_breakdown['from_refunds'] > 0): ?>
            <div class="earnings-stat-sub">
                จากการขาย ฿<?php echo number_format($balance_breakdown['from_sales'], 2); ?>
                · คืนเงินจากการซื้อ ฿<?php echo number_format($balance_breakdown['from_refunds'], 2); ?>
            </div>
            <?php elseif ($balance_breakdown['from_sales'] > 0): ?>
            <div class="earnings-stat-sub">จากการขายทั้งหมด</div>
            <?php endif; ?>
        </div>
        <div class="earnings-stat earnings-stat--escrow">
            <p class="earnings-stat-label">ใน Escrow</p>
            <div class="earnings-stat-value">฿<?php echo number_format($escrow, 2); ?></div>
            <div class="earnings-stat-sub">รอออเดอร์สำเร็จหรือตัดสินข้อพิพาท</div>
        </div>
        <div class="earnings-stat earnings-stat--meta">
            <p class="earnings-stat-label">รายได้รวม / ถอนแล้ว</p>
            <div class="earnings-stat-value" style="font-size:1.35rem;">฿<?php echo number_format($total_earned, 2); ?></div>
            <div class="earnings-stat-sub">ถอนแล้ว ฿<?php echo number_format($total_withdrawn, 2); ?></div>
        </div>
    </div>

    <?php if (!empty($pending_withdrawals)): ?>
    <div class="earnings-notice earnings-notice--warn">
        <span class="material-symbols-outlined">hourglass_top</span>
        <div>
            <strong>คำขอถอนรออนุมัติ</strong>
            <?php foreach ($pending_withdrawals as $pw): ?>
                <div>฿<?php echo number_format((float)$pw['amount'], 2); ?> — ส่งเมื่อ <?php echo date('d/m/Y H:i', strtotime($pw['created_at'])); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$is_verified): ?>
    <div class="earnings-notice earnings-notice--warn">
        <span class="material-symbols-outlined">verified_user</span>
        <div>
            <strong>ต้องยืนยันตัวตน (KYC) ก่อนถอนเงิน</strong>
            <div>เมื่อผ่าน KYC แล้ว ชื่อบัญชีธนาคารต้องตรงกับชื่อที่ยืนยันในบัตรประชาชน</div>
            <a href="kyc_verify.php" class="btn-flixo-primary" style="display:inline-block;margin-top:10px;padding:8px 16px;">ยืนยันตัวตน</a>
        </div>
    </div>
    <?php else: ?>
    <div class="earnings-notice earnings-notice--info">
        <span class="material-symbols-outlined">account_balance</span>
        <div>
            <strong>ชื่อบัญชีต้องตรงกับผู้ที่ยืนยันตัวตน (KYC)</strong>
            <div>ใช้ชื่อบัญชีว่า <strong><?php echo safeText($kyc_name !== '' ? $kyc_name : $display_name); ?></strong> เท่านั้น — หากไม่ตรง ระบบจะไม่บันทึกบัญชีและแอดมินอาจปฏิเสธการถอน</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="flixo-card" style="margin-bottom:20px;">
        <div class="earnings-card-head">
            <span class="material-symbols-outlined">account_balance</span>
            <h2>บัญชีรับเงิน</h2>
        </div>

        <?php if (!empty($bank_accounts)): ?>
        <div class="earnings-bank-current">
            <strong>บัญชีหลัก:</strong>
            <?php echo safeText($bank_accounts[0]['bank_name']); ?> —
            <?php echo safeText($bank_accounts[0]['account_name']); ?>
            (<?php echo safeText($bank_accounts[0]['account_number_masked']); ?>)
        </div>
        <?php endif; ?>

        <form id="bankForm">
            <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
            <input type="hidden" name="action" value="save_bank">
            <div class="earnings-form-grid">
                <select name="bank_code" id="bankCode" class="earnings-field" required>
                    <option value="">เลือกธนาคาร</option>
                    <?php foreach ($thai_banks as $b): ?>
                    <option value="<?php echo safeText($b['code']); ?>" data-name="<?php echo safeText($b['name']); ?>"><?php echo safeText($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="bank_name" id="bankName">
                <input type="text" name="account_number" class="earnings-field" placeholder="เลขบัญชี 10-15 หลัก" required maxlength="15" pattern="[0-9]{10,15}">
            </div>
            <input type="text" name="account_name" class="earnings-full-input" style="margin-bottom:12px;"
                   placeholder="<?php echo $is_verified && $kyc_name !== '' ? 'ชื่อบัญชี: ' . safeText($kyc_name) : 'ชื่อบัญชี (ภาษาไทย — ต้องตรง KYC)'; ?>"
                   <?php if ($is_verified && $kyc_name !== ''): ?>value="<?php echo safeText($kyc_name); ?>"<?php endif; ?>
                   required maxlength="150">
            <p class="tx-meta" style="margin:0 0 12px;">ชื่อบัญชีต้องตรงกับชื่อที่ยืนยันตัวตน<?php echo $kyc_name !== '' ? ' (' . safeText($kyc_name) . ')' : ''; ?></p>
            <button type="submit" class="btn-flixo-primary">บันทึกบัญชีธนาคาร</button>
        </form>
    </div>

    <div class="flixo-card" style="margin-bottom:20px;">
        <div class="earnings-card-head">
            <span class="material-symbols-outlined">payments</span>
            <h2>ถอนเงิน</h2>
        </div>
        <p class="tx-meta" style="margin:0 0 14px;">ขั้นต่ำ ฿100 — ต้องผ่าน KYC และมีบัญชีธนาคารที่ชื่อตรงกับ KYC</p>
        <form id="withdrawForm">
            <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="bank_account_id" value="<?php echo !empty($bank_accounts[0]['id']) ? (int)$bank_accounts[0]['id'] : 0; ?>">
            <div class="earnings-withdraw-row">
                <div>
                    <label class="tx-meta" for="withdraw-amount">จำนวนเงิน (บาท)</label>
                    <input type="number" id="withdraw-amount" name="amount" class="earnings-field" min="100" max="<?php echo max(0, floor($available)); ?>" step="0.01" placeholder="เช่น 500" required>
                </div>
                <button type="submit" class="btn-flixo-primary"
                    <?php echo (empty($bank_accounts) || !$is_verified) ? 'disabled title="ต้องมีบัญชีธนาคารและผ่าน KYC"' : ''; ?>>
                    ขอถอนเงิน
                </button>
            </div>
        </form>
    </div>

    <div class="flixo-card" style="margin-bottom:20px;">
        <div class="earnings-card-head">
            <span class="material-symbols-outlined">receipt_long</span>
            <h2>รายการล่าสุด</h2>
        </div>
        <div class="earnings-table-wrap">
            <table class="earnings-table">
                <thead>
                    <tr><th>วันที่</th><th>ประเภท</th><th>จำนวน</th><th>รายละเอียด</th></tr>
                </thead>
                <tbody>
                <?php if (!empty($transactions)): foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($tx['created_at'])); ?></td>
                        <td><span class="earnings-type-badge"><?php echo safeText($txTypeLabels[$tx['type']] ?? $tx['type']); ?></span></td>
                        <td class="<?php echo $tx['direction'] === 'credit' ? 'earnings-amount--credit' : 'earnings-amount--debit'; ?>">
                            <?php echo $tx['direction'] === 'credit' ? '+' : '-'; ?>฿<?php echo number_format((float)$tx['amount'], 2); ?>
                        </td>
                        <td><?php echo safeText($tx['description']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="earnings-empty">ยังไม่มีรายการ — เมื่อออเดอร์สำเร็จหรือมีการคืนเงิน รายการจะแสดงที่นี่</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flixo-card">
        <div class="earnings-card-head">
            <span class="material-symbols-outlined">shopping_bag</span>
            <h2>ออเดอร์ขายสำเร็จ</h2>
        </div>
        <div class="earnings-table-wrap">
            <table class="earnings-table">
                <thead>
                    <tr><th>วันที่</th><th>สินค้า</th><th>ผู้ซื้อ</th><th>ยอด</th></tr>
                </thead>
                <tbody>
                <?php if ($res_history && $res_history->num_rows > 0): while ($row = $res_history->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></td>
                        <td><?php echo safeText($row['product_name'] ?? $row['item_name']); ?></td>
                        <td>@<?php echo safeText($row['buyer_name']); ?></td>
                        <td class="earnings-amount--credit">+฿<?php echo number_format((float)$row['amount'], 2); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="4" class="earnings-empty">ยังไม่มีออเดอร์สำเร็จ</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('bankCode')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('bankName').value = opt.dataset.name || '';
});

document.getElementById('bankForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    fetch('<?php echo BASE_URL; ?>/controllers/WalletController.php', { method: 'POST', body: new FormData(this), credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            if (d.status === 'success') {
                Swal.fire('สำเร็จ', 'บันทึกบัญชีแล้ว', 'success').then(() => location.reload());
            } else {
                Swal.fire('ผิดพลาด', d.message || 'ไม่สำเร็จ', 'error');
            }
        })
        .catch(() => Swal.fire('ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'));
});

document.getElementById('withdrawForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    Swal.fire({ title: 'ยืนยันถอนเงิน?', icon: 'question', showCancelButton: true }).then(r => {
        if (!r.isConfirmed) return;
        fetch('<?php echo BASE_URL; ?>/controllers/WalletController.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(res => res.json()).then(d => {
                if (d.status === 'success') {
                    Swal.fire('สำเร็จ', d.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('ผิดพลาด', d.message, 'error');
                }
            })
            .catch(() => Swal.fire('ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'));
    });
});
</script>

<?php include 'includes/footer.php'; ?>
