<?php
// includes/Rbac.php — role-based access control for admin staff

class Rbac {
    private const PERMISSIONS = [
        'admin' => ['admin.access', 'pii.view', 'dispute.resolve', 'escrow.manage', 'withdrawal.manage', 'users.manage', 'support.inbox', 'privacy.requests'],
        'finance' => ['admin.access', 'escrow.manage', 'withdrawal.manage'],
        'support' => ['admin.access', 'dispute.resolve', 'support.inbox', 'privacy.requests'],
        'user' => [],
    ];

    public static function roleOf(mysqli $conn, string $username): string {
        $stmt = $conn->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row['role'] ?? 'user';
    }

    public static function can(string $role, string $permission): bool {
        $role = strtolower($role);
        if ($role === 'admin') {
            return true;
        }
        return in_array($permission, self::PERMISSIONS[$role] ?? [], true);
    }

    public static function isStaff(string $role): bool {
        return self::can($role, 'admin.access');
    }

    public static function requirePermission(mysqli $conn, string $username, string $permission): void {
        $role = self::roleOf($conn, $username);
        if (!self::can($role, $permission)) {
            http_response_code(403);
            exit('Access Denied');
        }
    }

    public static function maskPhone(?string $phone, string $role): string {
        if ($phone === null || $phone === '') {
            return '-';
        }
        if (self::can($role, 'pii.view')) {
            return $phone;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 6) {
            return '***';
        }
        return substr($digits, 0, 3) . '-***-' . substr($digits, -4);
    }
}
