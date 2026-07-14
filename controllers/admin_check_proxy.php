<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/Rbac.php';

if (!isset($_SESSION['user'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit();
}

$role = $_SESSION['role'] ?? Rbac::roleOf($conn, $_SESSION['user']);
if (!Rbac::isStaff($role)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Admin Only'], JSON_UNESCAPED_UNICODE);
    exit();
}
