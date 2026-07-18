<?php
// includes/config.php

// ==========================================
// Load .env file for local development
// ==========================================
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

// ==========================================
// 1. Database Configuration
// ==========================================
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'flixo_db');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3307);

// ==========================================
// 2. System Configuration
// ==========================================
/**
 * Build public base URL from the current HTTP request.
 * Works with ngrok / reverse proxies (X-Forwarded-Host / Proto).
 */
$detectRequestBaseUrl = static function (): string {
    if (empty($_SERVER['HTTP_HOST'])) {
        return 'http://localhost/flixo_escrow';
    }

    $fwdProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $fwdProto = trim(explode(',', $fwdProto)[0]);
    $httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $fwdProto === 'https'
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $httpsOn ? 'https' : 'http';

    $fwdHost = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
    $host = trim(explode(',', $fwdHost !== '' ? $fwdHost : (string) $_SERVER['HTTP_HOST'])[0]);
    $host = preg_replace('/[^a-zA-Z0-9.\-\:\[\]]/', '', $host) ?: 'localhost';

    // Prefer filesystem path to project root (stable under /admin, /api, etc.)
    $basePath = '/flixo_escrow';
    $projectFs = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    if ($projectFs !== '' && $docRoot !== '' && str_starts_with($projectFs, $docRoot)) {
        $basePath = substr($projectFs, strlen($docRoot)) ?: '';
    } else {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.+?)/(?:admin|api|scripts|controllers)/#', $script, $m)) {
            $basePath = $m[1];
        } else {
            $basePath = rtrim(dirname($script), '/');
        }
    }
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
};

$requestBaseUrl = $detectRequestBaseUrl();
$envBaseUrl = rtrim((string) (getenv('BASE_URL') ?: ''), '/');

$isLocalHost = static function (?string $host): bool {
    $host = strtolower((string) $host);
    return $host === 'localhost' || $host === '127.0.0.1' || str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1:');
};

// If .env still says localhost but the browser hits ngrok/LAN, follow the request
// so CSS/JS/assets load. Production domains in .env always win.
if ($envBaseUrl === '') {
    $resolvedBaseUrl = $requestBaseUrl;
} elseif ($isLocalHost(parse_url($envBaseUrl, PHP_URL_HOST)) && !$isLocalHost(parse_url($requestBaseUrl, PHP_URL_HOST))) {
    $resolvedBaseUrl = $requestBaseUrl;
} else {
    $resolvedBaseUrl = $envBaseUrl;
}

define('BASE_URL', rtrim($resolvedBaseUrl, '/'));
define('SYSTEM_NAME', getenv('SYSTEM_NAME') ?: 'FLIXO Escrow');

// ==========================================
// 3. Payment & Escrow Configuration
// ==========================================
define('PROMPTPAY_NUMBER', getenv('PROMPTPAY_NUMBER') ?: '0937509744');
define('PROMPTPAY_NAME', getenv('PROMPTPAY_NAME') ?: 'บริษัท ฟลิกโซ่ เอสโครว์ จำกัด');

// ==========================================
// 4. External API / Secret Configuration
// ==========================================
define('SLIP_API_QR_URL', getenv('SLIP_API_QR_URL') ?: 'https://developer.easyslip.com/api/v1/qr/generate');
define('SLIP_API_URL', getenv('SLIP_API_URL') ?: 'https://developer.easyslip.com/api/v1/verify');
define('SLIP_API_KEY', getenv('SLIP_API_KEY') ?: '');

define('OMISE_PUBLIC_KEY', getenv('OMISE_PUBLIC_KEY') ?: '');
define('OMISE_SECRET_KEY', getenv('OMISE_SECRET_KEY') ?: '');
define('OMISE_SANDBOX', getenv('OMISE_SANDBOX') === '1' || str_starts_with(getenv('OMISE_SECRET_KEY') ?: '', 'skey_test_'));
define('OMISE_API_BASE', OMISE_SANDBOX ? 'https://api.omise.co' : 'https://api.omise.co');

define('MAIL_ENABLED', getenv('MAIL_ENABLED') === '1');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'noreply@localhost');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'FLIXO Escrow');
define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: '');
define('MAIL_SMTP_PORT', getenv('MAIL_SMTP_PORT') ? (int)getenv('MAIL_SMTP_PORT') : 587);
define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: '');
define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMTP_PASSWORD') ?: '');
define('MAIL_SMTP_SECURE', getenv('MAIL_SMTP_SECURE') ?: 'tls');
define('MAIL_USE_PHP_MAIL', getenv('MAIL_USE_PHP_MAIL') === '1');

