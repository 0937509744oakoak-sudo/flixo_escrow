<?php
// includes/security.php
// ตั้งค่าหัว HTTP security header เพื่อเพิ่มความปลอดภัยให้กับเว็บ

// Prevent direct access to the includes directory
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit('Access Denied');
}

if (!defined('IN_SYSTEM')) {
    define('IN_SYSTEM', true);
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-XSS-Protection: 1; mode=block');

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdn.tailwindcss.com https://static.sumsub.com https://accounts.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://accounts.google.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self' https://cdn.jsdelivr.net https://api.sumsub.com https://accounts.google.com https://oauth2.googleapis.com https://www.googleapis.com; frame-src https://api.sumsub.com https://accounts.google.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
?>