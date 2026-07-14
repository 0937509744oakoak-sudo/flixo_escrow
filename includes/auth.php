<?php
// เริ่ม Session, config, DB, CSRF และฟังก์ชันพื้นฐาน
require_once __DIR__ . '/init.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// ตรวจสอบการล็อกอิน (Gatekeeper)
if (!isset($_SESSION['user'])) {
    redirectTo('login.php');
}

$me = $_SESSION['user'];
$stmt = $conn->prepare("SELECT status, role FROM users WHERE username = ?");
$stmt->bind_param('s', $me);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || $user['status'] === 'banned') {
    clearSession();
    redirectTo('login.php');
}

$_SESSION['role'] = $user['role'] ?? 'user';

require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/../models/Consent.php';
require_once __DIR__ . '/../models/User.php';

$userModel = new User($conn);
$fullUser = $userModel->findByUsername($me);
if ($fullUser) {
    $consentModel = new Consent($conn);
    $needsReconsent = !$consentModel->hasCurrentPolicyConsent((int) $fullUser['id']);
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $allowWithoutConsent = in_array($currentScript, ['privacy.php', 'logout.php', 'PrivacyController.php'], true);
    if ($needsReconsent && !$allowWithoutConsent) {
        redirectTo('privacy.php?action=reconsent');
    }
}
?>
