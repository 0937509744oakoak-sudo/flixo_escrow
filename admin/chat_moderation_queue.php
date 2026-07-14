<?php
require 'admin_check.php';
require_once __DIR__ . '/../includes/ChatModerationService.php';

$moderation = new ChatModerationService($conn);
$csrf_token = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['scan_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $scanId = (int)$_POST['scan_id'];
    $action = $_POST['action'];
    $adminUser = $_SESSION['user'] ?? 'admin';

    if ($action === 'dismiss') {
        $moderation->dismissScan($scanId, $adminUser);
        echo "<script>alert('✅ ปิดเคสแล้ว — ไม่มีการดำเนินการเพิ่มเติม'); window.location='chat_moderation_queue.php';</script>";
        exit;
    }

    if ($action === 'action') {
        $moderation->actionScan($scanId, $adminUser);
        echo "<script>alert('⚠️ บันทึกว่าดำเนินการแล้ว'); window.location='chat_moderation_queue.php';</script>";
        exit;
    }
}

$queue = $moderation->getReviewQueue(100);
$page_title = 'ตรวจแชท (AI)';
include 'layout_head.php';

function chatModDecodeJson(?string $json): array {
    if (!$json) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>💬 คิวตรวจสอบแชท</h1>
                <p>Tier 1 Regex (ฟรี) → Tier 2 Gemini AI (เฉพาะข้อความน่าสงสัย) — เบอร์โทร, เลขบัญชี, ลิงก์, ชวนโอนนอกระบบ</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>ผู้ส่ง → ผู้รับ</th>
                            <th>เนื้อหา</th>
                            <th>คะแนน</th>
                            <th>Pattern ที่พบ</th>
                            <th>เหตุผล</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($queue && $queue->num_rows > 0): while ($row = $queue->fetch_assoc()):
                        $verdict = $row['verdict'] ?? 'flag';
                        $badgeClass = $verdict === 'reject' ? 'admin-badge-rejected' : 'admin-badge-flagged';
                        $patterns = chatModDecodeJson($row['matched_patterns'] ?? null);
                        $reasons = chatModDecodeJson($row['reasons'] ?? null);
                        $preview = $row['message_preview'] ?: ($row['message'] ?? '');
                        if (!empty($row['image_path'])) {
                            $preview = '[รูปภาพ] ' . $row['image_path'];
                        }
                    ?>
                        <tr>
                            <td><?php echo adminFormatDate($row['created_at'] ?? null); ?></td>
                            <td>
                                <strong><?php echo safeText($row['sender_name']); ?></strong>
                                → <?php echo safeText($row['receiver_name']); ?>
                            </td>
                            <td style="max-width:220px; word-break:break-word;">
                                <?php echo safeText(mb_substr($preview, 0, 120)); ?>
                            </td>
                            <td><?php echo number_format((float)($row['risk_score'] ?? 0), 0); ?>/100</td>
                            <td>
                                <?php if ($patterns): ?>
                                    <ul class="admin-reasons">
                                        <?php foreach ($patterns as $p): ?>
                                            <li><code><?php echo safeText($p); ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="admin-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($reasons): ?>
                                    <ul class="admin-reasons">
                                        <?php foreach ($reasons as $reason): ?>
                                            <li><?php echo safeText($reason); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="admin-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-action-group">
                                    <form method="post" onsubmit="return confirm('ปิดเคสนี้โดยไม่ดำเนินการ?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="scan_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="action" value="dismiss">
                                        <button type="submit" class="admin-btn admin-btn-sm">ปิดเคส</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('บันทึกว่าดำเนินการแล้ว?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="scan_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="action" value="action">
                                        <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm">ดำเนินการแล้ว</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="7" class="empty">ไม่มีข้อความรอตรวจสอบ 🎉</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
