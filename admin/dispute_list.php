<?php
require 'admin_check.php';
require_once '../includes/OrderStateMachine.php';
require_once '../includes/Rbac.php';
require_once '../includes/DisputeFlowService.php';
require_once '../models/Dispute.php';

if (!Rbac::can($_SESSION['role'] ?? 'user', 'dispute.resolve')) {
    die('Access Denied');
}

$page_title = 'จัดการข้อพิพาท';
$csrf_token = getCsrfToken();
$disputeModel = new Dispute($conn);
$flowService = new DisputeFlowService($conn);
$openIn = "'SELLER_RESPONSE','EVIDENCE_REVIEW','SETTLEMENT_PENDING','PENDING'";

$stmt = $conn->prepare("SELECT d.*, o.item_name, o.buyer_name, o.seller_name, o.amount 
                        FROM disputes d 
                        JOIN orders o ON d.order_id = o.id 
                        WHERE d.status IN ({$openIn}) 
                        ORDER BY d.created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>⚖️ ข้อพิพาทที่รอดำเนินการ</h1>
                <p>ตัดสินข้อพิพาทระหว่างผู้ซื้อและผู้ขาย</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ออเดอร์</th>
                            <th>รายละเอียด</th>
                            <th>ยอด</th>
                            <th>SLA</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $row = $flowService->syncAndGet((int)$row['id']);
                            $phase = $flowService->getPhaseInfo($row);
                            $statusLabels = [
                                'SELLER_RESPONSE' => '⏳ รอผู้ขาย',
                                'EVIDENCE_REVIEW' => '📋 หลักฐาน',
                                'SETTLEMENT_PENDING' => '🤝 รอตกลง',
                                'PENDING' => '⏳ รอแก้ไข',
                            ];
                            $status_badge = $statusLabels[$row['status']] ?? safeText($phase['label'] ?? $row['status']);
                            $sla = $row['sla_due_at'] ?? null;
                            $slaClass = ($sla && strtotime($sla) < time()) ? 'sla-overdue' : '';
                            $judgeCheck = $flowService->canAdminJudge($row, false);
                        ?>
                            <tr>
                                <td>#<?php echo (int)$row['id']; ?></td>
                                <td>#<?php echo (int)$row['order_id']; ?> — <?php echo safeText($row['item_name']); ?></td>
                                <td>
                                    <small><strong><?php echo safeText($row['reason']); ?></strong><br>
                                    ซื้อ: <?php echo safeText($row['buyer_name']); ?> / ขาย: <?php echo safeText($row['seller_name']); ?></small>
                                </td>
                                <td class="admin-text-danger">฿<?php echo number_format((float)$row['amount']); ?></td>
                                <td class="<?php echo $slaClass; ?>"><?php echo $sla ? date('d/m/Y', strtotime($sla)) : '-'; ?></td>
                                <td><?php echo $status_badge; ?></td>
                                <td>
                                    <div class="admin-action-group">
                                        <a href="../dispute_detail.php?dispute_id=<?php echo (int)$row['id']; ?>" class="admin-btn admin-btn-info admin-btn-sm">ดูรายละเอียด</a>
                                        <button type="button" class="admin-btn admin-btn-sm" style="background:#6366f1;color:#fff;" onclick="quickAiAnalyze(<?php echo (int)$row['id']; ?>)">🤖 AI</button>
                                        <?php if ($judgeCheck['ok']): ?>
                                        <button type="button" class="admin-btn admin-btn-danger admin-btn-sm" onclick="judgeDispute(<?php echo (int)$row['order_id']; ?>, 'refund_buyer')">คืนผู้ซื้อ</button>
                                        <button type="button" class="admin-btn admin-btn-success admin-btn-sm" onclick="judgeDispute(<?php echo (int)$row['order_id']; ?>, 'payout_seller')">ให้ผู้ขาย</button>
                                        <button type="button" class="admin-btn admin-btn-warning admin-btn-sm" onclick="splitDispute(<?php echo (int)$row['order_id']; ?>, <?php echo (float)$row['amount']; ?>)">แบ่งเงิน</button>
                                        <?php else: ?>
                                        <span class="admin-text-muted" title="<?php echo safeText($judgeCheck['reason']); ?>">🔒 รอรอบ</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="empty">ไม่มีข้อพิพาทค้าง</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CSRF = <?php echo json_encode($csrf_token); ?>;

function judgeDispute(oid, action) {
    const title = action === 'refund_buyer' ? 'ยืนยันคืนเงินผู้ซื้อ?' : 'ยืนยันโอนเงินให้ผู้ขาย?';
    Swal.fire({ title, icon: 'warning', showCancelButton: true }).then(r => {
        if (!r.isConfirmed) return;
        postDispute({ action, order_id: oid });
    });
}

function splitDispute(oid, total) {
    Swal.fire({
        title: 'แบ่งเงิน (SPLIT)',
        html: `<p>ยอดรวม ฿${total.toLocaleString()}</p>
               <input id="sw-buyer" class="swal2-input" placeholder="คืนผู้ซื้อ (บาท)" type="number">
               <input id="sw-seller" class="swal2-input" placeholder="ให้ผู้ขาย (บาท)" type="number">`,
        showCancelButton: true,
        preConfirm: () => {
            const buyer = parseFloat(document.getElementById('sw-buyer').value) || 0;
            const seller = parseFloat(document.getElementById('sw-seller').value) || 0;
            if (Math.abs(buyer + seller - total) > 0.01) {
                Swal.showValidationMessage('ยอดรวมต้องเท่ากับยอดออเดอร์');
                return false;
            }
            return { buyer, seller };
        }
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        postDispute({ action: 'split_resolution', order_id: oid, buyer_amount: r.value.buyer, seller_amount: r.value.seller });
    });
}

function postDispute(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    fd.append('csrf_token', CSRF);
    fetch('../controllers/DisputeController.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(d => Swal.fire(d.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', d.message, d.status).then(() => { if (d.status === 'success') location.reload(); }));
}

