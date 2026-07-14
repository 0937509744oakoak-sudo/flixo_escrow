<?php
// includes/db.php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);

// ใช้ค่าจาก Config
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    $connectError = mysqli_connect_error();
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