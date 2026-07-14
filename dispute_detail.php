<?php
// dispute_detail.php — รายละเอียดและจัดการข้อพิพาท
require_once 'includes/init.php';
require_once 'models/Dispute.php';
require_once 'models/Order.php';
require_once 'includes/Rbac.php';
require_once 'includes/DisputeFlowService.php';
require_once 'includes/DisputeAssistantService.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$me = $_SESSION['user'];
$myRole = $_SESSION['role'] ?? 'user';

$dispute_id = (int)($_GET['dispute_id'] ?? 0);
if (!$dispute_id) {
    header('Location: my_disputes.php');
    exit;
}

$disputeModel = new Dispute($conn);
$dispute = $disputeModel->findById($dispute_id);

if (!$dispute) {
    die('ไม่พบข้อพิพาทนี้');
}

$flowService = new DisputeFlowService($conn);
$dispute = $flowService->syncAndGet($dispute_id);

$orderModel = new Order($conn);
$order = $orderModel->findById($dispute['order_id']);

if (!$order || ($order['buyer_name'] !== $me &&
                $order['seller_name'] !== $me &&
                !Rbac::can($myRole, 'dispute.resolve'))) {
    die('ไม่มีสิทธิ์ดูข้อพิพาทนี้');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    if (!empty($_POST['add_comment'])) {
        $comment = trim($_POST['comment'] ?? '');
        if ($comment !== '') {
            $disputeModel->addComment($dispute_id, $me, $comment);
            header('Location: dispute_detail.php?dispute_id=' . $dispute_id);
            exit;
        }
    }

    if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['size'] > 0) {
        $file = $_FILES['evidence_file'];
        $maxSize = 5 * 1024 * 1024;
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file['size'] > $maxSize) {
            $error = 'ไฟล์ใหญ่เกินไป (สูงสุด 5 MB)';
        } elseif (!in_array($ext, $allowed, true)) {
            $error = 'ประเภทไฟล์ไม่อนุญาต';
        } else {
            $filename = 'dispute_' . $dispute_id . '_' . time() . '.' . $ext;
            $filepath = 'uploads/disputes/' . $filename;

            if (!is_dir('uploads/disputes')) {
                mkdir('uploads/disputes', 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $description = trim($_POST['evidence_description'] ?? '');
                $disputeModel->addEvidence($dispute_id, $me, $description, $filepath);
                header('Location: dispute_detail.php?dispute_id=' . $dispute_id);
                exit;
            }
            $error = 'ไม่สามารถอัปโหลดไฟล์';
        }
    }
}

$evidenceRows = [];
$evResult = $disputeModel->getEvidences($dispute_id);
while ($row = $evResult->fetch_assoc()) {
    $evidenceRows[] = $row;
}

$commentRows = [];
$cmResult = $disputeModel->getComments($dispute_id);
while ($row = $cmResult->fetch_assoc()) {
    $commentRows[] = $row;
}

$csrf_token = getCsrfToken();
$isAdmin = Rbac::can($myRole, 'dispute.resolve');
$isOpen = in_array($dispute['status'], DisputeFlowService::openStatuses(), true);
$isBuyer = ($me === $order['buyer_name']);
$isSeller = ($me === $order['seller_name']);
$phaseInfo = $flowService->getPhaseInfo($dispute);
$activeSettlement = $flowService->getActiveSettlement($dispute_id);
$adminJudge = $isAdmin ? $flowService->canAdminJudge($dispute, false) : ['ok' => false, 'reason' => ''];
$canUploadEvidence = $isOpen && (
    in_array($dispute['status'] ?? '', ['EVIDENCE_REVIEW', 'SETTLEMENT_PENDING'], true)
    || (($dispute['status'] ?? '') === 'SELLER_RESPONSE' && $isSeller)
);
$canComment = $isOpen && !($isSeller && ($dispute['status'] ?? '') === 'SELLER_RESPONSE' && empty($dispute['seller_responded_at']));

$buyerEvidences = [];
$sellerEvidences = [];
$otherEvidences = [];
foreach ($evidenceRows as $row) {
    $uploader = $row['uploader'] ?? '';
    if ($uploader === $order['buyer_name']) {
        $buyerEvidences[] = $row;
    } elseif ($uploader === $order['seller_name']) {
        $sellerEvidences[] = $row;
    } else {
        $otherEvidences[] = $row;
    }
}

$buyerCanUpload = $canUploadEvidence && $isBuyer;
$sellerCanUpload = $canUploadEvidence && $isSeller;
$buyerWaitHint = $isOpen && ($dispute['status'] ?? '') === 'SELLER_RESPONSE' && !$buyerCanUpload;
$sellerWaitHint = false;

