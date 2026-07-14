<?php

/**
 * FLIXO Support chat — system announcements & admin two-way chat.
 */

function getSupportUsername(): string
{
    return defined('SUPPORT_USERNAME') ? SUPPORT_USERNAME : 'FLIXO_Support';
}

function isSupportUsername(string $username): bool
{
    return strcasecmp(trim($username), getSupportUsername()) === 0;
}

function ensureMessagesReadColumn(mysqli $conn): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    $result = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
    if ($result && $result->num_rows > 0) {
        $hasColumn = true;
        return true;
    }

    if ($conn->query('ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0')) {
        $hasColumn = true;
        return true;
    }

    error_log('support_chat: unable to add messages.is_read — ' . $conn->error);
    $hasColumn = false;
    return false;
}

function ensureSupportUserExists(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $support = getSupportUsername();
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        error_log('support_chat: ensureSupportUserExists select failed — ' . $conn->error);
        return;
    }

    $stmt->bind_param('s', $support);
    $stmt->execute();

    if ($stmt->get_result()->num_rows === 0) {
        $email = 'support@flixo.local';
        $password = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $realName = 'ทีมงาน FLIXO';
        $phone = '';
        $insert = $conn->prepare(
            "INSERT INTO users (username, password, email, real_name, phone, role, status)
             VALUES (?, ?, ?, ?, ?, 'user', 'active')"
        );
        if ($insert) {
            $insert->bind_param('sssss', $support, $password, $email, $realName, $phone);
            $insert->execute();
        } else {
            $insert = $conn->prepare(
                "INSERT INTO users (username, password, email, real_name, phone, role)
                 VALUES (?, ?, ?, ?, ?, 'user')"
            );
            if ($insert) {
                $insert->bind_param('sssss', $support, $password, $email, $realName, $phone);
                $insert->execute();
            } else {
                error_log('support_chat: ensureSupportUserExists insert failed — ' . $conn->error);
            }
        }
    }

    $ensured = true;
}

function sendSupportMessage(mysqli $conn, string $toUser, string $message): bool
{
    $toUser = trim($toUser);
    $message = trim($message);

    if ($toUser === '' || $message === '' || isSupportUsername($toUser)) {
        return false;
    }

    ensureSupportUserExists($conn);
    $hasReadColumn = ensureMessagesReadColumn($conn);

    $support = getSupportUsername();
    if ($hasReadColumn) {
        $stmt = $conn->prepare(
            'INSERT INTO messages (sender_name, receiver_name, message, is_read) VALUES (?, ?, ?, 0)'
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO messages (sender_name, receiver_name, message) VALUES (?, ?, ?)'
        );
    }

    if (!$stmt) {
        error_log('support_chat: sendSupportMessage prepare failed — ' . $conn->error);
        return false;
    }

    $stmt->bind_param('sss', $support, $toUser, $message);
    return $stmt->execute();
}

function getSupportUnreadCount(mysqli $conn, string $username): int
{
    if ($username === '' || isSupportUsername($username)) {
        return 0;
    }

    ensureSupportUserExists($conn);

    if (!ensureMessagesReadColumn($conn)) {
        return 0;
    }

    $support = getSupportUsername();
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS unread FROM messages
         WHERE sender_name = ? AND receiver_name = ? AND is_read = 0'
    );
    if (!$stmt) {
        error_log('support_chat: getSupportUnreadCount prepare failed — ' . $conn->error);
        return 0;
    }

    $stmt->bind_param('ss', $support, $username);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['unread'] ?? 0);
}

function markSupportMessagesRead(mysqli $conn, string $username, string $partner): void
{
    if (!ensureMessagesReadColumn($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE messages SET is_read = 1 WHERE sender_name = ? AND receiver_name = ? AND is_read = 0'
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $partner, $username);
    $stmt->execute();
}

function getSupportLastPreview(mysqli $conn, string $username): array
{
    $support = getSupportUsername();
    $default = [
        'preview' => 'คุยกับทีมงาน FLIXO',
        'time' => '',
        'initial' => 'F',
        'is_support' => true,
    ];

    $stmt = $conn->prepare(
        'SELECT message, created_at FROM messages
         WHERE (sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?)
         ORDER BY created_at DESC LIMIT 1'
    );
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('ssss', $support, $username, $username, $support);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return $default;
    }

    $preview = $row['message'];
    if (mb_strlen($preview) > 40) {
        $preview = mb_substr($preview, 0, 40) . '...';
    }

    $msgTime = strtotime($row['created_at']);
    $timeDisplay = (date('Ymd') === date('Ymd', $msgTime))
        ? date('H:i', $msgTime)
        : date('d/m', $msgTime);

    return [
        'preview' => $preview,
        'time' => $timeDisplay,
        'initial' => 'F',
        'is_support' => true,
    ];
}

