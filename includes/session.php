<?php



if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_trans_sid', 0);
    ini_set('session.cookie_samesite', 'Lax');

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    ini_set('session.cookie_secure', $secure ? 1 : 0);

    $hostName = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $cookieDomain = '';
    if ($hostName !== '' && !in_array($hostName, ['localhost', '127.0.0.1'], true)) {
        $cookieDomain = $hostName;
    }

    session_name('flixo_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookieDomain,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// สร้าง CSRF token หากยังไม่มี
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>