$latestAiAnalysis = null;
if ($isAdmin && $isOpen) {
    $aiService = new DisputeAssistantService($conn);
    $latestAiAnalysis = $aiService->getLatestFormatted($dispute_id);
}

$statusMap = [
    'SELLER_RESPONSE' => ['label' => 'รอผู้ขายชี้แจง', 'class' => 'is-open'],
    'PENDING' => ['label' => 'รอดำเนินการ', 'class' => 'is-open'],
    'EVIDENCE_REVIEW' => ['label' => 'ตรวจหลักฐาน', 'class' => 'is-review'],
    'SETTLEMENT_PENDING' => ['label' => 'รอตกลงกัน', 'class' => 'is-review'],
    'RESOLVED' => ['label' => 'แก้ไขแล้ว', 'class' => 'is-resolved'],
];
$statusMeta = $statusMap[$dispute['status']] ?? ['label' => $dispute['status'], 'class' => 'is-open'];

$resolutionLabels = [
    'REFUND_BUYER' => 'คืนเงินผู้ซื้อ',
    'PAYOUT_SELLER' => 'โอนให้ผู้ขาย',
    'SPLIT' => 'แบ่งเงินทั้งสองฝ่าย',
];

$page_title = 'ข้อพิพาท #' . $dispute_id;
$load_flixo_css = true;
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/dispute-detail.css?v=<?php echo time(); ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include 'includes/navbar.php'; ?>

