<?php
// includes/Rbac.php — role-based access control for admin staff
//
// Staff roles:
//   owner     — เจ้าของระบบ เข้าถึงได้ทุกอย่าง รวมถึงการมอบ role ('admin' เป็น alias เดิม)
//   finance   — การเงิน: ตรวจสลิป/Escrow และอนุมัติถอนเงิน (เห็น PII เท่าที่จำเป็น)
//   support   — ซัพพอร์ต: แชทช่วยเหลือ ข้อพิพาท และคำขอด้านข้อมูลส่วนบุคคล
//   moderator — ตรวจเนื้อหา: สินค้า รูปสินค้า แชท KYC และแบน/ปลดแบนสมาชิก

class Rbac {
    public const ALL_PERMISSIONS = [
        'admin.access',        // เข้าหลังบ้านได้
        'escrow.manage',       // ตรวจสลิป / จัดการ Escrow
        'withdrawal.manage',   // อนุมัติ-ปฏิเสธคำขอถอนเงิน
        'pii.view',            // เห็นข้อมูลส่วนบุคคลแบบไม่ปิดบัง
        'support.inbox',       // แชทช่วยเหลือ
        'dispute.resolve',     // จัดการข้อพิพาท
        'privacy.requests',    // คำขอ PDPA
        'products.manage',     // จัดการประกาศสินค้า
        'moderation.queue',    // คิวตรวจ AI (รูปสินค้า / แชท)
        'kyc.review',          // ตรวจเอกสาร KYC (รวมดูรูปบัตร/เซลฟี่)
        'users.manage',        // แบน / ปลดแบนสมาชิก
        'roles.manage',        // มอบ role ให้พนักงาน (owner เท่านั้น)
        'logs.view',           // system logs
        'audit.view',          // PII audit log
        'notifications.view',  // ประวัติการแจ้งเตือน
    ];

    private const PERMISSIONS = [
        'owner' => ['*'],
        'admin' => ['*'], // legacy alias ของ owner
        'finance' => [
            'admin.access',
            'escrow.manage',
            'withdrawal.manage',
            'pii.view',
        ],
        'support' => [
            'admin.access',
            'support.inbox',
            'dispute.resolve',
            'privacy.requests',
            'notifications.view',
        ],
        'moderator' => [
            'admin.access',
            'products.manage',
            'moderation.queue',
            'kyc.review',
            'users.manage',
        ],
        'user' => [],
    ];

    private const ROLE_LABELS = [
        'owner' => 'Owner (เจ้าของระบบ)',
        'admin' => 'Owner (เจ้าของระบบ)',
        'finance' => 'Finance (การเงิน)',
        'support' => 'Support (ซัพพอร์ต)',
        'moderator' => 'Moderator (ตรวจเนื้อหา)',
        'user' => 'สมาชิกทั่วไป',
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
        $perms = self::PERMISSIONS[$role] ?? [];
        if (in_array('*', $perms, true)) {
            return true;
        }
        return in_array($permission, $perms, true);
    }

    public static function isStaff(string $role): bool {
        return self::can($role, 'admin.access');
    }

    /** Role ทั้งหมดที่มอบให้ผ่านหน้าจัดการสมาชิกได้ */
    public static function assignableRoles(): array {
        return ['user', 'finance', 'support', 'moderator', 'owner'];
    }

    public static function roleLabel(string $role): string {
        return self::ROLE_LABELS[strtolower($role)] ?? $role;
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
