<?php
/**
 * Secure KYC image serving — admin only, with PII audit log and decryption.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/PiiEncryption.php';
require_once __DIR__ . '/../includes/PiiAudit.php';
require_once __DIR__ . '/../includes/Rbac.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Forbidden');
}

$role = $_SESSION['role'] ?? Rbac::roleOf($conn, $_SESSION['user']);
if (!Rbac::can($role, 'pii.view')) {
    http_response_code(403);
    exit('Forbidden — insufficient permission to view PII');
}

$adminUser = $_SESSION['user'];
$filename = basename($_GET['file'] ?? '');
$targetUser = trim($_GET['user'] ?? '');
$type = $_GET['type'] ?? 'kyc';
$column = ($type === 'selfie') ? 'selfie_image' : 'id_card_image';

if (!in_array($type, ['kyc', 'selfie'], true) || $filename === '' || preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    http_response_code(400);
    exit('Bad request');
}

if ($targetUser === '') {
    $stmt = $conn->prepare("SELECT username FROM users WHERE {$column} = ? LIMIT 1");
    $stmt->bind_param('s', $filename);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $targetUser = $row['username'] ?? 'unknown';
}

$kycDir = realpath(__DIR__ . '/../uploads/kyc');
$filepath = realpath($kycDir . DIRECTORY_SEPARATOR . $filename);

if ($filepath === false || !str_starts_with($filepath, $kycDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('File not found');
}

logPiiAccess($conn, $adminUser, $targetUser, 'view_' . $type . '_image', $type . '_image', $filename);

$servePath = PiiEncryption::decryptFileToTemp($filepath);
if ($servePath === null) {
    http_response_code(500);
    exit('Cannot decrypt file');
}

$mime = PiiEncryption::guessMimeFromPath($filename);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($servePath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($servePath);

if (str_starts_with($servePath, sys_get_temp_dir())) {
    @unlink($servePath);
}
exit;