<div class="dispute-page">
    <a href="my_disputes.php" class="dispute-back">
        <span class="material-symbols-outlined">arrow_back</span>
        ข้อพิพาทของฉัน
    </a>

    <?php if ($error): ?>
        <div class="dispute-alert-error">
            <span class="material-symbols-outlined">error</span>
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <header class="dispute-hero">
        <div class="dispute-hero-top">
            <div>
                <span class="dispute-hero-badge">ข้อพิพาท #<?php echo (int)$dispute_id; ?> · ออเดอร์ #<?php echo (int)$order['id']; ?></span>
                <h1><?php echo safeText($order['item_name']); ?></h1>
            </div>
            <span class="dispute-status-pill <?php echo safeText($statusMeta['class']); ?>">
                <?php echo safeText($statusMeta['label']); ?>
            </span>
        </div>

        <div class="dispute-hero-stats">
            <div class="dispute-stat dispute-stat-amount">
                <label>ยอด Escrow</label>
                <strong>฿<?php echo number_format((float)$order['amount'], 2); ?></strong>
            </div>
            <div class="dispute-stat">
                <label>เปิดเมื่อ</label>
                <strong><?php echo date('d/m/Y H:i', strtotime($dispute['created_at'])); ?></strong>
            </div>
            <?php if (!empty($dispute['sla_due_at']) && $isOpen): ?>
                <?php $slaOverdue = strtotime($dispute['sla_due_at']) < time(); ?>
                <div class="dispute-stat">
                    <label>กำหนดตัดสิน (SLA)</label>
                    <strong style="<?php echo $slaOverdue ? 'color:#fecaca;' : ''; ?>">
                        <?php echo date('d/m/Y', strtotime($dispute['sla_due_at'])); ?>
                        <?php echo $slaOverdue ? ' (เลยกำหนด)' : ''; ?>
                    </strong>
                </div>
            <?php endif; ?>
            <?php if ($dispute['status'] === 'RESOLVED' && !empty($dispute['resolved_at'])): ?>
                <div class="dispute-stat">
                    <label>ตัดสินเมื่อ</label>
                    <strong><?php echo date('d/m/Y H:i', strtotime($dispute['resolved_at'])); ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isOpen): ?>
            <div class="dispute-notice">
                <span class="material-symbols-outlined">lock</span>
                <span>เงินถูก<strong>ระงับใน Escrow</strong> ชั่วคราว — กรุณาแนบหลักฐานและแสดงความเห็นเพื่อให้ทีมงานตรวจสอบ</span>
            </div>
        <?php endif; ?>

        <?php if ($isOpen): ?>
            <nav class="dispute-phase-timeline" aria-label="ขั้นตอนข้อพิพาท">
                <div class="dispute-phase-step<?php echo ($phaseInfo['step'] ?? 0) >= 1 ? ' is-active' : ''; ?><?php echo ($phaseInfo['step'] ?? 0) > 1 ? ' is-done' : ''; ?>">
                    <span class="dispute-phase-num">1</span>
                    <span class="dispute-phase-text">ผู้ขายชี้แจง</span>
                </div>
                <div class="dispute-phase-step<?php echo ($phaseInfo['step'] ?? 0) >= 2 ? ' is-active' : ''; ?><?php echo ($phaseInfo['step'] ?? 0) > 2 ? ' is-done' : ''; ?>">
                    <span class="dispute-phase-num">2</span>
                    <span class="dispute-phase-text">หลักฐาน / ตกลงกัน</span>
                </div>
                <div class="dispute-phase-step<?php echo ($phaseInfo['step'] ?? 0) >= 3 ? ' is-active' : ''; ?>">
                    <span class="dispute-phase-num">3</span>
                    <span class="dispute-phase-text">ตัดสิน</span>
                </div>
            </nav>
            <div class="dispute-phase-banner<?php echo !empty($phaseInfo['overdue']) ? ' is-overdue' : ''; ?>">
                <strong><?php echo safeText($phaseInfo['label'] ?? ''); ?></strong>
                <p><?php echo safeText($phaseInfo['description'] ?? ''); ?></p>
                <?php if (!empty($phaseInfo['deadline_label'])): ?>
                    <small>กำหนด: <?php echo safeText($phaseInfo['deadline_label']); ?><?php echo !empty($phaseInfo['overdue']) ? ' (เลยกำหนดแล้ว)' : ''; ?></small>
                <?php endif; ?>
                <?php if (!empty($phaseInfo['seller_note'])): ?>
                    <small class="dispute-phase-note"><?php echo safeText($phaseInfo['seller_note']); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="dispute-layout">
        <main class="dispute-main">

            <section class="dispute-card">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">report</span>
                    <h2>เหตุผลข้อพิพาท</h2>
                </div>
                <p class="dispute-reason-title"><?php echo safeText($dispute['reason']); ?></p>
                <?php if (!empty($dispute['description'])): ?>
                    <p class="dispute-reason-desc"><?php echo nl2br(safeText($dispute['description'])); ?></p>
                <?php else: ?>
                    <p class="dispute-reason-desc" style="color:#94a3b8;">ไม่มีรายละเอียดเพิ่มเติม</p>
                <?php endif; ?>
            </section>

            <?php if (!empty($dispute['seller_response'])): ?>
            <section class="dispute-card dispute-seller-statement">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">record_voice_over</span>
                    <h2>คำชี้แจงผู้ขาย</h2>
                    <?php if (!empty($dispute['seller_responded_at'])): ?>
                        <span><?php echo date('d/m/Y H:i', strtotime($dispute['seller_responded_at'])); ?></span>
                    <?php endif; ?>
                </div>
                <p class="dispute-reason-desc"><?php echo nl2br(safeText($dispute['seller_response'])); ?></p>
            </section>
            <?php elseif ($isOpen && !empty($dispute['seller_no_response'])): ?>
            <section class="dispute-card">
                <div class="dispute-empty">
                    <span class="material-symbols-outlined">schedule</span>
                    ผู้ขายไม่ได้ชี้แจงภายในเวลาที่กำหนด
                </div>
            </section>
            <?php endif; ?>

            <?php if ($isOpen && $isSeller && ($dispute['status'] ?? '') === 'SELLER_RESPONSE' && empty($dispute['seller_responded_at'])): ?>
            <section class="dispute-card dispute-seller-form">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">edit_note</span>
                    <h2>ชี้แจงข้อกล่าวหา (ผู้ขาย)</h2>
                </div>
                <p class="dispute-form-hint">อธิบายว่าจัดส่งแล้วหรือไม่ มีเลขพัสดุหรือไม่ และโต้แย้งข้อกล่าวหา — หลังส่งแล้วจะเข้าสู่ช่วงแนบหลักฐาน</p>
                <textarea id="seller-response-text" class="dispute-textarea" placeholder="เช่น ส่งสินค้าแล้วเมื่อวันที่... เลขพัสดุ..."></textarea>
                <button type="button" class="dispute-btn-submit" onclick="submitSellerResponse()">
                    <span class="material-symbols-outlined" style="font-size:18px;">send</span>
                    ส่งคำชี้แจง
                </button>
            </section>
            <?php endif; ?>

            <?php if ($isOpen && $activeSettlement): ?>
            <section class="dispute-card dispute-settlement-card">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">handshake</span>
                    <h2>ข้อเสนอตกลงกัน</h2>
                </div>
                <div class="dispute-settlement-offer">
                    <p><strong><?php echo safeText($activeSettlement['proposer']); ?></strong> เสนอแบ่งเงิน:</p>
                    <ul>
                        <li>คืนผู้ซื้อ: <strong>฿<?php echo number_format((float)$activeSettlement['buyer_amount'], 2); ?></strong></li>
                        <li>ให้ผู้ขาย: <strong>฿<?php echo number_format((float)$activeSettlement['seller_amount'], 2); ?></strong></li>
                    </ul>
                    <?php if (!empty($activeSettlement['message'])): ?>
                        <p class="dispute-reason-desc"><?php echo nl2br(safeText($activeSettlement['message'])); ?></p>
                    <?php endif; ?>
                    <?php if ($activeSettlement['proposer'] !== $me): ?>
                        <div class="dispute-settlement-actions">
                            <button type="button" class="dispute-btn-submit" onclick="respondSettlement('accept')">ยอมรับข้อเสนอ</button>
                            <button type="button" class="dispute-btn-submit secondary" onclick="respondSettlement('reject')">ปฏิเสธ</button>
                            <button type="button" class="dispute-btn-submit secondary" onclick="openCounterOffer()">เสนอ counter-offer</button>
                        </div>
                    <?php else: ?>
                        <p class="dispute-form-hint">รอ <?php echo safeText($isBuyer ? $order['seller_name'] : $order['buyer_name']); ?> ตอบรับ</p>
                    <?php endif; ?>
                </div>
            </section>
            <?php elseif ($isOpen && ($dispute['status'] ?? '') === 'EVIDENCE_REVIEW'): ?>
            <section class="dispute-card">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">handshake</span>
                    <h2>เสนอข้อตกลง (ไม่บังคับ)</h2>
                </div>
                <p class="dispute-form-hint">หากตกลงแบ่งเงินได้ อีกฝ่ายต้องกดยอมรับ — ไม่ต้องรอแอดมิน</p>
                <button type="button" class="dispute-btn-submit secondary" onclick="openProposeSettlement()">เสนอข้อตกลงแบ่งเงิน</button>
            </section>
            <?php endif; ?>

            <section class="dispute-card">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">attach_file</span>
                    <h2>หลักฐาน</h2>
                    <span><?php echo count($evidenceRows); ?> รายการทั้งหมด</span>
                </div>

                <div class="dispute-evidence-split">
                    <?php
                    $laneTitle = 'ผู้ซื้อ';
                    $laneSubtitle = $order['buyer_name'] . ($isBuyer ? ' (คุณ)' : '');
                    $laneRole = 'buyer';
                    $laneItems = $buyerEvidences;
                    $showUpload = $buyerCanUpload;
                    $showWaitHint = $buyerWaitHint && !$showUpload;
                    $waitHint = 'รอผู้ขายชี้แจงก่อน — หลังจากนั้นจะแนบหลักฐานเพิ่มได้';
                    include __DIR__ . '/includes/partials/dispute_evidence_lane.php';

                    $laneTitle = 'ผู้ขาย';
                    $laneSubtitle = $order['seller_name'] . ($isSeller ? ' (คุณ)' : '');
                    $laneRole = 'seller';
                    $laneItems = $sellerEvidences;
                    $showUpload = $sellerCanUpload;
                    $showWaitHint = $sellerWaitHint;
                    $waitHint = '';
                    include __DIR__ . '/includes/partials/dispute_evidence_lane.php';
                    ?>
                </div>

                <?php if (!empty($otherEvidences)): ?>
                    <div class="dispute-evidence-other">
                        <h4>หลักฐานจากแอดมิน / อื่น ๆ</h4>
                        <div class="dispute-evidence-grid">
                            <?php foreach ($otherEvidences as $evidence):
                                $ext = strtolower(pathinfo($evidence['file_path'], PATHINFO_EXTENSION));
                                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
                                $fileUrl = htmlspecialchars($evidence['file_path'], ENT_QUOTES, 'UTF-8');
                            ?>
                                <a href="<?php echo $fileUrl; ?>" target="_blank" rel="noopener" class="dispute-evidence-item">
                                    <div class="dispute-evidence-thumb">
                                        <?php if ($isImage): ?>
                                            <img src="<?php echo $fileUrl; ?>" alt="หลักฐาน">
                                        <?php elseif ($ext === 'pdf'): ?>
                                            <span class="file-icon">📄</span>
                                        <?php else: ?>
                                            <span class="file-icon">📎</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="dispute-evidence-meta">
                                        <strong><?php echo safeText($evidence['uploader']); ?></strong>
                                        <?php if (!empty($evidence['description'])): ?>
                                            <small><?php echo safeText(mb_strimwidth($evidence['description'], 0, 40, '…')); ?></small>
                                        <?php endif; ?>
                                        <small><?php echo date('d/m/Y H:i', strtotime($evidence['created_at'])); ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dispute-card">
                <div class="dispute-card-head">
                    <span class="material-symbols-outlined">forum</span>
                    <h2>ความเห็น</h2>
                    <span><?php echo count($commentRows); ?> ข้อความ</span>
                </div>

                <?php if ($commentRows): ?>
                    <div class="dispute-thread">
                        <?php foreach ($commentRows as $comment):
                            $isMe = ($comment['commenter'] === $me);
                            $initial = mb_strtoupper(mb_substr($comment['commenter'], 0, 1));
                        ?>
                            <article class="dispute-comment<?php echo $isMe ? ' is-me' : ''; ?>">
                                <div class="dispute-comment-avatar"><?php echo safeText($initial); ?></div>
                                <div class="dispute-comment-body">
                                    <div class="dispute-comment-head">
                                        <strong><?php echo safeText($comment['commenter']); ?><?php echo $isMe ? ' (คุณ)' : ''; ?></strong>
                                        <time datetime="<?php echo safeText($comment['created_at']); ?>">
                                            <?php echo date('d/m/Y H:i', strtotime($comment['created_at'])); ?>
                                        </time>
                                    </div>
                                    <p><?php echo nl2br(safeText($comment['comment'])); ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dispute-empty">
                        <span class="material-symbols-outlined">chat_bubble_outline</span>
                        ยังไม่มีความเห็น
                    </div>
                <?php endif; ?>

                <?php if ($canComment): ?>
                    <form method="POST" class="dispute-form">
                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                        <input type="hidden" name="add_comment" value="1">
                        <textarea name="comment" class="dispute-textarea" placeholder="แสดงความเห็นหรืออธิบายเพิ่มเติม..." required></textarea>
                        <button type="submit" class="dispute-btn-submit secondary">
                            <span class="material-symbols-outlined" style="font-size:18px;">send</span>
                            ส่งความเห็น
                        </button>
                    </form>
                <?php endif; ?>
            </section>

            <?php if ($isAdmin && $isOpen): ?>
                <section class="dispute-ai-panel" id="ai-panel">
                    <h2>
                        <span class="material-symbols-outlined" style="font-size:22px;color:#6366f1;">smart_toy</span>
                        AI Dispute Assistant
                    </h2>
                    <p class="dispute-ai-sub">
                        <?php echo DisputeAssistantService::isAiConfigured() ? 'OpenRouter AI — วิเคราะห์จากแชท, ขนส่ง และหลักฐาน' : 'Heuristic mode — ตั้ง OPENROUTER_API_KEY เพื่อ AI เต็มรูปแบบ'; ?>
                    </p>
                    <div id="ai-content">
                        <?php if ($latestAiAnalysis): ?>
                            <?php include __DIR__ . '/includes/partials/dispute_ai_result.php'; ?>
                        <?php else: ?>
                            <p class="ai-empty">ยังไม่มีการวิเคราะห์ — กดปุ่มด้านล่างเพื่อให้ AI ช่วยประเมินคดีนี้</p>
                        <?php endif; ?>
                    </div>
                    <div class="dispute-ai-actions">
                        <button type="button" class="dispute-ai-btn primary" id="btn-ai-analyze" onclick="runAiAnalysis(false)">
                            <span class="material-symbols-outlined" style="font-size:18px;">search</span>
                            วิเคราะห์ด้วย AI
                        </button>
                        <button type="button" class="dispute-ai-btn secondary" id="btn-ai-refresh" onclick="runAiAnalysis(true)">
                            <span class="material-symbols-outlined" style="font-size:18px;">refresh</span>
                            วิเคราะห์ใหม่
                        </button>
                    </div>
                </section>

                <section class="dispute-admin-card">
                    <h2>
                        <span class="material-symbols-outlined">gavel</span>
                        ตัดสินข้อพิพาท
                    </h2>
                    <?php if (!$adminJudge['ok']): ?>
                        <div class="dispute-admin-guard">
                            <span class="material-symbols-outlined">info</span>
                            <?php echo safeText($adminJudge['reason']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="dispute-admin-actions<?php echo !$adminJudge['ok'] ? ' is-disabled' : ''; ?>">
                        <button type="button" class="dispute-admin-btn refund" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, 'refund_buyer')"<?php echo !$adminJudge['ok'] ? ' disabled' : ''; ?>>
                            <span class="material-symbols-outlined">undo</span>
                            คืนเงินผู้ซื้อ
                        </button>
                        <button type="button" class="dispute-admin-btn payout" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, 'payout_seller')"<?php echo !$adminJudge['ok'] ? ' disabled' : ''; ?>>
                            <span class="material-symbols-outlined">payments</span>
                            โอนให้ผู้ขาย
                        </button>
                        <button type="button" class="dispute-admin-btn split" onclick="splitDispute(<?php echo (int)$dispute['order_id']; ?>, <?php echo (float)$order['amount']; ?>)"<?php echo !$adminJudge['ok'] ? ' disabled' : ''; ?>>
                            <span class="material-symbols-outlined">balance</span>
                            แบ่งเงิน
                        </button>
                    </div>
                    <?php if (!$adminJudge['ok']): ?>
                        <button type="button" class="dispute-btn-submit secondary" style="margin-top:12px;width:100%;" onclick="judgeDisputeForce(<?php echo (int)$dispute['order_id']; ?>)">
                            บังคับตัดสิน (กรณีฉุกเฉิน)
                        </button>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

        </main>

        <aside class="dispute-sidebar">
            <div class="dispute-sidebar-card">
                <h3>ข้อมูลออเดอร์</h3>
                <div class="dispute-info-row">
                    <span>ออเดอร์</span>
                    <span>#<?php echo (int)$order['id']; ?></span>
                </div>
                <div class="dispute-info-row">
                    <span>สถานะออเดอร์</span>
                    <span><?php echo safeText($order['status']); ?></span>
                </div>
                <div class="dispute-info-row">
                    <span>ผู้เปิดข้อพิพาท</span>
                    <span><?php echo safeText($dispute['initiator']); ?></span>
                </div>
                <?php if (!empty($order['tracking_number'])): ?>
                    <div class="dispute-tracking-box">
                        <strong>📦 <?php echo safeText($order['courier'] ?? 'ขนส่ง'); ?></strong><br>
                        <?php echo safeText($order['tracking_number']); ?>
                        <?php if (!empty($order['tracking_status'])): ?>
                            <br><small>สถานะ: <?php echo safeText($order['tracking_status']); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dispute-sidebar-card">
                <h3>คู่กรณี</h3>
                <div class="dispute-party">
                    <div class="dispute-party-avatar">B</div>
                    <div class="dispute-party-info">
                        <strong><?php echo safeText($order['buyer_name']); ?></strong>
                        <small>ผู้ซื้อ<?php echo $isBuyer ? ' (คุณ)' : ''; ?></small>
                    </div>
                </div>
                <div class="dispute-party">
                    <div class="dispute-party-avatar">S</div>
                    <div class="dispute-party-info">
                        <strong><?php echo safeText($order['seller_name']); ?></strong>
                        <small>ผู้ขาย<?php echo $isSeller ? ' (คุณ)' : ''; ?></small>
                    </div>
                </div>
            </div>

            <?php if ($dispute['status'] === 'RESOLVED'): ?>
                <div class="dispute-sidebar-card">
                    <h3>ผลการตัดสิน</h3>
                    <div class="dispute-resolution-box">
                        <strong><?php echo safeText($resolutionLabels[$dispute['resolution'] ?? ''] ?? ($dispute['resolution'] ?? '-')); ?></strong>
                        <?php if (!empty($dispute['buyer_refund_amount']) || !empty($dispute['seller_payout_amount'])): ?>
                            <br><br>
                            คืนผู้ซื้อ: ฿<?php echo number_format((float)($dispute['buyer_refund_amount'] ?? 0), 2); ?><br>
                            ให้ผู้ขาย: ฿<?php echo number_format((float)($dispute['seller_payout_amount'] ?? 0), 2); ?>
                        <?php endif; ?>
                        <?php if (!empty($dispute['admin_note'])): ?>
                            <br><br><em><?php echo safeText($dispute['admin_note']); ?></em>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($isBuyer ? $order['seller_name'] : $order['buyer_name']); ?>" class="dispute-btn-submit secondary" style="text-decoration:none;display:flex;">
                <span class="material-symbols-outlined" style="font-size:18px;">chat</span>
                เปิดแชทกับอีกฝ่าย
            </a>
        </aside>
    </div>