define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'FLIXO_SECRET_DEMO_2026');
define('FORCE_HTTPS', getenv('FORCE_HTTPS') === '1');

// Application environment: local | staging | production
define('APP_ENV', getenv('APP_ENV') ?: 'local');

// AES-256-GCM encryption key for PII at rest (min 32 characters in production)
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');

// Error logging (auto-enabled in production unless LOG_ERRORS=0)
$logErrorsEnv = getenv('LOG_ERRORS');
define('LOG_ERRORS', $logErrorsEnv === '1' || ($logErrorsEnv !== '0' && APP_ENV === 'production'));
define('LOG_PATH', getenv('LOG_PATH') ?: '');

// eKYC — provider: auto | manual | sumsub | aigen
define('EKYC_PROVIDER', getenv('EKYC_PROVIDER') ?: 'auto');
define('SUMSUB_APP_TOKEN', getenv('SUMSUB_APP_TOKEN') ?: '');
define('SUMSUB_SECRET_KEY', getenv('SUMSUB_SECRET_KEY') ?: '');
define('SUMSUB_LEVEL_NAME', getenv('SUMSUB_LEVEL_NAME') ?: 'id-and-liveness');
define('SUMSUB_WEBHOOK_SECRET', getenv('SUMSUB_WEBHOOK_SECRET') ?: '');
define('SUMSUB_API_BASE', 'https://api.sumsub.com');
// AiGen (developer.aigen.online) — Thai ID OCR + Face Compare trial
define('AIGEN_API_KEY', getenv('AIGEN_API_KEY') ?: '');
define('AIGEN_API_BASE', getenv('AIGEN_API_BASE') ?: 'https://api.aigen.online');
define('AIGEN_FACE_THRESHOLD', getenv('AIGEN_FACE_THRESHOLD') !== false && getenv('AIGEN_FACE_THRESHOLD') !== ''
    ? (int) getenv('AIGEN_FACE_THRESHOLD') : 80);
define('EKYC_REQUIRED_FOR_SELL', getenv('EKYC_REQUIRED_FOR_SELL') === '1');

// AI product image fraud: Tier 1 EXIF (free) → Tier 2 Google Cloud Vision (suspicious only)
define('IMAGE_FRAUD_PROVIDER', getenv('IMAGE_FRAUD_PROVIDER') ?: 'auto');
define('GOOGLE_CLOUD_VISION_API_KEY', getenv('GOOGLE_CLOUD_VISION_API_KEY') ?: '');
define('IMAGE_FRAUD_BLOCK', getenv('IMAGE_FRAUD_BLOCK') !== '0');
define('IMAGE_FRAUD_BLOCK_THRESHOLD', getenv('IMAGE_FRAUD_BLOCK_THRESHOLD') !== false && getenv('IMAGE_FRAUD_BLOCK_THRESHOLD') !== ''
    ? (float)getenv('IMAGE_FRAUD_BLOCK_THRESHOLD') : 75.0);
define('IMAGE_FRAUD_FLAG_THRESHOLD', getenv('IMAGE_FRAUD_FLAG_THRESHOLD') !== false && getenv('IMAGE_FRAUD_FLAG_THRESHOLD') !== ''
    ? (float)getenv('IMAGE_FRAUD_FLAG_THRESHOLD') : 50.0);
define('IMAGE_FRAUD_MIN_FILE_BYTES', getenv('IMAGE_FRAUD_MIN_FILE_BYTES') !== false && getenv('IMAGE_FRAUD_MIN_FILE_BYTES') !== ''
    ? (int)getenv('IMAGE_FRAUD_MIN_FILE_BYTES') : 80000);
define('IMAGE_FRAUD_EXIF_TRUST_THRESHOLD', getenv('IMAGE_FRAUD_EXIF_TRUST_THRESHOLD') !== false && getenv('IMAGE_FRAUD_EXIF_TRUST_THRESHOLD') !== ''
    ? (int)getenv('IMAGE_FRAUD_EXIF_TRUST_THRESHOLD') : 65);

