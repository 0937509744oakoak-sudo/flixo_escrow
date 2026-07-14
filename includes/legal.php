<?php
// includes/legal.php — PDPA document versions (bump when policies change)

if (!defined('TERMS_VERSION')) {
    define('TERMS_VERSION', '1.1');
}
if (!defined('PRIVACY_VERSION')) {
    define('PRIVACY_VERSION', '1.1');
}
if (!defined('KYC_CONSENT_VERSION')) {
    define('KYC_CONSENT_VERSION', '1.0');
}

function getClientIp(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return substr($ip, 0, 45);
}

function getClientUserAgent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
}