</div>

<script>
const DISPUTE_ID = <?php echo (int)$dispute_id; ?>;
const ORDER_AMOUNT = <?php echo (float)$order['amount']; ?>;
const DISPUTE_CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

function renderAiAnalysis(a) {
    if (!a || a.status === 'error') {
        return '<p class="ai-empty" style="color:#dc2626;">' + (a?.error_message || 'วิเคราะห์ไม่สำเร็จ') + '</p>';
    }
    const actionLabels = { REFUND_BUYER: 'คืนเงินผู้ซื้อ', PAYOUT_SELLER: 'โอนให้ผู้ขาย', SPLIT: 'แบ่งเงิน', NEEDS_REVIEW: 'ต้องตรวจสอบเพิ่ม' };
    const actionClasses = { REFUND_BUYER: 'ai-rec-refund', PAYOUT_SELLER: 'ai-rec-payout', SPLIT: 'ai-rec-split', NEEDS_REVIEW: 'ai-rec-review' };
    const action = a.recommended_action || 'NEEDS_REVIEW';
    const buyerPct = a.buyer_fault_pct || 50;
    const sellerPct = a.seller_fault_pct || 50;
    let html = '<div class="ai-result">';
    html += '<p><strong>' + (a.summary || '') + '</strong></p>';
    html += '<span class="ai-badge ' + (actionClasses[action] || 'ai-rec-review') + '">แนะนำ: ' + (actionLabels[action] || action) + '</span>';
    html += ' <span class="ai-confidence">ความมั่นใจ ' + (a.confidence || 0) + '%</span>';
    html += '<div class="ai-fault-bar"><div class="ai-fault-buyer" style="width:' + buyerPct + '%"></div><div class="ai-fault-seller" style="width:' + sellerPct + '%"></div></div>';
    html += '<small>น้ำหนักความผิด: ผู้ซื้อ ' + buyerPct + '% / ผู้ขาย ' + sellerPct + '%</small>';
    if (a.suggested_buyer_refund > 0 || a.suggested_seller_payout > 0) {
        html += '<p style="margin-top:8px;font-size:13px;">แนะนำ: คืนผู้ซื้อ ฿' + Number(a.suggested_buyer_refund).toLocaleString() + ' / ให้ผู้ขาย ฿' + Number(a.suggested_seller_payout).toLocaleString() + '</p>';
    }
    if (a.reasoning) html += '<p style="font-size:13px;color:#475569;margin-top:8px;">' + a.reasoning + '</p>';
    if (a.key_findings && a.key_findings.length) {
        html += '<div style="margin-top:12px;"><strong>ประเด็นสำคัญ:</strong>';
        a.key_findings.forEach(f => { html += '<div class="ai-finding">' + f + '</div>'; });
        html += '</div>';
    }
    html += '<div class="dispute-ai-actions" style="margin-top:12px;">';
    if (action === 'REFUND_BUYER') html += '<button type="button" class="dispute-admin-btn refund" style="width:100%;flex-direction:row;justify-content:center;" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, \'refund_buyer\')">ใช้คำแนะนำ: คืนผู้ซื้อ</button>';
    else if (action === 'PAYOUT_SELLER') html += '<button type="button" class="dispute-admin-btn payout" style="width:100%;flex-direction:row;justify-content:center;" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, \'payout_seller\')">ใช้คำแนะนำ: ให้ผู้ขาย</button>';
    else if (action === 'SPLIT') html += '<button type="button" class="dispute-admin-btn split" style="width:100%;flex-direction:row;justify-content:center;" onclick="applyAiSplit(' + a.suggested_buyer_refund + ',' + a.suggested_seller_payout + ')">ใช้คำแนะนำ: แบ่งเงิน</button>';
    html += '</div></div>';
    return html;
}

