<?php
if (php_sapi_name() !== 'cli') exit("CLI only\n");
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../includes/init.php';
$user = $argv[1] ?? 'test1';
$r = $conn->query("SELECT id,user_name,type,title,message,is_read,created_at FROM notifications WHERE user_name='" . $conn->real_escape_string($user) . "' ORDER BY id DESC LIMIT 6");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
