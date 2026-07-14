<?php
// controllers/AuthController.php — social login (Google Sign-In)
require_once __DIR__ . '/../includes/init.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/GoogleIdTokenVerifier.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/OAuthAccount.php';
require_once __DIR__ . '/../models/Consent.php';

header('Content-Type: application/json; charset=utf-8');

verifyCsrfToken($_POST['csrf_token'] ?? '');

try {
    $action = $_POST['action'] ?? '';

    if ($action !== 'google_login') {
        throw new Exception('คำสั่งไม่ถูกต้อง');
    }

    if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '') {
        throw new Exception('ยังไม่ได้เปิดใช้ Google Sign-In');
    }

    $idToken = trim((string) ($_POST['id_token'] ?? ''));
    if ($idToken === '') {
        throw new Exception('ไม่พบโทเคนจาก Google');
    }

    $verifier = new GoogleIdTokenVerifier(GOOGLE_CLIENT_ID);
    $payload = $verifier->verify($idToken);

    $googleSub = (string) $payload['sub'];
    $email = strtolower(trim((string) $payload['email']));
    $realName = trim((string) ($payload['name'] ?? ''));
    if ($realName === '') {
        $realName = explode('@', $email)[0] ?? 'ผู้ใช้';
    }
    $realName = mb_substr($realName, 0, 100);

    $redirectAfter = trim((string) ($_POST['redirect'] ?? ''));
    if ($redirectAfter !== '' && (str_contains($redirectAfter, '://') || str_starts_with($redirectAfter, '//'))) {
        $redirectAfter = '';
    }

    $userModel = new User($conn);
    $oauthModel = new OAuthAccount($conn);
    $consentModel = new Consent($conn);
    $oauthModel->ensureTable();

    $oauthRow = $oauthModel->findByProvider('google', $googleSub);
    $user = null;
    $isNewUser = false;

    if ($oauthRow) {
        $user = $userModel->findById((int) $oauthRow['user_id']);
    }

    if (!$user) {
        $user = $userModel->findByEmail($email);
        if ($user) {
            $oauthModel->link((int) $user['id'], 'google', $googleSub, $email);
        }
    }

    if (!$user) {
        $consentTerms = !empty($_POST['consent_terms']);
        $consentPrivacy = !empty($_POST['consent_privacy']);
        if (!$consentTerms || !$consentPrivacy) {
            echo json_encode([
                'status' => 'needs_consent',
                'message' => 'กรุณายอมรับข้อกำหนดและนโยบายความเป็นส่วนตัวก่อนสมัครด้วย Google',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $base = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0] ?? '');
        $username = sanitizeUsername($base !== '' ? $base : ('user' . substr(bin2hex(random_bytes(3)), 0, 6)));
        if (mb_strlen($username) < 3) {
            $username = 'user' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $baseUser = $username;
        $tries = 0;
        while ($userModel->findByUsername($username) && $tries < 8) {
            $username = sanitizeUsername($baseUser . substr(bin2hex(random_bytes(2)), 0, 4));
            $tries++;
        }
        if ($userModel->findByUsername($username)) {
            throw new Exception('ไม่สามารถสร้างบัญชีได้ กรุณาลองใหม่');
        }

        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $userId = $userModel->create($username, $passwordHash, $email, $realName, '');
        if (!$userId) {
            throw new Exception('เกิดข้อผิดพลาดทางเทคนิค กรุณาลองใหม่');
        }

        $oauthModel->link((int) $userId, 'google', $googleSub, $email);

        $consentModel->record((int) $userId, 'terms_of_service', TERMS_VERSION);
        $consentModel->record((int) $userId, 'privacy_policy', PRIVACY_VERSION);

        $user = $userModel->findById((int) $userId);
        $isNewUser = true;
    }

    if (!$user) {
        throw new Exception('ไม่พบบัญชีผู้ใช้');
    }
    if (($user['status'] ?? '') === 'banned') {
        throw new Exception('บัญชีของคุณถูกระงับ โปรดติดต่อฝ่ายสนับสนุน');
    }
    if (($user['status'] ?? 'active') !== 'active') {
        throw new Exception('บัญชีของคุณยังไม่สามารถใช้งานได้');
    }

    if (!$oauthModel->findByUserAndProvider((int) $user['id'], 'google')) {
        try {
            $oauthModel->link((int) $user['id'], 'google', $googleSub, $email);
        } catch (RuntimeException $e) {
            // Already logged in by email match — linking is best-effort for edge cases
            error_log('OAuth best-effort link: ' . $e->getMessage());
        }
    }

    if (!$consentModel->hasCurrentPolicyConsent((int) $user['id'])) {
        $consentTerms = !empty($_POST['consent_terms']);
        $consentPrivacy = !empty($_POST['consent_privacy']);
        if (!$consentTerms || !$consentPrivacy) {
            echo json_encode([
                'status' => 'needs_consent',
                'message' => 'กรุณายอมรับข้อกำหนดและนโยบายความเป็นส่วนตัวฉบับปัจจุบัน',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $consentModel->record((int) $user['id'], 'terms_of_service', TERMS_VERSION);
        $consentModel->record((int) $user['id'], 'privacy_policy', PRIVACY_VERSION);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = $user['username'];
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'] ?? 'user';

    $redirect = $redirectAfter !== '' ? $redirectAfter : 'dashboard.php';
    echo json_encode([
        'status' => 'success',
        'redirect' => $redirect,
        'is_new' => $isNewUser,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = (int) ($e->getCode() ?: 400);
    if ($code < 400 || $code > 599) {
        $code = 400;
    }
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
