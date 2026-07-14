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
