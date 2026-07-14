<?php

// models/Consent.php



require_once __DIR__ . '/../includes/legal.php';



class Consent {

    private $conn;



    public function __construct($db_connection) {

        $this->conn = $db_connection;

    }



    public function record(int $userId, string $consentType, string $version): bool {

        $ip = getClientIp();

        $ua = getClientUserAgent();

        $sql = "INSERT INTO user_consents (user_id, consent_type, version, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {

            error_log('Consent prepare failed: ' . $this->conn->error);

            return false;

        }

        $stmt->bind_param('issss', $userId, $consentType, $version, $ip, $ua);

        return $stmt->execute();

    }



    public function hasConsent(int $userId, string $consentType, string $version): bool {

        $sql = "SELECT id FROM user_consents WHERE user_id = ? AND consent_type = ? AND version = ? AND withdrawn_at IS NULL LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {

            return false;

        }

        $stmt->bind_param('iss', $userId, $consentType, $version);

        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;

    }



    public function hasCurrentPolicyConsent(int $userId): bool {

        return $this->hasConsent($userId, 'terms_of_service', TERMS_VERSION)

            && $this->hasConsent($userId, 'privacy_policy', PRIVACY_VERSION);

    }



    public function withdraw(int $userId, string $consentType, string $reason = ''): bool {

        $ip = getClientIp();

        $sql = "UPDATE user_consents SET withdrawn_at = NOW(), withdrawn_ip = ?, withdrawn_reason = ?

                WHERE user_id = ? AND consent_type = ? AND withdrawn_at IS NULL

                ORDER BY consented_at DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {

            return false;

        }

        $stmt->bind_param('ssis', $ip, $reason, $userId, $consentType);

        return $stmt->execute() && $stmt->affected_rows > 0;

    }



    public function getByUser(int $userId): array {

        $sql = "SELECT consent_type, version, ip_address, consented_at, withdrawn_at FROM user_consents WHERE user_id = ? ORDER BY consented_at DESC";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {

            return [];

        }

        $stmt->bind_param('i', $userId);

        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    }

}

