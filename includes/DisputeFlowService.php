<?php
// includes/DisputeFlowService.php — phased dispute flow (seller response → evidence → settlement/admin)

require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/OrderStateMachine.php';
require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/order_helper.php';

class DisputeFlowService {
    public const STATUS_SELLER_RESPONSE = 'SELLER_RESPONSE';
    public const STATUS_EVIDENCE_REVIEW = 'EVIDENCE_REVIEW';
    public const STATUS_SETTLEMENT_PENDING = 'SETTLEMENT_PENDING';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RESOLVED = 'RESOLVED';

    public const SETTLEMENT_PENDING = 'PENDING';
    public const SETTLEMENT_ACCEPTED = 'ACCEPTED';
    public const SETTLEMENT_REJECTED = 'REJECTED';
    public const SETTLEMENT_COUNTERED = 'COUNTERED';
    public const SETTLEMENT_EXPIRED = 'EXPIRED';

    private mysqli $conn;
    private Dispute $disputeModel;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->disputeModel = new Dispute($conn);
    }

    public static function sellerResponseHours(): int {
        $v = getenv('DISPUTE_SELLER_RESPONSE_HOURS');
        if ($v !== false && $v !== '') {
            return max(1, (int)$v);
        }
        return defined('DISPUTE_SELLER_RESPONSE_HOURS') ? (int)DISPUTE_SELLER_RESPONSE_HOURS : 72;
    }

    public static function evidenceHours(): int {
        $v = getenv('DISPUTE_EVIDENCE_HOURS');
        if ($v !== false && $v !== '') {
            return max(1, (int)$v);
        }
        return defined('DISPUTE_EVIDENCE_HOURS') ? (int)DISPUTE_EVIDENCE_HOURS : 48;
    }

    public static function openStatuses(): array {
        return [
            self::STATUS_SELLER_RESPONSE,
            self::STATUS_EVIDENCE_REVIEW,
            self::STATUS_SETTLEMENT_PENDING,
            self::STATUS_PENDING,
        ];
    }

    public function initializeNewDispute(int $disputeId): void {
        $hours = self::sellerResponseHours();
        $stmt = $this->conn->prepare(
            "UPDATE disputes SET status = ?, seller_response_due_at = DATE_ADD(NOW(), INTERVAL ? HOUR)
             WHERE id = ?"
        );
        $status = self::STATUS_SELLER_RESPONSE;
        $stmt->bind_param('sii', $status, $hours, $disputeId);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function syncAndGet(int $disputeId): array {
        $dispute = $this->disputeModel->findById($disputeId);
        if (!$dispute) {
            throw new RuntimeException('ไม่พบข้อพิพาท');
        }
        $this->advancePhase($dispute);
        $refreshed = $this->disputeModel->findById($disputeId);
        return $refreshed ?: $dispute;
    }

    /**
     * @param array<string, mixed> $dispute
     */
    public function advancePhase(array $dispute): void {
        if (($dispute['status'] ?? '') === self::STATUS_RESOLVED) {
            return;
        }

        $disputeId = (int)$dispute['id'];
        $status = (string)($dispute['status'] ?? '');

        if ($status === self::STATUS_PENDING && empty($dispute['seller_response_due_at'])) {
            $hours = self::sellerResponseHours();
            $stmt = $this->conn->prepare(
                "UPDATE disputes SET status = ?, seller_response_due_at = DATE_ADD(created_at, INTERVAL ? HOUR)
                 WHERE id = ?"
            );
            $newStatus = self::STATUS_SELLER_RESPONSE;
            $stmt->bind_param('sii', $newStatus, $hours, $disputeId);
            $stmt->execute();
            $dispute['status'] = self::STATUS_SELLER_RESPONSE;
            $status = self::STATUS_SELLER_RESPONSE;
        }

        if ($status === self::STATUS_SELLER_RESPONSE && empty($dispute['seller_responded_at'])) {
            $due = $dispute['seller_response_due_at'] ?? null;
            if ($due && strtotime($due) <= time()) {
                $this->enterEvidenceReview($disputeId, true);
            }
        }
    }

    private function enterEvidenceReview(int $disputeId, bool $sellerNoResponse = false): void {
        $hours = self::evidenceHours();
        $stmt = $this->conn->prepare(
            "UPDATE disputes SET status = ?, evidence_due_at = DATE_ADD(NOW(), INTERVAL ? HOUR),
             seller_no_response = CASE WHEN ? = 1 THEN 1 ELSE seller_no_response END
             WHERE id = ?"
        );
        $status = self::STATUS_EVIDENCE_REVIEW;
        $flag = $sellerNoResponse ? 1 : 0;
        $stmt->bind_param('siii', $status, $hours, $flag, $disputeId);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $dispute
     * @return array{ok: bool, reason: string}
     */
    public function canAdminJudge(array $dispute, bool $force = false): array {
        if (($dispute['status'] ?? '') === self::STATUS_RESOLVED) {
            return ['ok' => false, 'reason' => 'ข้อพิพาทปิดแล้ว'];
        }

        $status = (string)($dispute['status'] ?? '');
        if ($status === self::STATUS_SELLER_RESPONSE && !$force) {
            $due = $dispute['seller_response_due_at'] ?? null;
            $dueText = $due ? date('d/m/Y H:i', strtotime($due)) : '-';
            return ['ok' => false, 'reason' => "ยังอยู่ในช่วงให้ผู้ขายชี้แจง (ถึง {$dueText})"];
        }

        if ($status === self::STATUS_SETTLEMENT_PENDING && !$force) {
            return ['ok' => false, 'reason' => 'มีข้อเสนอตกลงกันรอการตอบรับ — รอคู่กรณีตอบก่อน หรือใช้บังคับตัดสิน'];
        }

        if ($status === self::STATUS_EVIDENCE_REVIEW && !$force) {
            $due = $dispute['evidence_due_at'] ?? null;
            if ($due && strtotime($due) > time()) {
                $dueText = date('d/m/Y H:i', strtotime($due));
                return ['ok' => false, 'reason' => "ยังอยู่ในช่วงแนบหลักฐานเพิ่ม (ถึง {$dueText})"];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * @param array<string, mixed> $dispute
     * @return array<string, mixed>
     */
    public function getPhaseInfo(array $dispute): array {
        $status = (string)($dispute['status'] ?? '');
        $now = time();

        if ($status === self::STATUS_SELLER_RESPONSE) {
            $due = $dispute['seller_response_due_at'] ?? null;
            return [
                'phase' => 'seller_response',
                'label' => 'รอผู้ขายชี้แจง',
                'description' => 'ผู้ขายมีเวลาชี้แจงและแนบหลักฐานการส่งสินค้า',
                'deadline' => $due,
                'deadline_label' => $due ? date('d/m/Y H:i', strtotime($due)) : null,
                'overdue' => $due && strtotime($due) < $now,
                'step' => 1,
            ];
        }

        if ($status === self::STATUS_SETTLEMENT_PENDING) {
            $offer = $this->getActiveSettlement((int)$dispute['id']);
            return [
                'phase' => 'settlement',
                'label' => 'รอตอบรับข้อเสนอตกลง',
                'description' => 'มีข้อเสนอแบ่งเงินรออีกฝ่ายยอมรับหรือปฏิเสธ',
                'deadline' => null,
                'deadline_label' => null,
                'overdue' => false,
                'step' => 3,
                'settlement' => $offer,
            ];
        }

        if ($status === self::STATUS_EVIDENCE_REVIEW || $status === self::STATUS_PENDING) {
            $due = $dispute['evidence_due_at'] ?? null;
            $sellerNote = !empty($dispute['seller_no_response'])
                ? 'ผู้ขายไม่ได้ชี้แจงภายในเวลา'
                : (!empty($dispute['seller_responded_at']) ? 'ผู้ขายชี้แจงแล้ว' : '');
            return [
                'phase' => 'evidence_review',
                'label' => 'ช่วงหลักฐาน / ตกลงกัน',
                'description' => 'ทั้งสองฝ่ายแนบหลักฐานเพิ่มหรือเสนอข้อตกลง — แอดมินตัดสินหลังครบกำหนด',
                'deadline' => $due,
                'deadline_label' => $due ? date('d/m/Y H:i', strtotime($due)) : null,
                'overdue' => $due && strtotime($due) < $now,
                'step' => 2,
                'seller_note' => $sellerNote,
            ];
        }

        return [
            'phase' => 'resolved',
            'label' => 'ปิดเคสแล้ว',
            'description' => '',
            'deadline' => null,
            'deadline_label' => null,
            'overdue' => false,
            'step' => 4,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    public function submitSellerResponse(int $disputeId, string $sellerUsername, string $responseText): array {
        $dispute = $this->syncAndGet($disputeId);
        $order = (new Order($this->conn))->findById((int)$dispute['order_id']);
        if (!$order || $order['seller_name'] !== $sellerUsername) {
            return ['status' => 'error', 'message' => 'เฉพาะผู้ขายเท่านั้นที่ชี้แจงได้'];
        }
        if (($dispute['status'] ?? '') !== self::STATUS_SELLER_RESPONSE) {
            return ['status' => 'error', 'message' => 'ไม่อยู่ในช่วงให้ผู้ขายชี้แจงแล้ว'];
        }
        if (!empty($dispute['seller_responded_at'])) {
            return ['status' => 'error', 'message' => 'คุณชี้แจงไปแล้ว — แนบหลักฐานเพิ่มในส่วนด้านล่างได้'];
        }

        $responseText = trim($responseText);
        if ($responseText === '') {
            return ['status' => 'error', 'message' => 'กรุณากรอกคำชี้แจง'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE disputes SET seller_response = ?, seller_responded_at = NOW(), seller_no_response = 0 WHERE id = ?"
        );
        $stmt->bind_param('si', $responseText, $disputeId);
        $stmt->execute();

        $this->disputeModel->addComment($disputeId, $sellerUsername, $responseText, 'seller_statement');
        $this->enterEvidenceReview($disputeId, false);

        try {
            createNotification(
                $this->conn,
                $order['buyer_name'],
                "📋 ผู้ขายชี้แจงข้อพิพาทออเดอร์ #{$order['id']} แล้ว — ดูรายละเอียดและแนบหลักฐานเพิ่มได้",
                "dispute_detail.php?dispute_id={$disputeId}"
            );
        } catch (Throwable $e) {
            error_log('DisputeFlowService notify buyer after seller response: ' . $e->getMessage());
        }

        return ['status' => 'success', 'message' => 'ส่งคำชี้แจงแล้ว — ตอนนี้ทั้งสองฝ่ายสามารถแนบหลักฐานเพิ่มได้'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveSettlement(int $disputeId): ?array {
        if (!$this->hasSettlementsTable()) {
            return null;
        }
        $stmt = $this->conn->prepare(
            "SELECT * FROM dispute_settlements WHERE dispute_id = ? AND status = ? ORDER BY id DESC LIMIT 1"
        );
        $pending = self::SETTLEMENT_PENDING;
        $stmt->bind_param('is', $disputeId, $pending);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function proposeSettlement(
        int $disputeId,
        string $proposer,
        float $buyerAmount,
        float $sellerAmount,
        string $message = ''
    ): array {
        $dispute = $this->syncAndGet($disputeId);
        $order = (new Order($this->conn))->findById((int)$dispute['order_id']);
        if (!$order) {
            return ['status' => 'error', 'message' => 'ไม่พบออเดอร์'];
        }

        if ($proposer !== $order['buyer_name'] && $proposer !== $order['seller_name']) {
            return ['status' => 'error', 'message' => 'ไม่มีสิทธิ์เสนอข้อตกลง'];
        }

        $allowedStatuses = [self::STATUS_EVIDENCE_REVIEW, self::STATUS_SETTLEMENT_PENDING];
        if (!in_array($dispute['status'] ?? '', $allowedStatuses, true)) {
            return ['status' => 'error', 'message' => 'ยังไม่ถึงช่วงเสนอข้อตกลง'];
        }

        $total = (float)$order['amount'];
        if ($buyerAmount < 0 || $sellerAmount < 0 || abs(($buyerAmount + $sellerAmount) - $total) > 0.01) {
            return ['status' => 'error', 'message' => 'ยอดแบ่งต้องรวมเท่ากับ ฿' . number_format($total, 2)];
        }

        if ($this->getActiveSettlement($disputeId)) {
            return ['status' => 'error', 'message' => 'มีข้อเสนอที่รอตอบอยู่แล้ว — รออีกฝ่ายตอบก่อน'];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO dispute_settlements (dispute_id, proposer, buyer_amount, seller_amount, message, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $status = self::SETTLEMENT_PENDING;
        $stmt->bind_param('isddss', $disputeId, $proposer, $buyerAmount, $sellerAmount, $message, $status);
        $stmt->execute();

        $upd = $this->conn->prepare("UPDATE disputes SET status = ? WHERE id = ?");
        $ds = self::STATUS_SETTLEMENT_PENDING;
        $upd->bind_param('si', $ds, $disputeId);
        $upd->execute();

        $other = $proposer === $order['buyer_name'] ? $order['seller_name'] : $order['buyer_name'];
        createNotification(
            $this->conn,
            $other,
            "🤝 {$proposer} เสนอข้อตกลงข้อพิพาทออเดอร์ #{$order['id']} (คืนผู้ซื้อ ฿" . number_format($buyerAmount, 2) . " / ให้ผู้ขาย ฿" . number_format($sellerAmount, 2) . ')',
            "dispute_detail.php?dispute_id={$disputeId}"
        );

        return ['status' => 'success', 'message' => 'ส่งข้อเสนอตกลงแล้ว — รออีกฝ่ายตอบรับ'];
    }

    /**
     * @return array{status: string, message: string}
     */
    public function respondSettlement(
        int $disputeId,
        string $responder,
        string $action,
        ?float $counterBuyer = null,
        ?float $counterSeller = null,
        string $message = ''
    ): array {
        $dispute = $this->syncAndGet($disputeId);
        $order = (new Order($this->conn))->findById((int)$dispute['order_id']);
        if (!$order) {
            return ['status' => 'error', 'message' => 'ไม่พบออเดอร์'];
        }

        $offer = $this->getActiveSettlement($disputeId);
        if (!$offer) {
            return ['status' => 'error', 'message' => 'ไม่มีข้อเสนอที่รอตอบ'];
        }

        if ($responder === $offer['proposer']) {
            return ['status' => 'error', 'message' => 'ไม่สามารถตอบข้อเสนอของตัวเองได้'];
        }

        if ($responder !== $order['buyer_name'] && $responder !== $order['seller_name']) {
            return ['status' => 'error', 'message' => 'ไม่มีสิทธิ์'];
        }

        if ($action === 'accept') {
            return $this->executeSettlement($dispute, $order, $offer, $responder);
        }

        if ($action === 'reject') {
            $stmt = $this->conn->prepare(
                "UPDATE dispute_settlements SET status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?"
            );
            $rejected = self::SETTLEMENT_REJECTED;
            $stmt->bind_param('ssi', $rejected, $responder, $offer['id']);
            $stmt->execute();

            $this->enterEvidenceReview($disputeId, false);

            createNotification(
                $this->conn,
                (string)$offer['proposer'],
                "❌ {$responder} ปฏิเสธข้อเสนอตกลงข้อพิพาทออเดอร์ #{$order['id']}",
                "dispute_detail.php?dispute_id={$disputeId}"
            );

            return ['status' => 'success', 'message' => 'ปฏิเสธข้อเสนอแล้ว — แอดมินจะพิจารณาต่อ'];
        }

        if ($action === 'counter') {
            if ($counterBuyer === null || $counterSeller === null) {
                return ['status' => 'error', 'message' => 'กรุณาระบุยอด counter-offer'];
            }

            $stmt = $this->conn->prepare(
                "UPDATE dispute_settlements SET status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?"
            );
            $countered = self::SETTLEMENT_COUNTERED;
            $stmt->bind_param('ssi', $countered, $responder, $offer['id']);
            $stmt->execute();

            return $this->proposeSettlement($disputeId, $responder, $counterBuyer, $counterSeller, $message);
        }

        return ['status' => 'error', 'message' => 'การดำเนินการไม่ถูกต้อง'];
    }

    /**
     * @param array<string, mixed> $dispute
     * @param array<string, mixed> $order
     * @param array<string, mixed> $offer
     * @return array{status: string, message: string}
     */
    private function executeSettlement(array $dispute, array $order, array $offer, string $acceptedBy): array {
        $orderId = (int)$order['id'];
        $disputeId = (int)$dispute['id'];
        $buyerAmt = (float)$offer['buyer_amount'];
        $sellerAmt = (float)$offer['seller_amount'];

        $wallet = new WalletService($this->conn);
        if (!$wallet->splitDisputeResolution(
            $order['buyer_name'],
            $order['seller_name'],
            $orderId,
            $buyerAmt,
            $sellerAmt,
            $acceptedBy
        )) {
            return ['status' => 'error', 'message' => 'ดำเนินการแบ่งเงินไม่สำเร็จ'];
        }

        $orderModel = new Order($this->conn);
        $orderModel->changeStatus(
            $orderId,
            $order['status'],
            OrderStateMachine::COMPLETED,
            $acceptedBy,
            "ตกลงกันเอง: ผู้ซื้อ ฿{$buyerAmt} / ผู้ขาย ฿{$sellerAmt}",
            true
        );

        $note = 'ตกลงกันเองโดย ' . $offer['proposer'] . ' (ยอมรับโดย ' . $acceptedBy . ')';
        $this->disputeModel->resolve($disputeId, 'SPLIT', $acceptedBy, $buyerAmt, $sellerAmt, $note);

        $stmt = $this->conn->prepare(
            "UPDATE dispute_settlements SET status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?"
        );
        $accepted = self::SETTLEMENT_ACCEPTED;
        $stmt->bind_param('ssi', $accepted, $acceptedBy, $offer['id']);
        $stmt->execute();

        createNotification($this->conn, $order['buyer_name'], "✅ ข้อพิพาทออเดอร์ #{$orderId} ปิดแล้ว (ตกลงกันเอง)", 'dashboard.php?tab=orders');
        createNotification($this->conn, $order['seller_name'], "✅ ข้อพิพาทออเดอร์ #{$orderId} ปิดแล้ว (ตกลงกันเอง)", 'earnings.php');

        return ['status' => 'success', 'message' => 'ทั้งสองฝ่ายตกลงกันแล้ว — แบ่งเงินตามที่เสนอเรียบร้อย'];
    }

    private function hasSettlementsTable(): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $r = $this->conn->query("SHOW TABLES LIKE 'dispute_settlements'");
        $exists = $r && $r->num_rows > 0;
        return $exists;
    }
}
