<?php
require 'admin_check.php';
adminRequirePermission('support.inbox');
require_once __DIR__ . '/../includes/support_chat.php';

$csrf_token = getCsrfToken();
$support = getSupportUsername();
$selectedUser = trim($_GET['user'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_user'], $_POST['reply_message'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $replyUser = trim($_POST['reply_user']);
    $replyMessage = trim($_POST['reply_message']);

    if ($replyUser !== '' && $replyMessage !== '' && !isSupportUsername($replyUser)) {
        sendSupportMessage($conn, $replyUser, $replyMessage);
        header('Location: support_inbox.php?user=' . urlencode($replyUser) . '&sent=1');
        exit;
    }
}

$partners = getSupportInboxPartners($conn);

$messages = [];
if ($selectedUser !== '' && !isSupportUsername($selectedUser)) {
    $stmt = $conn->prepare(
        'SELECT sender_name, receiver_name, message, created_at
         FROM messages
         WHERE (sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?)
         ORDER BY created_at ASC'
    );
    $stmt->bind_param('ssss', $support, $selectedUser, $selectedUser, $support);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    markSupportMessagesRead($conn, $support, $selectedUser);
}

$page_title = 'แชทช่วยเหลือ';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="admin-content admin-support-wrap">
        <div class="admin-topbar">
            <div>
                <h1>💬 แชทช่วยเหลือ</h1>
                <p>ตอบคำถามผู้ใช้ผ่าน <?php echo htmlspecialchars($support); ?> — แจ้งข่าวสารและผล eKYC</p>
            </div>
        </div>

        <?php if (!empty($_GET['sent'])): ?>
            <div class="admin-alert-success">ส่งข้อความแล้ว</div>
        <?php endif; ?>

        <div class="admin-inbox-grid">
            <div class="admin-user-list">
                <?php if (empty($partners)): ?>
                    <div class="empty" style="padding:40px;text-align:center;color:var(--admin-muted);">ยังไม่มีแชทจากผู้ใช้</div>
                <?php else: ?>
                    <?php foreach ($partners as $partner): ?>
                        <a href="support_inbox.php?user=<?php echo urlencode($partner); ?>"
                           class="<?php echo $partner === $selectedUser ? 'active' : ''; ?>">
                            @<?php echo htmlspecialchars($partner); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="admin-chat-panel">
                <?php if ($selectedUser === ''): ?>
                    <div class="empty" style="padding:40px;text-align:center;color:var(--admin-muted);">เลือกผู้ใช้จากรายการด้านซ้ายเพื่อดูและตอบแชท</div>
                <?php else: ?>
                    <div class="admin-chat-messages">
                        <?php if (empty($messages)): ?>
                            <div class="empty" style="padding:40px;text-align:center;color:var(--admin-muted);">ยังไม่มีข้อความ — ส่งข้อความเปิดบทสนทนาได้</div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <?php $fromSupport = ($msg['sender_name'] === $support); ?>
                                <div class="admin-msg <?php echo $fromSupport ? 'support' : 'user'; ?>">
                                    <?php
                                    require_once __DIR__ . '/../includes/support_chat.php';
                                    if ($fromSupport && (strpos($msg['message'], '[[flixo-btn:') !== false || strpos($msg['message'], 'ดูรายละเอียด:') !== false)) {
                                        echo formatSupportChatMessageHtml($msg['message']);
                                    } else {
                                        echo nl2br(htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8'));
                                    }
                                    ?>
                                    <time><?php echo htmlspecialchars($msg['created_at']); ?></time>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="admin-reply-box">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="reply_user" value="<?php echo htmlspecialchars($selectedUser, ENT_QUOTES, 'UTF-8'); ?>">
                        <textarea name="reply_message" placeholder="พิมพ์ข้อความถึง @<?php echo htmlspecialchars($selectedUser); ?>..." required></textarea>
                        <button type="submit" class="admin-btn admin-btn-primary" style="margin-top:10px;">ส่งข้อความ</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
