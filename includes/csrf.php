<?php
// includes/csrf.php

require_once __DIR__ . '/session.php';

function getCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session.php';
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfTokenIsValid($token) {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session.php';
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $submittedToken = $token ?: $headerToken;

    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $submittedToken);
}

function flixoParseIniSize(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float) $value;
    return match ($unit) {
        'g' => (int) ($num * 1024 * 1024 * 1024),
        'm' => (int) ($num * 1024 * 1024),
        'k' => (int) ($num * 1024),
        default => (int) $num,
    };
}

/**
 * @param string $onFail json|html|redirect
 */
function verifyCsrfToken($token, $onFail = 'json') {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session.php';
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (csrfTokenIsValid($token)) {
        return;
    }

    // Multipart POST with empty body usually means post_max_size was exceeded —
    // PHP drops $_POST/$_FILES, which looks like a CSRF failure.
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = flixoParseIniSize((string) ini_get('post_max_size'));
    $isOversizedUpload = $contentLength > 0
        && empty($_POST)
        && stripos($contentType, 'multipart/form-data') !== false
        && ($postMax <= 0 || $contentLength > $postMax);

    if ($isOversizedUpload) {
        $message = 'ไฟล์ใหญ่เกินไป กรุณาลดขนาดรูปหรือจำนวนรูปแล้วลองใหม่';
        if ($onFail === 'redirect') {
            require_once __DIR__ . '/functions.php';
            redirectTo('dashboard.php?tab=sell');
        }
        if ($onFail === 'html') {
            http_response_code(413);
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>อัปโหลดไม่สำเร็จ</title></head>';
            echo '<body style="font-family:sans-serif;text-align:center;padding:48px;"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
            echo '<p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
            exit();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($onFail === 'redirect') {
        require_once __DIR__ . '/functions.php';
        redirectTo('dashboard.php?tab=profile&logout_error=csrf');
    }

    if ($onFail === 'html') {
        http_response_code(403);
        $back = htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'login.php', ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เซสชันหมดอายุ</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:48px;background:#f4f6f8;">';
        echo '<div style="background:#fff;padding:32px;border-radius:12px;display:inline-block;max-width:420px;box-shadow:0 4px 16px rgba(0,0,0,.08);">';
        echo '<h1 style="margin:0 0 12px;font-size:20px;">เซสชันหมดอายุ</h1>';
        echo '<p style="color:#555;margin:0 0 20px;">กรุณารีเฟรชหน้าแล้วลองใหม่อีกครั้ง</p>';
        echo '<a href="' . $back . '" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;">รีเฟรชหน้า</a>';
        echo '</div></body></html>';
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security Error: ตรวจพบคำสั่งที่ไม่ปลอดภัย (CSRF)'], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
