<?php
// models/User.php

require_once __DIR__ . '/../includes/PiiEncryption.php';

class User {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    private function prepareStatement($sql) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('DB prepare failed: ' . $this->conn->error . ' SQL: ' . $sql);
        }
        return $stmt;
    }

    private function decryptUserRow(?array $row): ?array {
        if (!$row) {
            return null;
        }
        if (!empty($row['phone'])) {
            $row['phone'] = PiiEncryption::decrypt($row['phone']);
        }
        return $row;
    }

    public static function resolveDisplayName(?string $realName, string $username): string {
        $realName = trim((string) $realName);
        return $realName !== '' ? $realName : $username;
    }

    public function findByUsername($username) {
        $sql = "SELECT * FROM users WHERE username = ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $this->decryptUserRow($stmt->get_result()->fetch_assoc());
    }

    public function findById(int $userId): ?array {
        if ($userId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM users WHERE id = ? LIMIT 1';
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $this->decryptUserRow($stmt->get_result()->fetch_assoc());
    }

    public function findByEmail(string $email): ?array {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $sql = "SELECT * FROM users WHERE email = ? LIMIT 1";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        return $this->decryptUserRow($stmt->get_result()->fetch_assoc());
    }

    public function updatePassword(int $userId, string $passwordHash): bool {
        if ($userId <= 0 || $passwordHash === '') {
            return false;
        }
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $passwordHash, $userId);
        return $stmt->execute();
    }

    public function findByLogin(string $login): ?array {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        if (strpos($login, '@') !== false) {
            $sql = "SELECT * FROM users WHERE email = ? LIMIT 1";
        } else {
            $sql = "SELECT * FROM users WHERE username = ? LIMIT 1";
        }

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $login);
        $stmt->execute();
        return $this->decryptUserRow($stmt->get_result()->fetch_assoc());
    }

    public function getDisplayName(string $username): string {
        $row = $this->findByUsername($username);
        if (!$row) {
            return $username;
        }
        return self::resolveDisplayName($row['real_name'] ?? '', $username);
    }

    /**
     * @param list<string> $usernames
     * @return array<string, string>
     */
    public function getDisplayNameMap(array $usernames): array {
        $usernames = array_values(array_unique(array_filter(array_map('strval', $usernames))));
        if ($usernames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($usernames), '?'));
        $sql = "SELECT username, real_name FROM users WHERE username IN ($placeholders)";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return [];
        }

        $types = str_repeat('s', count($usernames));
        $stmt->bind_param($types, ...$usernames);
        $stmt->execute();
        $result = $stmt->get_result();

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['username']] = self::resolveDisplayName($row['real_name'] ?? '', $row['username']);
        }

        foreach ($usernames as $username) {
            if (!isset($map[$username])) {
                $map[$username] = $username;
            }
        }

        return $map;
    }

    public function updateRealName(int $userId, string $realName): bool {
        $realName = trim($realName);
        if ($userId <= 0 || $realName === '' || mb_strlen($realName) > 100) {
            return false;
        }

        $sql = "UPDATE users SET real_name = ? WHERE id = ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $realName, $userId);
        return $stmt->execute();
    }

    public function checkExists($username, $email) {
        $sql = "SELECT id FROM users WHERE username = ? OR email = ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function create($username, $password, $email, $real_name, $phone) {
        $encryptedPhone = $phone !== '' ? PiiEncryption::encrypt($phone) : '';
        $sql = "INSERT INTO users (username, password, email, real_name, phone, role, kyc_status) VALUES (?, ?, ?, ?, ?, 'user', 'none')";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            // Fallback if kyc_status column missing on older DBs
            $sql = "INSERT INTO users (username, password, email, real_name, phone, role) VALUES (?, ?, ?, ?, ?, 'user')";
            $stmt = $this->prepareStatement($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("sssss", $username, $password, $email, $real_name, $encryptedPhone);
            if (!$stmt->execute()) {
                return false;
            }
            return (int)$this->conn->insert_id;
        }
        $stmt->bind_param("sssss", $username, $password, $email, $real_name, $encryptedPhone);
        if (!$stmt->execute()) {
            return false;
        }
        return (int)$this->conn->insert_id;
    }

    public function updateKyc($username, $imageName, ?string $selfieName = null) {
        if ($selfieName !== null) {
            $sql = "UPDATE users SET id_card_image = ?, selfie_image = ?, kyc_status = 'pending', ekyc_provider = 'manual' WHERE username = ?";
            $stmt = $this->prepareStatement($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sss', $imageName, $selfieName, $username);
        } else {
            $sql = "UPDATE users SET id_card_image = ?, kyc_status = 'pending', ekyc_provider = 'manual' WHERE username = ?";
            $stmt = $this->prepareStatement($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $imageName, $username);
        }
        return $stmt->execute();
    }

    public function isVerified(string $username): bool {
        $stmt = $this->prepareStatement("SELECT is_verified FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return !empty($row['is_verified']);
    }
    
  
    public function getChatCount($username) {
        $sql = "SELECT COUNT(DISTINCT IF(sender_name=?, receiver_name, sender_name)) as count FROM messages WHERE sender_name=? OR receiver_name=?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("sss", $username, $username, $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    }

    public function searchUsers($keyword, $exclude_user) {
        $search = '%' . $keyword . '%';
        $sql = "SELECT username, (SELECT AVG(rating) FROM reviews WHERE target_name = users.username) as avg FROM users WHERE username LIKE ? AND username != ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $search, $exclude_user);
        $stmt->execute();
        return $stmt->get_result();
    }

    // ---------- Community: follow system ----------

    public function follow(string $follower, string $target): bool {
        if ($follower === '' || $target === '' || $follower === $target) {
            return false;
        }
        $stmt = $this->prepareStatement('INSERT IGNORE INTO user_follows (follower_name, target_name) VALUES (?, ?)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $follower, $target);
        return $stmt->execute();
    }

    public function unfollow(string $follower, string $target): bool {
        $stmt = $this->prepareStatement('DELETE FROM user_follows WHERE follower_name = ? AND target_name = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $follower, $target);
        return $stmt->execute();
    }

    public function isFollowing(string $follower, string $target): bool {
        $stmt = $this->prepareStatement('SELECT id FROM user_follows WHERE follower_name = ? AND target_name = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $follower, $target);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    public function countFollowers(string $username): int {
        $stmt = $this->prepareStatement('SELECT COUNT(*) AS cnt FROM user_follows WHERE target_name = ?');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    }

    /**
     * Aggregated stats for the seller card on the product page.
     * @return array{sold_count:int, joined_year:?int, avg_response_minutes:?float, response_rate:?float}
     */
    public function getSellerProfileStats(string $username): array {
        $stats = ['sold_count' => 0, 'joined_year' => null, 'avg_response_minutes' => null, 'response_rate' => null];

        $stmt = $this->prepareStatement("SELECT COUNT(*) AS c FROM orders WHERE seller_name = ? AND status = 'COMPLETED'");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stats['sold_count'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        }

        $stmt = $this->prepareStatement("SELECT YEAR(created_at) AS y FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && !empty($row['y'])) {
                $stats['joined_year'] = (int) $row['y'];
            }
        }

        // Reply behaviour sampled from recent incoming messages:
        //   avg_response_minutes — average time to first reply
        //   response_rate        — % of messages answered within 24 h
        $sql = "SELECT AVG(t.diff_min) AS avg_min,
                       AVG(CASE WHEN t.diff_min IS NOT NULL AND t.diff_min <= 1440 THEN 1 ELSE 0 END) * 100 AS rate,
                       COUNT(*) AS total
                FROM (
                    SELECT TIMESTAMPDIFF(MINUTE, m.created_at,
                        (SELECT MIN(r.created_at) FROM messages r
                         WHERE r.sender_name = ? AND r.receiver_name = m.sender_name AND r.created_at >= m.created_at)
                    ) AS diff_min
                    FROM messages m
                    WHERE m.receiver_name = ? AND m.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ORDER BY m.id DESC
                    LIMIT 200
                ) t";
        $stmt = $this->prepareStatement($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $username, $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && (int) $row['total'] > 0) {
                if ($row['avg_min'] !== null) {
                    $stats['avg_response_minutes'] = (float) $row['avg_min'];
                }
                $stats['response_rate'] = (float) $row['rate'];
            }
        }

        return $stats;
    }

    public function getReviewStats($username) {
        $sql = "SELECT AVG(rating) as avg_score, COUNT(*) as total_reviews FROM reviews WHERE target_name = ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return ['avg_score' => 0, 'total_reviews' => 0];
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getRecentReviews($username, $limit = 10) {
        $sql = "SELECT * FROM reviews WHERE target_name = ? ORDER BY created_at DESC LIMIT ?";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("si", $username, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getChatHistory($username) {
        $sql = "SELECT * FROM messages WHERE sender_name = ? OR receiver_name = ? ORDER BY created_at DESC";
        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        return $stmt->get_result();
    }
}
?>