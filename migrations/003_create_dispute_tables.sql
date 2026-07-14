-- Migration: Create Dispute System Tables
-- ตารางสำหรับจัดการข้อพิพาทและหลักฐาน

CREATE TABLE IF NOT EXISTS disputes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL UNIQUE,
    initiator VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    description LONGTEXT,
    status VARCHAR(20) DEFAULT 'PENDING' COMMENT 'PENDING, EVIDENCE_REVIEW, RESOLVED',
    resolution VARCHAR(20) COMMENT 'REFUND_BUYER, PAYOUT_SELLER, SPLIT',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX (status),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตารางเก็บหลักฐาน (รูปภาพ/ไฟล์เอกสาร)
CREATE TABLE IF NOT EXISTS dispute_evidences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dispute_id INT NOT NULL,
    uploader VARCHAR(64) NOT NULL,
    description TEXT,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) COMMENT 'image, document, video, other',
    file_size INT COMMENT 'bytes',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
    INDEX (dispute_id),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตารางสำหรับความเห็นจากทั้งสองฝ่าย/แอดมิน
CREATE TABLE IF NOT EXISTS dispute_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dispute_id INT NOT NULL,
    commenter VARCHAR(64) NOT NULL,
    comment LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
    INDEX (dispute_id),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