function runAiAnalysis(force) {
    const btn = document.getElementById('btn-ai-analyze');
    const content = document.getElementById('ai-content');
    if (btn) btn.disabled = true;
    content.innerHTML = '<p class="ai-empty">⏳ กำลังวิเคราะห์... (10–30 วินาที)</p>';
    const fd = new FormData();
    fd.append('action', 'analyze');
    fd.append('dispute_id', DISPUTE_ID);
    fd.append('csrf_token', DISPUTE_CSRF_TOKEN);
    if (force) fd.append('force', '1');
    fetch('controllers/DisputeAssistantController.php', { method: 'POST', body: fd })
        .then(r => r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text ? text.substring(0, 150) : ('HTTP ' + r.status));
            }
        }))
        .then(d => {
            if (btn) btn.disabled = false;
            content.innerHTML = (d.status === 'success' && d.analysis) ? renderAiAnalysis(d.analysis) : '<p class="ai-empty" style="color:#dc2626;">' + (d.message || 'วิเคราะห์ไม่สำเร็จ') + '</p>';
        })
        .catch(err => {
            if (btn) btn.disabled = false;
            content.innerHTML = '<p class="ai-empty" style="color:#dc2626;">' + (err.message || 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้') + '</p>';
        });
}

function applyAiSplit(buyerAmt, sellerAmt) {
    postDispute({ action: 'split_resolution', order_id: <?php echo (int)$dispute['order_id']; ?>, buyer_amount: buyerAmt, seller_amount: sellerAmt });
}