// AI chat monitoring — Tier 1 regex (free) → Tier 2 Gemini AI (suspicious only)
define('CHAT_MODERATION_ENABLED', getenv('CHAT_MODERATION_ENABLED') !== '0');
define('CHAT_MODERATION_BLOCK', getenv('CHAT_MODERATION_BLOCK') !== '0');
define('CHAT_MODERATION_BLOCK_THRESHOLD', getenv('CHAT_MODERATION_BLOCK_THRESHOLD') !== false && getenv('CHAT_MODERATION_BLOCK_THRESHOLD') !== ''
    ? (float)getenv('CHAT_MODERATION_BLOCK_THRESHOLD') : 70.0);
define('CHAT_MODERATION_FLAG_THRESHOLD', getenv('CHAT_MODERATION_FLAG_THRESHOLD') !== false && getenv('CHAT_MODERATION_FLAG_THRESHOLD') !== ''
    ? (float)getenv('CHAT_MODERATION_FLAG_THRESHOLD') : 45.0);
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.5-flash');
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
define('CHAT_MODERATION_AI_ENABLED', getenv('CHAT_MODERATION_AI_ENABLED') !== '0');
define('CHAT_MODERATION_AI_MIN_SCORE', getenv('CHAT_MODERATION_AI_MIN_SCORE') !== false && getenv('CHAT_MODERATION_AI_MIN_SCORE') !== ''
    ? (float)getenv('CHAT_MODERATION_AI_MIN_SCORE') : 25.0);
define('CHAT_MODERATION_AI_SKIP_ABOVE_SCORE', getenv('CHAT_MODERATION_AI_SKIP_ABOVE_SCORE') !== false && getenv('CHAT_MODERATION_AI_SKIP_ABOVE_SCORE') !== ''
    ? (float)getenv('CHAT_MODERATION_AI_SKIP_ABOVE_SCORE') : 80.0);
define('CHAT_MODERATION_GEMINI_MODEL', getenv('CHAT_MODERATION_GEMINI_MODEL') ?: 'gemini-2.0-flash');

// AI Dispute Assistant — Groq (preferred) → OpenRouter → Gemini → heuristic
define('DISPUTE_AI_ENABLED', getenv('DISPUTE_AI_ENABLED') !== '0');
define('DISPUTE_AI_GEMINI_MODEL', getenv('DISPUTE_AI_GEMINI_MODEL') ?: 'gemini-2.0-flash');
define('DISPUTE_AI_MODEL', getenv('DISPUTE_AI_MODEL') ?: (getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.5-flash'));
define('DISPUTE_AI_GROQ_MODEL', getenv('DISPUTE_AI_GROQ_MODEL') ?: (getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile'));
define('CHAT_MODERATION_GROQ_MODEL', getenv('CHAT_MODERATION_GROQ_MODEL') ?: (getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile'));
define('DISPUTE_SELLER_RESPONSE_HOURS', getenv('DISPUTE_SELLER_RESPONSE_HOURS') !== false && getenv('DISPUTE_SELLER_RESPONSE_HOURS') !== ''
    ? (int)getenv('DISPUTE_SELLER_RESPONSE_HOURS') : 72);
define('DISPUTE_EVIDENCE_HOURS', getenv('DISPUTE_EVIDENCE_HOURS') !== false && getenv('DISPUTE_EVIDENCE_HOURS') !== ''
    ? (int)getenv('DISPUTE_EVIDENCE_HOURS') : 48);

// Support chat account (auto-created on first use if missing)
define('SUPPORT_USERNAME', getenv('SUPPORT_USERNAME') ?: 'FLIXO_Support');

// Google Sign-In (GIS) — Client ID only; verify ID tokens server-side
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');

// Web Push (VAPID) — generate with: php scripts/generate_vapid_keys.php
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: '');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: '');
define('VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:support@flixo.local');
define('PUSH_NOTIFICATIONS_ENABLED', VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '');

// Important: ใน Production ให้เก็บค่าลับใน Environment Variables เท่านั้น
// หากต้องการทดสอบในเครื่อง ให้ตั้งตัวแปร SLIP_API_KEY หรือ MAIL_ENABLED ในสภาพแวดล้อมแทนการแก้โค้ดโดยตรง

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/..'));
}

require_once __DIR__ . '/ErrorLogger.php';
ErrorLogger::register();
