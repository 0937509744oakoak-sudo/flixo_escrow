<?php
/**
 * ThaiD (DOPA Digital ID) verification — OpenID Connect authorization-code flow.
 *
 * Two entry points share one file so that a single URL can be registered with
 * DOPA as the redirect URI:
 *   POST action=start  → build the authorization request and leave the site
 *   GET  ?code=…&state → DOPA sends the browser back here
 *
 * Both redirect back to kyc_verify.php with a flash message; nothing here
 * returns JSON, because every request is a top-level browser navigation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ErrorLogger.php';
require_once __DIR__ . '/../includes/ThaiDClient.php';
require_once __DIR__ . '/../includes/EkycService.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/OAuthAccount.php';
require_once __DIR__ . '/../models/Consent.php';

const THAID_STATE_TTL = 900;

$userModel = new User($conn);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    $row = $userModel->findByUsername($me);
    $userId = (int) ($row['id'] ?? 0);
}

try {
    if ($userId <= 0) {
        throw new RuntimeException('ไม่พบบัญชีผู้ใช้');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'start') {
        thaidStart($conn, $userId);
    } elseif (isset($_GET['code']) || isset($_GET['error'])) {
        thaidCallback($conn, $userId, $me);
    } else {
        redirectTo('kyc_verify.php');
    }
} catch (Throwable $e) {
    ErrorLogger::write('error', 'ThaiD verification failed', [
        'user_id' => $userId,
        'message' => $e->getMessage(),
    ]);
    thaidFinish('error', $e->getMessage());
}

function thaidStart(mysqli $conn, int $userId): void {
    // 'html': this is a browser navigation, so a JSON error body would be unreadable.
    verifyCsrfToken($_POST['csrf_token'] ?? '', 'html');

    if (empty($_POST['consent_kyc'])) {
        thaidFinish('error', 'กรุณายอมรับความยินยอมเก็บข้อมูล KYC ก่อนยืนยันตัวตน');
    }
    if (!ThaiDClient::isConfigured()) {
        thaidFinish('error', 'ระบบยังไม่ได้ตั้งค่า ThaiD กรุณาติดต่อผู้ดูแลระบบ');
    }

    // Recorded before leaving the site: the user consents to us asking DOPA for
    // their registry data, whether or not they finish the flow.
    (new Consent($conn))->record($userId, 'kyc_data_collection', KYC_CONSENT_VERSION);

    $verifier = (defined('THAID_USE_PKCE') && THAID_USE_PKCE) ? ThaiDClient::generateCodeVerifier() : null;
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));

    $_SESSION['thaid_auth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'user_id' => $userId,
        'created_at' => time(),
    ];

    header('Location: ' . ThaiDClient::authorizationUrl($state, $nonce, $verifier));
    exit;
}

function thaidCallback(mysqli $conn, int $userId, string $username): void {
    // Single use: pull the bundle out of the session before anything can fail,
    // so a replayed callback finds nothing to match against.
    $pending = $_SESSION['thaid_auth'] ?? null;
    unset($_SESSION['thaid_auth']);

    if (isset($_GET['error'])) {
        thaidFinish('error', 'ยกเลิกการยืนยันตัวตนกับ ThaiD แล้ว');
    }
    if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== $userId) {
        thaidFinish('error', 'ไม่พบคำขอยืนยันตัวตนที่เริ่มไว้ กรุณาเริ่มใหม่');
    }
    if (time() - (int) ($pending['created_at'] ?? 0) > THAID_STATE_TTL) {
        thaidFinish('error', 'คำขอยืนยันตัวตนหมดอายุ กรุณาเริ่มใหม่');
    }
    if (!hash_equals((string) $pending['state'], (string) ($_GET['state'] ?? ''))) {
        thaidFinish('error', 'state ไม่ตรงกับคำขอที่เริ่มไว้');
    }

    $tokens = ThaiDClient::exchangeCode((string) $_GET['code'], $pending['verifier'] ?? null);
    $claims = ThaiDClient::verifyIdToken((string) $tokens['id_token'], (string) $pending['nonce']);

    // The pid scope is not guaranteed to land in the id_token; userinfo carries
    // the same claims and is the documented fallback.
    if (empty($claims['pid']) && !empty($tokens['access_token'])) {
        $userInfo = ThaiDClient::userInfo((string) $tokens['access_token']);
        $subject = (string) ($claims['sub'] ?? '');
        if (isset($userInfo['sub']) && $subject !== '' && !hash_equals($subject, (string) $userInfo['sub'])) {
            throw new RuntimeException('ข้อมูลจาก userinfo ไม่ตรงกับ id_token');
        }
        // userinfo only fills gaps: the signed id_token claims stay authoritative,
        // and empty claims are dropped so a blank pid cannot mask the real one.
        $present = array_filter($claims, static fn($value): bool => $value !== '' && $value !== null);
        $claims = array_merge($userInfo, $present);
    }

    $pid = trim((string) ($claims['pid'] ?? ''));
    if ($pid === '') {
        throw new RuntimeException('ThaiD ไม่ได้ส่งเลขประจำตัวประชาชนกลับมา (ตรวจสอบ scope "pid")');
    }
    $pidIndex = EkycService::thaidPidIndex($pid);

    // One national ID, one verified account.
    $oauth = new OAuthAccount($conn);
    $existing = $oauth->findByProvider(EkycService::PROVIDER_THAID, $pidIndex);
    if ($existing && (int) $existing['user_id'] !== $userId) {
        throw new RuntimeException('เลขประจำตัวประชาชนนี้ถูกใช้ยืนยันกับบัญชีอื่นแล้ว');
    }
    $oauth->link($userId, EkycService::PROVIDER_THAID, $pidIndex, null);

    $ekyc = new EkycService($conn);

    if (!thaidMeetsAssuranceLevel($claims)) {
        $ekyc->markThaiDPending($userId, $pidIndex, $claims);
        thaidFinish('info', 'ยืนยันตัวตนกับ ThaiD แล้ว แต่ระดับความน่าเชื่อถือต่ำกว่าที่กำหนด ทีมงานจะตรวจสอบภายใน 24 ชม.');
    }

    $ekyc->applyThaiDResult($userId, thaidRealName($claims), $pidIndex, $claims);

    require_once __DIR__ . '/../includes/support_chat.php';
    sendSupportMessage(
        $conn,
        $username,
        "✅ ยืนยันตัวตนผ่าน ThaiD สำเร็จแล้ว!\n\nคุณสามารถถอนเงินและใช้งานฟีเจอร์ครบได้แล้ว หากมีคำถาม พิมพ์ถามในแชทนี้ได้เลย"
    );

    // Back to kyc_verify.php rather than the profile: it is the one page that reads
    // the flash, and it forwards to the profile once it sees the account verified.
    thaidFinish('success', 'ยืนยันตัวตนผ่าน ThaiD สำเร็จแล้ว');
}

/**
 * DOPA reports assurance as a dotted level (1.3, 2.2, 2.3). Compared with
 * version_compare so "1.3" sorts below "2.3" instead of comparing as text.
 *
 * @param array<string,mixed> $claims
 */
function thaidMeetsAssuranceLevel(array $claims): bool {
    $minimum = defined('THAID_MIN_IAL') ? (string) THAID_MIN_IAL : '2.3';
    if ($minimum === '') {
        return true;
    }
    $ial = trim((string) ($claims['ial'] ?? ''));
    // No ial claim means we cannot prove the level, so treat it as insufficient.
    return $ial !== '' && version_compare($ial, $minimum, '>=');
}

/** @param array<string,mixed> $claims */
function thaidRealName(array $claims): ?string {
    $given = trim((string) ($claims['given_name'] ?? ''));
    $family = trim((string) ($claims['family_name'] ?? ''));
    if ($given !== '' && $family !== '') {
        return $given . ' ' . $family;
    }
    $name = EkycService::stripThaiTitle((string) ($claims['name'] ?? ''));
    return $name !== '' ? $name : null;
}

function thaidFinish(string $type, string $message, string $target = 'kyc_verify.php'): void {
    $_SESSION['thaid_flash'] = ['type' => $type, 'message' => $message];
    redirectTo($target);
}