function judgeDispute(oid, action) {
    const title = action === 'refund_buyer' ? 'ยืนยันคืนเงินผู้ซื้อ?' : 'ยืนยันโอนเงินให้ผู้ขาย?';
    Swal.fire({ title, icon: 'warning', showCancelButton: true, confirmButtonColor: '#2563eb' }).then(r => {
        if (r.isConfirmed) postDispute({ action, order_id: oid });
    });
}

function splitDispute(oid, total) {
    Swal.fire({
        title: 'แบ่งเงิน (Split)',
        html: '<p style="margin-bottom:12px;">ยอดรวม <strong>฿' + total.toLocaleString() + '</strong></p>'
            + '<input id="sw-b" class="swal2-input" type="number" placeholder="คืนผู้ซื้อ (บาท)">'
            + '<input id="sw-s" class="swal2-input" type="number" placeholder="ให้ผู้ขาย (บาท)">',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        preConfirm: () => {
            const b = parseFloat(document.getElementById('sw-b').value) || 0;
            const s = parseFloat(document.getElementById('sw-s').value) || 0;
            if (Math.abs(b + s - total) > 0.01) { Swal.showValidationMessage('ยอดรวมต้องเท่ากับยอดออเดอร์'); return false; }
            return { buyer_amount: b, seller_amount: s };
        }
    }).then(r => { if (r.isConfirmed && r.value) postDispute({ action: 'split_resolution', order_id: oid, ...r.value }); });
}

