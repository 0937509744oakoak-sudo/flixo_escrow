<?php


class OrderStateMachine {

    const CREATED         = 'CREATED';
    const PENDING_PAYMENT = 'PENDING_PAYMENT';
    const WAITING_VERIFY  = 'WAITING_VERIFY';
    const PAID            = 'PAID';
    const SHIPPED         = 'SHIPPED';
    const COMPLETED       = 'COMPLETED';
    const CANCELLED       = 'CANCELLED';
    const DISPUTE         = 'DISPUTE';

 
    const PENDING_CONFIRM = 'CREATED';
    const PENDING_VERIFY  = 'WAITING_VERIFY';

    
    private static $allowedTransitions = [
        self::CREATED         => [self::PENDING_PAYMENT, self::CANCELLED],
        self::PENDING_PAYMENT => [self::WAITING_VERIFY, self::CANCELLED],
        self::WAITING_VERIFY  => [self::PAID, self::CANCELLED],
        self::PAID            => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED         => [self::COMPLETED, self::DISPUTE],
        self::DISPUTE         => [self::COMPLETED, self::CANCELLED], // แอดมินตัดสินเท่านั้น
        self::COMPLETED       => [], // สิ้นสุด
        self::CANCELLED       => []  // สิ้นสุด
    ];

  
    public static function isValidTransition($currentStatus, $newStatus) {
        if (!isset(self::$allowedTransitions[$currentStatus])) {
            return false;
        }
        return in_array($newStatus, self::$allowedTransitions[$currentStatus]);
    }

    /**
     * 4. ตรวจสอบสิทธิ์ (ใครมีสิทธิ์เปลี่ยนเป็นสถานะไหน?)
     * @param string $role (buyer, seller, admin)
     */
    public static function canUserPerformAction($newStatus, $userRole) {
        $permissions = [
            self::WAITING_VERIFY  => ['buyer'],           // ผู้ซื้อเท่านั้นที่แจ้งโอนได้
            self::PAID            => ['admin'],           // แอดมิน/ระบบ เท่านั้นที่ยืนยันเงินเข้าได้
            self::SHIPPED         => ['seller', 'admin'],  // ผู้ขายแจ้งส่งของ (แอดมินช่วยได้)
            self::COMPLETED       => ['buyer', 'admin'],  // ผู้ซื้อยืนยันรับของ หรือ แอดมินบังคับจบ
            self::DISPUTE         => ['buyer'],           // ผู้ซื้อเท่านั้นที่เปิดข้อพิพาทได้
            self::CANCELLED       => ['buyer', 'admin'],  // ผู้ซื้อขอยกเลิก (ถ้ายังไม่จ่าย) หรือแอดมินสั่ง
            self::PENDING_PAYMENT => ['buyer', 'seller']           // ผู้ขายกดยืนยันข้อเสนอ/สร้างออเดอร์
        ];

        if (!isset($permissions[$newStatus])) return false;
        return in_array($userRole, $permissions[$newStatus]);
    }

    /**
     * 5. ฟังก์ชันหลักสำหรับตรวจสอบความถูกต้องทั้งหมด (The Gatekeeper)
     * @return bool
     * @throws Exception ถ้าไม่ผ่านกฎ
     */
    public static function validate($order, $newStatus, $user) {
        $currentStatus = $order['status'];
        $userRole = $user['role']; // ข้อมูลจาก Session

        
        if (!self::isValidTransition($currentStatus, $newStatus)) {
            throw new Exception("ลำดับขั้นตอนไม่ถูกต้อง: ไม่สามารถเปลี่ยนจาก $currentStatus ไปเป็น $newStatus ได้");
        }

      
        if ($userRole !== 'admin') {
            if ($userRole === 'buyer' && $order['buyer_name'] !== $user['username']) {
                throw new Exception("คุณไม่มีสิทธิ์จัดการออเดอร์ของผู้ซื้อรายอื่น");
            }
            if ($userRole === 'seller' && $order['seller_name'] !== $user['username']) {
                throw new Exception("คุณไม่มีสิทธิ์จัดการออเดอร์ของผู้ขายรายอื่น");
            }
        }

    
        if (!self::canUserPerformAction($newStatus, $userRole)) {
            throw new Exception("บทบาทของคุณ ($userRole) ไม่มีสิทธิ์ดำเนินการในขั้นตอนนี้");
        }

        return true;
    }

    
    public static function getLabel($status) {
        $labels = [
            self::CREATED         => 'รอรับข้อเสนอ',
            self::PENDING_PAYMENT => 'รอชำระเงิน',
            self::WAITING_VERIFY  => 'รอตรวจสอบสลิป',
            self::PAID            => 'รอจัดส่ง',
            self::SHIPPED         => 'รอผู้ซื้อรับของ',
            self::COMPLETED       => 'สำเร็จ (โอนเงินแล้ว)',
            self::CANCELLED       => 'ยกเลิก',
            self::DISPUTE         => 'มีข้อพิพาท'
        ];
        return $labels[$status] ?? 'ไม่ทราบสถานะ';
    }

  
    public static function getColor($status) {
        $colors = [
            self::CREATED         => '#6c757d',
            self::PENDING_PAYMENT => '#ffc107',
            self::WAITING_VERIFY  => '#17a2b8',
            self::PAID            => '#fd7e14',
            self::SHIPPED         => '#007bff',
            self::COMPLETED       => '#28a745',
            self::CANCELLED       => '#dc3545',
            self::DISPUTE         => '#dc3545'
        ];
        return $colors[$status] ?? '#6c757d';
    }
}