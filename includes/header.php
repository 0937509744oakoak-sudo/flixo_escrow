<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?php echo rtrim(BASE_URL, '/'); ?>/manifest.webmanifest">
    <?php $pwaIcon = defined('ASSETS_URL') ? ASSETS_URL . '/icons/icon-192.svg' : 'assets/icons/icon-192.svg'; ?>
    <link rel="apple-touch-icon" href="<?php echo $pwaIcon; ?>">
    <title><?php echo isset($page_title) ? $page_title . ' | FLIXO' : 'FLIXO'; ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hanken+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <?php if (!empty($use_app_shell) || !empty($load_flixo_css)): ?>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet" />
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/flixo-app.css?v=<?php echo time(); ?>">
    <?php endif; ?>
    
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/page-loader.css?v=<?php echo time(); ?>">
    
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/chat.css?v=<?php echo time(); ?>">
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php
    require_once __DIR__ . '/csrf.php';
    $csrf_token = getCsrfToken();
    ?>
    <script>
        const CSRF_TOKEN = "<?php echo $csrf_token; ?>";
        window.CSRF_TOKEN = CSRF_TOKEN;
        const originalFetch = window.fetch;

        window.fetch = function(resource, config) {
            config = config || {};
            const method = (config.method || 'GET').toUpperCase();

            if (method === 'POST') {
                if (config.body instanceof FormData) {
                    config.body.set('csrf_token', CSRF_TOKEN);
                }
                const headers = new Headers(config.headers || {});
                if (!headers.has('X-CSRF-Token') && CSRF_TOKEN) {
                    headers.set('X-CSRF-Token', CSRF_TOKEN);
                }
                config.headers = headers;
                if (config.credentials === undefined) {
                    config.credentials = 'same-origin';
                }
            }

            return originalFetch(resource, config);
        };
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet" />
</head>
<body>
<div id="page-loader" class="page-loader" aria-busy="true" aria-label="กำลังโหลด">
    <div class="page-loader-stage">
        <?php for ($x = 0; $x < 50; $x++): ?>
        <div class="page-loader-dot"></div>
        <?php endfor; ?>
    </div>
    <p class="page-loader-label">FLIXO</p>
</div>
<script src="<?php echo ASSETS_URL; ?>/js/page-loader.js?v=<?php echo time(); ?>"></script>