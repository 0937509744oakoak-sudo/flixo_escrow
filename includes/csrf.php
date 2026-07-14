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