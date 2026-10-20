<?php
// includes/db.php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);

// ใช้ค่าจาก Config
$conn = mysqli_init();

// A database that accepts the TCP connection but never answers (a wedged MariaDB
// on startup, for example) would otherwise hang every request forever, taking the
// whole site down instead of showing the maintenance page below.
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int) (getenv('DB_CONNECT_TIMEOUT') ?: 5));
if (php_sapi_name() !== 'cli') {
    // CLI is exempt: migrations and batch scripts legitimately run long queries.
    $conn->options(MYSQLI_OPT_READ_TIMEOUT, (int) (getenv('DB_READ_TIMEOUT') ?: 30));
}

if (!@$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
    $connectError = mysqli_connect_error() ?: $conn->connect_error;
    error_log('Database connection failed: ' . $connectError);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Database connection failed: {$connectError}\n");
        exit(1);
    }
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Maintenance</title>
        <style>
            body { font-family: sans-serif; text-align: center; padding: 50px; background: #f4f6f8; }
            .error-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); display: inline-block; }
            h1 { color: #e74c3c; margin-bottom: 10px; }
            p { color: #666; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>🔧 ระบบกำลังปรับปรุง</h1>
            <p>ขออภัยในความไม่สะดวก ขณะนี้เราไม่สามารถเชื่อมต่อฐานข้อมูลได้<br>กรุณาลองใหม่อีกครั้งในภายหลัง</p>
        </div>
    </body>
    </html>
    <?php
    exit();
}

mysqli_set_charset($conn, "utf8mb4");

?>