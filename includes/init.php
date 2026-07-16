<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ProductionGuard.php';

ProductionGuard::assertWebSafe();

if (defined('FORCE_HTTPS') && FORCE_HTTPS && php_sapi_name() !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    if (!$isHttps && !empty($_SERVER['HTTP_HOST'])) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $uri, true, 301);
        exit;
    }
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

// Presence: อัปเดตเวลาใช้งานล่าสุดของผู้ที่ล็อกอิน (throttle ทุก 60 วิ เพื่อลด write)
if (!empty($_SESSION['user']) && php_sapi_name() !== 'cli') {
    $lastTouch = (int) ($_SESSION['presence_touched_at'] ?? 0);
    if (time() - $lastTouch >= 60) {
        $_SESSION['presence_touched_at'] = time();
        $touchStmt = $conn->prepare('UPDATE users SET last_active_at = NOW() WHERE username = ?');
        if ($touchStmt) {
            $touchStmt->bind_param('s', $_SESSION['user']);
            $touchStmt->execute();
        }
    }
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', ROOT_PATH . '/public');
}
if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', rtrim(BASE_URL, '/') . '/assets');
}
if (!defined('UPLOADS_URL')) {
    define('UPLOADS_URL', rtrim(BASE_URL, '/') . '/uploads');
}

