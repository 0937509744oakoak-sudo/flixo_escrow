<?php

/**
 * Build sidebar partner list for chat UI.
 *
 * @return array<string, array{preview: string, time: string, initial: string}>
 */
function flixo_build_chat_sidebar(User $userModel, string $me): array
{
    $sidebar_partners = [];
    $chat_history = $userModel->getChatHistory($me);

    if (!$chat_history || $chat_history->num_rows === 0) {
        return $sidebar_partners;
    }

    $partnerUsernames = [];
    while ($row = $chat_history->fetch_assoc()) {
        $p = ($row['sender_name'] === $me) ? $row['receiver_name'] : $row['sender_name'];
        $partnerUsernames[$p] = true;
    }

    $displayNames = $userModel->getDisplayNameMap(array_keys($partnerUsernames));
    $chat_history = $userModel->getChatHistory($me);
    if (!$chat_history) {
        return $sidebar_partners;
    }

    while ($row = $chat_history->fetch_assoc()) {
        $p = ($row['sender_name'] === $me) ? $row['receiver_name'] : $row['sender_name'];
        if (isset($sidebar_partners[$p])) {
            continue;
        }

        $displayName = $displayNames[$p] ?? $p;

        $preview = $row['message'];
        if (strpos($preview, '[OFFER:') !== false) {
            $preview = '🤝 ดีลตกลงกัน';
        } elseif (strpos($preview, '[PRODUCT:') !== false) {
            $preview = '📢 ส่งประกาศ';
        } elseif (!empty($row['image_path'])) {
            $preview = '📷 ส่งรูปภาพ';
        }
        if (mb_strlen($preview) > 40) {
            $preview = mb_substr($preview, 0, 40) . '...';
        }

        $msg_time = strtotime($row['created_at']);
        $time_display = (date('Ymd') === date('Ymd', $msg_time))
            ? date('H:i', $msg_time)
            : date('d/m', $msg_time);

        $sidebar_partners[$p] = [
            'preview' => $preview,
            'time' => $time_display,
            'initial' => strtoupper(mb_substr($displayName, 0, 1)),
            'display_name' => $displayName,
        ];
    }

    return $sidebar_partners;
}

/**
 * Pin FLIXO Support at top of chat sidebar.
 */
function flixo_finalize_chat_sidebar(mysqli $conn, string $me, array $sidebarPartners): array
{
    require_once __DIR__ . '/support_chat.php';
    return flixo_pin_support_in_sidebar($conn, $me, $sidebarPartners);
}

/**
 * แจ้งเตือนผู้รับเมื่อมีข้อความแชทใหม่ (in-app + email + push ตาม preference)
 *
 * กันสแปม: ถ้าผู้รับยังมีแจ้งเตือนแชทจากคู่สนทนาคนเดิมที่ยังไม่อ่านค้างอยู่
 * จะไม่สร้างแจ้งเตือนซ้ำ — จะแจ้งอีกครั้งเมื่อผู้รับเปิดอ่านห้องแชทนั้นแล้ว
 */
function flixo_notify_chat_message(mysqli $conn, string $sender, string $receiver, string $preview): void
{
    require_once __DIR__ . '/support_chat.php';
    // แชทกับทีมงาน FLIXO มี flow ของ support inbox อยู่แล้ว
    if (isSupportUsername($sender) || isSupportUsername($receiver)) {
        return;
    }

    $chatUrl = 'dashboard.php?tab=chat&user=' . rawurlencode($sender);

    $stmt = $conn->prepare(
        "SELECT id FROM notifications
         WHERE user_name = ? AND type = 'chat' AND is_read = 0 AND link_url LIKE CONCAT('%', ?)
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $receiver, $chatUrl);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return; // มีแจ้งเตือนค้างอ่านจากคนนี้อยู่แล้ว ไม่ต้องยิงซ้ำ
        }
    }

    $preview = trim($preview);
    if (mb_strlen($preview) > 80) {
        $preview = mb_substr($preview, 0, 80) . '…';
    }
    $senderDisplay = function_exists('userDisplayName') ? userDisplayName($conn, $sender) : $sender;

    require_once __DIR__ . '/NotificationService.php';
    NotificationService::notifyUser($conn, $receiver, [
        'type' => 'chat',
        'title' => 'ข้อความใหม่',
        'icon' => '💬',
        'message' => "💬 {$senderDisplay}: {$preview}",
        'url' => $chatUrl,
    ]);
}

/**
 * ผู้ใช้เปิดอ่านห้องแชทแล้ว → เคลียร์แจ้งเตือนแชทของคู่สนทนานั้น
 * (ทำให้กระดิ่งหายและข้อความใหม่ครั้งถัดไปแจ้งเตือนได้อีก)
 */
function flixo_mark_chat_notifications_read(mysqli $conn, string $viewer, string $partner): void
{
    $chatUrl = 'dashboard.php?tab=chat&user=' . rawurlencode($partner);
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_name = ? AND type = 'chat' AND is_read = 0 AND link_url LIKE CONCAT('%', ?)"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $viewer, $chatUrl);
        $stmt->execute();
    }
}
