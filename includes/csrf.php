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
    if (
        $contentLength > 0
        && empty($_POST)
        && stripos($contentType, 'multipart/form-data') !== false
        && ($postMax <= 0 || $contentLength > $postMax)
    ) {
        $message = 'ไฟล์ใหญ่เกินไป กรุณาลดขนาดรูปหรือจำนวนรูปแล้วลองใหม่';
        if ($onFail === 'redirect') {
            require_once __DIR__ . '/functions.php';
            redirectTo('dashboard.php?tab=sell');
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

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security Error: ตรวจพบคำสั่งที่ไม่ปลอดภัย (CSRF)'], JSON_UNESCAPED_UNICODE);
    exit();
}
?>