<?php
/**
 * Follow / unfollow a seller (AJAX, JSON).
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/User.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนติดตามผู้ขาย'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$me = $_SESSION['user'];
$target = sanitizeUsername($_POST['target'] ?? '');

if ($target === '' || $target === $me) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'คำขอไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userModel = new User($conn);
$targetUser = $userModel->findByUsername($target);
if (!$targetUser || ($targetUser['status'] ?? '') === 'banned') {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบผู้ใช้นี้'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userModel->isFollowing($me, $target)) {
    $userModel->unfollow($me, $target);
    $following = false;
} else {
    $userModel->follow($me, $target);
    $following = true;
}

echo json_encode([
    'status' => 'success',
    'following' => $following,
    'follower_count' => $userModel->countFollowers($target),
], JSON_UNESCAPED_UNICODE);
