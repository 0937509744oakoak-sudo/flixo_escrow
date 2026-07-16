<?php
/**
 * Follower feed — แจ้งเตือนผู้ติดตามร้านผ่านระบบ Notification (in-app + email + push)
 * เมื่อร้านลงประกาศใหม่ที่แสดงต่อสาธารณะ
 */

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../models/User.php';

class FollowerFeedService
{
    private const MAX_FOLLOWERS_PER_EVENT = 500;

    /**
     * แจ้งผู้ติดตามทุกคนของ $seller ว่ามีประกาศใหม่
     * เรียกเฉพาะเมื่อสินค้าแสดงต่อสาธารณะแล้ว (ผ่าน/ข้ามการตรวจรูป)
     *
     * @return int จำนวนผู้ติดตามที่ถูกแจ้ง
     */
    public static function notifyNewListing(mysqli $conn, string $seller, int $productId, string $productName, float $price): int
    {
        if ($seller === '' || $productId <= 0) {
            return 0;
        }

        $stmt = $conn->prepare(
            "SELECT f.follower_name
             FROM user_follows f
             JOIN users u ON u.username = f.follower_name
             WHERE f.target_name = ? AND u.status != 'banned'
             ORDER BY f.id
             LIMIT " . self::MAX_FOLLOWERS_PER_EVENT
        );
        if (!$stmt) {
            error_log('FollowerFeedService: prepare failed — ' . $conn->error);
            return 0;
        }
        $stmt->bind_param('s', $seller);
        $stmt->execute();
        $result = $stmt->get_result();

        $followers = [];
        while ($row = $result->fetch_assoc()) {
            $followers[] = $row['follower_name'];
        }
        if ($followers === []) {
            return 0;
        }

        $sellerDisplay = (new User($conn))->getDisplayName($seller);
        $shortName = mb_strlen($productName) > 60 ? mb_substr($productName, 0, 60) . '…' : $productName;
        $message = $sellerDisplay . ' ลงประกาศใหม่: ' . $shortName . ' — ฿' . number_format($price, 0);
        $url = 'product.php?id=' . $productId;

        $notified = 0;
        foreach ($followers as $follower) {
            try {
                $id = NotificationService::notifyUser($conn, $follower, [
                    'type' => 'follow',
                    'title' => 'ร้านที่คุณติดตาม',
                    'icon' => '🛍️',
                    'message' => $message,
                    'url' => $url,
                ]);
                if ($id > 0) {
                    $notified++;
                }
            } catch (Throwable $e) {
                error_log('FollowerFeedService: notify ' . $follower . ' failed — ' . $e->getMessage());
            }
        }

        return $notified;
    }
}
