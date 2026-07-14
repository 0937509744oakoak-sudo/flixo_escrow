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
