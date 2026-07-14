<?php
// models/Review.php

require_once __DIR__ . '/User.php';

class Review {
    private $conn;

    public function __construct(mysqli $db_connection) {
        $this->conn = $db_connection;
    }

    public static function starsLabel(int $rating): string {
        $rating = max(1, min(5, $rating));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }

    public function existsForOrder(int $orderId): bool {
        if ($orderId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare('SELECT id FROM reviews WHERE order_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    public function getForOrder(int $orderId): ?array {
        if ($orderId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepare('SELECT * FROM reviews WHERE order_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, true> order_id => true
     */
    public function getReviewedOrderIds(string $reviewer, array $orderIds): array {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($reviewer === '' || $orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = "SELECT order_id FROM reviews WHERE reviewer_name = ? AND order_id IN ($placeholders)";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $types = 's' . str_repeat('i', count($orderIds));
        $params = array_merge([$reviewer], $orderIds);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['order_id']] = true;
        }
        return $map;
    }

    public function create(string $reviewer, string $target, int $rating, string $comment, int $orderId): bool {
        if ($this->existsForOrder($orderId)) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO reviews (reviewer_name, target_name, rating, comment, order_id) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssisi', $reviewer, $target, $rating, $comment, $orderId);
        return $stmt->execute();
    }

    public function countByTarget(string $username): int {
        $stmt = $this->conn->prepare('SELECT COUNT(*) AS cnt FROM reviews WHERE target_name = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getByTarget(string $username, int $limit = 10, int $offset = 0): array {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $stmt = $this->conn->prepare(
            'SELECT * FROM reviews WHERE target_name = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sii', $username, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $reviewers = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
            $reviewers[] = $row['reviewer_name'];
        }

        if ($rows === []) {
            return [];
        }

        $userModel = new User($this->conn);
        $displayMap = $userModel->getDisplayNameMap($reviewers);

        foreach ($rows as &$row) {
            $row['reviewer_display_name'] = $displayMap[$row['reviewer_name']] ?? $row['reviewer_name'];
        }
        unset($row);

        return $rows;
    }
}