function quickAiAnalyze(disputeId) {
    Swal.fire({ title: 'กำลังวิเคราะห์...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'analyze');
    fd.append('dispute_id', disputeId);
    fd.append('csrf_token', CSRF);
    fd.append('force', '1');
    fetch('../controllers/DisputeAssistantController.php', { method: 'POST', body: fd })
        .then(r => r.text().then(text => {
            try {
                return { ok: r.ok, data: JSON.parse(text) };
            } catch (e) {
                throw new Error(text ? text.substring(0, 150) : ('HTTP ' + r.status));
            }
        }))
        .then(({ data: d }) => {
            if (d.status !== 'success' || !d.analysis || d.analysis.status === 'error') {
                Swal.fire('ผิดพลาด', d.message || d.analysis?.error_message || 'วิเคราะห์ไม่สำเร็จ', 'error');
                return;
            }
            const a = d.analysis;
            const labels = { REFUND_BUYER: 'คืนผู้ซื้อ', PAYOUT_SELLER: 'ให้ผู้ขาย', SPLIT: 'แบ่งเงิน', NEEDS_REVIEW: 'ตรวจสอบเพิ่ม' };
            const rec = labels[a.recommended_action] || a.recommended_action;
            let html = '<div style="text-align:left;font-size:14px;line-height:1.55;">';
            html += '<p style="margin:0 0 12px;">' + (a.summary || '') + '</p>';
            html += '<p style="margin:0 0 8px;"><strong>แนะนำ:</strong> ' + rec + ' · ความมั่นใจ ' + (a.confidence || 0) + '%</p>';
            html += '<p style="margin:0 0 8px;">ผู้ซื้อ ' + (a.buyer_fault_pct || 0) + '% / ผู้ขาย ' + (a.seller_fault_pct || 0) + '%</p>';
            if (a.suggested_buyer_refund > 0 || a.suggested_seller_payout > 0) {
                html += '<p style="margin:0 0 8px;">คืนผู้ซื้อ ฿' + Number(a.suggested_buyer_refund).toLocaleString() + ' / ให้ผู้ขาย ฿' + Number(a.suggested_seller_payout).toLocaleString() + '</p>';
            }
            if (a.key_findings && a.key_findings.length) {
                html += '<ul style="margin:8px 0 0;padding-left:18px;font-size:13px;">';
                a.key_findings.forEach(f => { html += '<li style="margin:4px 0;">' + f + '</li>'; });
                html += '</ul>';
            }
            html += '</div>';
            Swal.fire({
                title: '🤖 AI Dispute Assistant',
                html,
                icon: 'info',
                width: 520,
                showCancelButton: true,
                confirmButtonText: 'ดูรายละเอียด',
                cancelButtonText: 'ปิด'
            }).then(r => {
                if (r.isConfirmed) window.location.href = '../dispute_detail.php?dispute_id=' + disputeId;
            });
        })
        .catch(err => Swal.fire('ผิดพลาด', err.message || 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'));
}
</script>

<?php include 'layout_footer.php'; ?>
