<?php
// models/Dispute.php

class Dispute {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    /**
     * สร้างข้อพิพาท
     */
    public function create($order_id, $initiator, $reason, $description = '') {
        $status = 'SELLER_RESPONSE';
        $created_at = date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO disputes (order_id, initiator, reason, description, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log('Dispute create prepare failed: ' . $this->conn->error);
            return false;
        }
        
        $order_id = (int)$order_id;
        $stmt->bind_param("isssss", $order_id, $initiator, $reason, $description, $status, $created_at);
        return $stmt->execute() ? $this->conn->insert_id : false;
    }

    /**
     * หาข้อพิพาทตามหมายเลขออเดอร์
     */
    public function findByOrderId($order_id) {
        $order_id = (int)$order_id;
        $sql = "SELECT * FROM disputes WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * หาข้อพิพาทตามหมายเลขข้อพิพาท
     */
    public function findById($dispute_id) {
        $dispute_id = (int)$dispute_id;
        $sql = "SELECT * FROM disputes WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $dispute_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * ดึงรายการข้อพิพาทของผู้ใช้
     */
    public function getUserDisputes($username, $limit = 50, $offset = 0) {
        $limit = (int)$limit;
        $offset = (int)$offset;
        $sql = "SELECT d.*, o.item_name, o.buyer_name, o.seller_name, o.amount, o.status as order_status
                FROM disputes d
                JOIN orders o ON d.order_id = o.id
                WHERE d.initiator = ? OR o.buyer_name = ? OR o.seller_name = ?
                ORDER BY d.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssii", $username, $username, $username, $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * ดึงหลักฐาน (Evidence) ของข้อพิพาท
     */
    public function getEvidences($dispute_id) {
        $dispute_id = (int)$dispute_id;
        $sql = "SELECT * FROM dispute_evidences WHERE dispute_id = ? ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $dispute_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * เพิ่มหลักฐาน (ภาพ/ไฟล์)
     */
    public function addEvidence($dispute_id, $uploader, $description, $file_path) {
        $dispute_id = (int)$dispute_id;
        $sql = "INSERT INTO dispute_evidences (dispute_id, uploader, description, file_path, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log('Evidence add prepare failed: ' . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("isss", $dispute_id, $uploader, $description, $file_path);
        return $stmt->execute();
    }

    /**
     * เพิ่มความเห็นในข้อพิพาท
     */
    public function addComment($dispute_id, $commenter, $comment, $comment_type = 'general') {
        $dispute_id = (int)$dispute_id;
        $hasType = $this->hasCommentTypeColumn();
        if ($hasType) {
            $sql = "INSERT INTO dispute_comments (dispute_id, commenter, comment, comment_type, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                error_log('Comment add prepare failed: ' . $this->conn->error);
                return false;
            }
            $stmt->bind_param("isss", $dispute_id, $commenter, $comment, $comment_type);
            return $stmt->execute();
        }

        $sql = "INSERT INTO dispute_comments (dispute_id, commenter, comment, created_at) 
                VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log('Comment add prepare failed: ' . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("iss", $dispute_id, $commenter, $comment);
        return $stmt->execute();
    }

    /**
     * ดึงความเห็นของข้อพิพาท
     */
    public function getComments($dispute_id) {
        $dispute_id = (int)$dispute_id;
        $sql = "SELECT * FROM dispute_comments WHERE dispute_id = ? ORDER BY created_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $dispute_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * อัปเดตสถานะข้อพิพาท
     */
    public function updateStatus($dispute_id, $status, $resolution = '') {
        $dispute_id = (int)$dispute_id;
        $resolved_at = ($status === 'RESOLVED') ? date('Y-m-d H:i:s') : null;
        
        $sql = "UPDATE disputes SET status = ?, resolution = ?, resolved_at = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssi", $status, $resolution, $resolved_at, $dispute_id);
        return $stmt->execute();
    }

    public function resolve(int $disputeId, string $resolution, string $adminUser, ?float $buyerRefund = null, ?float $sellerPayout = null, string $note = ''): bool {
        $sql = "UPDATE disputes SET status = 'RESOLVED', resolution = ?, resolved_at = NOW(), resolved_by = ?,
                buyer_refund_amount = ?, seller_payout_amount = ?, admin_note = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssddsi', $resolution, $adminUser, $buyerRefund, $sellerPayout, $note, $disputeId);
        return $stmt->execute();
    }

    public function setSlaDue(int $disputeId, int $days = 7): bool {
        $stmt = $this->conn->prepare("UPDATE disputes SET sla_due_at = DATE_ADD(created_at, INTERVAL ? DAY) WHERE id = ? AND sla_due_at IS NULL");
        $stmt->bind_param('ii', $days, $disputeId);
        return $stmt->execute();
    }

    /**
     * นับข้อพิพาทที่รอแก้ไขของผู้ใช้
     */
    public function getPendingCount($username) {
        $statuses = "'SELLER_RESPONSE','EVIDENCE_REVIEW','SETTLEMENT_PENDING','PENDING'";
        $sql = "SELECT COUNT(*) as count FROM disputes 
                WHERE status IN ({$statuses}) 
                AND (initiator = ? OR id IN (
                    SELECT d.id FROM disputes d 
                    JOIN orders o ON d.order_id = o.id 
                    WHERE (o.buyer_name = ? OR o.seller_name = ?)
                ))";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $username, $username, $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    }

    private function hasCommentTypeColumn(): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $r = $this->conn->query("SHOW COLUMNS FROM dispute_comments LIKE 'comment_type'");
        $exists = $r && $r->num_rows > 0;
        return $exists;
    }
}
