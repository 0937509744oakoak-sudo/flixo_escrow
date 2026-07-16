<?php
/**
 * Serve product images from uploads volume (public).
 * Works even when Apache cannot see a Railway Volume path correctly.
 */
require_once __DIR__ . '/../includes/config.php';

$raw = (string) ($_GET['f'] ?? '');
$filename = basename(str_replace(['\\', "\0"], '', $raw));

if ($filename === '' || preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    http_response_code(400);
    exit('Invalid file');
}

$uploadsRoot = null;
foreach (['UPLOADS_PATH', 'RAILWAY_VOLUME_MOUNT_PATH'] as $envKey) {
    $val = getenv($envKey);
    if (is_string($val) && trim($val) !== '') {
        $candidate = rtrim(trim($val), "/\\");
        if (is_dir($candidate)) {
            $uploadsRoot = $candidate;
            break;
        }
    }
}
if ($uploadsRoot === null) {
    $uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
}

$candidates = [
    $uploadsRoot . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $filename,
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $filename,
];

$filepath = null;
foreach (array_unique($candidates) as $path) {
    if (is_file($path)) {
        $filepath = $path;
        break;
    }
}

if ($filepath === null) {
    $placeholder = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'product-placeholder.svg';
    if (is_file($placeholder)) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=300');
        readfile($placeholder);
        exit;
    }
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filepath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit;
