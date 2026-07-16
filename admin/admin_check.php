<?php
// admin/admin_check.php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/Rbac.php';

if (!isset($_SESSION['user'])) {
    redirectTo(BASE_URL . '/login.php');
}

$me = $_SESSION['user'];
$role = Rbac::roleOf($conn, $me);

if (!Rbac::isStaff($role)) {
    header('Location: ../dashboard.php');
    exit();
}

$_SESSION['role'] = $role;

/**
 * Guard รายหน้า — เรียกต่อจาก require 'admin_check.php' ที่หัวไฟล์
 */
function adminRequirePermission(string $permission): void {
    $role = $_SESSION['role'] ?? 'user';
    if (!Rbac::can($role, $permission)) {
        http_response_code(403);
        exit('Access Denied — บทบาท "' . htmlspecialchars(Rbac::roleLabel($role)) . '" ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
}