function postDispute(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append('csrf_token', DISPUTE_CSRF_TOKEN);
    fetch('controllers/DisputeController.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(d => Swal.fire(d.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', d.message, d.status).then(() => { if (d.status === 'success') location.reload(); }));
}

function postDisputeUser(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append('csrf_token', DISPUTE_CSRF_TOKEN);
    return fetch('controllers/DisputeUserController.php', { method: 'POST', body: fd })
        .then(r => r.text().then(text => {
            try { return JSON.parse(text); }
            catch (e) { throw new Error(text ? text.substring(0, 150) : 'HTTP error'); }
        }));
}

function submitSellerResponse() {
    const text = (document.getElementById('seller-response-text')?.value || '').trim();
    if (!text) { Swal.fire('กรุณากรอกคำชี้แจง', '', 'warning'); return; }
    Swal.fire({ title: 'ส่งคำชี้แจง?', icon: 'question', showCancelButton: true }).then(r => {
        if (!r.isConfirmed) return;
        postDisputeUser({ action: 'seller_response', dispute_id: DISPUTE_ID, response: text })
            .then(d => Swal.fire(d.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', d.message, d.status)
                .then(() => { if (d.status === 'success') location.reload(); }))
            .catch(err => Swal.fire('ผิดพลาด', err.message, 'error'));
    });
}

function openProposeSettlement() {
    Swal.fire({
        title: 'เสนอข้อตกลงแบ่งเงิน',
        html: '<p style="margin-bottom:12px;">ยอดรวม <strong>฿' + ORDER_AMOUNT.toLocaleString() + '</strong></p>'
            + '<input id="sw-b" class="swal2-input" type="number" placeholder="คืนผู้ซื้อ (บาท)">'
            + '<input id="sw-s" class="swal2-input" type="number" placeholder="ให้ผู้ขาย (บาท)">'
            + '<textarea id="sw-msg" class="swal2-textarea" placeholder="ข้อความ (ไม่บังคับ)"></textarea>',
        showCancelButton: true,
        preConfirm: () => {
            const b = parseFloat(document.getElementById('sw-b').value) || 0;
            const s = parseFloat(document.getElementById('sw-s').value) || 0;
            if (Math.abs(b + s - ORDER_AMOUNT) > 0.01) { Swal.showValidationMessage('ยอดรวมต้องเท่ากับยอดออเดอร์'); return false; }
            return { buyer_amount: b, seller_amount: s, message: document.getElementById('sw-msg').value || '' };
        }
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        postDisputeUser({ action: 'propose_settlement', dispute_id: DISPUTE_ID, ...r.value })
            .then(d => Swal.fire(d.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', d.message, d.status)
                .then(() => { if (d.status === 'success') location.reload(); }));
    });
}

function openCounterOffer() {
    openProposeSettlement();
}

function respondSettlement(response) {
    const labels = { accept: 'ยอมรับข้อเสนอ?', reject: 'ปฏิเสธข้อเสนอ?' };
    Swal.fire({ title: labels[response] || 'ยืนยัน?', icon: 'question', showCancelButton: true }).then(r => {
        if (!r.isConfirmed) return;
        postDisputeUser({ action: 'respond_settlement', dispute_id: DISPUTE_ID, response })
            .then(d => Swal.fire(d.status === 'success' ? 'สำเร็จ' : 'ผิดพลาด', d.message, d.status)
                .then(() => { if (d.status === 'success') location.reload(); }));
    });
}

function judgeDisputeForce(oid) {
    Swal.fire({
        title: 'บังคับตัดสิน (กรณีฉุกเฉิน)',
        text: 'ข้ามช่วงรอผู้ขาย/หลักฐาน — ใช้เมื่อจำเป็นเท่านั้น',
        input: 'select',
        inputOptions: { refund_buyer: 'คืนเงินผู้ซื้อ', payout_seller: 'โอนให้ผู้ขาย', split_resolution: 'แบ่งเงิน' },
        showCancelButton: true,
        confirmButtonColor: '#dc2626'
    }).then(r => {
        if (!r.isConfirmed || !r.value) return;
        if (r.value === 'split_resolution') {
            splitDispute(oid, ORDER_AMOUNT);
            return;
        }
        Swal.fire({ title: 'ยืนยันบังคับตัดสิน?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626' }).then(c => {
            if (c.isConfirmed) postDispute({ action: r.value, order_id: oid, force: '1' });
        });
    });
}
</script>

<?php include 'includes/footer.php'; ?>