function flixo_pin_support_in_sidebar(mysqli $conn, string $me, array $sidebarPartners): array
{
    if (isSupportUsername($me)) {
        return $sidebarPartners;
    }

    $support = getSupportUsername();
    $supportEntry = getSupportLastPreview($conn, $me);

    unset($sidebarPartners[$support]);
    return [$support => $supportEntry] + $sidebarPartners;
}

function getSupportInboxPartners(mysqli $conn): array
{
    $support = getSupportUsername();
    ensureSupportUserExists($conn);

    $sql = "SELECT
                CASE WHEN sender_name = ? THEN receiver_name ELSE sender_name END AS partner,
                MAX(created_at) AS last_at
            FROM messages
            WHERE sender_name = ? OR receiver_name = ?
            GROUP BY partner
            HAVING partner != ?
            ORDER BY last_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('support_chat: getSupportInboxPartners prepare failed — ' . $conn->error);
        return [];
    }

    $stmt->bind_param('ssss', $support, $support, $support, $support);
    $stmt->execute();
    $result = $stmt->get_result();

    $partners = [];
    while ($row = $result->fetch_assoc()) {
        $partners[] = $row['partner'];
    }

    return $partners;
}

/**
 * แปลงข้อความแจ้งเตือน Support เป็น HTML (ข้อความ + ปุ่มกด)
 */
function formatSupportChatMessageHtml(string $text): string
{
    $buttons = [];

    $text = preg_replace_callback(
        '/\[\[flixo-btn:([^|\]]+)\|([^\]]+)\]\]/u',
        static function (array $m) use (&$buttons): string {
            $buttons[] = ['url' => trim($m[1]), 'label' => trim($m[2])];
            return '';
        },
        $text
    ) ?? $text;

    $text = preg_replace_callback(
        '/(?:\n\n|\n)?ดูรายละเอียด:\s*(https?:\/\/\S+|[^\s\n]+)/u',
        static function (array $m) use (&$buttons): string {
            $raw = trim($m[1]);
            $path = $raw;
            if (strpos($raw, 'http') === 0 && defined('BASE_URL')) {
                $base = rtrim(BASE_URL, '/');
                if (strpos($raw, $base) === 0) {
                    $path = ltrim(substr($raw, strlen($base)), '/');
                }
            }
            $label = 'ดูรายละเอียด';
            if (strpos($path, 'dispute_detail.php') !== false) {
                $label = 'ดูข้อพิพาท';
            } elseif (strpos($path, 'dashboard.php') !== false) {
                $label = 'ดูออเดอร์';
            } elseif (strpos($path, 'earnings.php') !== false) {
                $label = 'ดูรายได้';
            }
            $buttons[] = ['url' => $path, 'label' => $label];
            return '';
        },
        $text
    ) ?? $text;

    $text = trim($text);
    $html = $text !== '' ? nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) : '';

    foreach ($buttons as $btn) {
        $href = $btn['url'];
        if ($href !== '' && strpos($href, 'http') !== 0 && strpos($href, '//') !== 0) {
            $href = ltrim($href, '/');
        }
        $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="chat-msg-action-btn">'
            . htmlspecialchars($btn['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }

    return $html;
}

function supportChatActionLabel(string $url): string
{
    if (strpos($url, 'dispute_detail.php') !== false) {
        return 'ดูข้อพิพาท';
    }
    if (strpos($url, 'earnings.php') !== false) {
        return 'ดูรายได้';
    }
    if (strpos($url, 'buy_product.php') !== false) {
        return 'ดูดีล';
    }
    if (strpos($url, 'dashboard.php') !== false) {
        return 'ดูออเดอร์';
    }
    return 'ดูรายละเอียด';
}
