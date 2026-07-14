<?php
// includes/PiiAudit.php — audit trail when admins access sensitive PII

require_once __DIR__ . '/legal.php';

function logPiiAccess(mysqli $conn, string $adminUsername, string $targetUsername, string $action, string $resourceType, ?string $resourceId = null): void {
    $ip = getClientIp();
    $ua = getClientUserAgent();
    $sql = "INSERT INTO pii_audit_logs (admin_username, target_username, action, resource_type, resource_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('PII audit log prepare failed: ' . $conn->error);
        return;
    }
    $stmt->bind_param('sssssss', $adminUsername, $targetUsername, $action, $resourceType, $resourceId, $ip, $ua);
    $stmt->execute();
}
