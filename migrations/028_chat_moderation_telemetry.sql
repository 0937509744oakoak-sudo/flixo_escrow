-- Migration 028: Cascaded chat moderation telemetry (research metrics)
-- Run: php scripts/run_migration.php 028_chat_moderation_telemetry.sql
-- Privacy: stores message_hash only — no plaintext message body.

CREATE TABLE IF NOT EXISTS chat_moderation_telemetry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NULL COMMENT 'FK-ish to messages.id when available',
    message_hash CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 of message text',
    content_type VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT 'text|image',
    tier_reached VARCHAR(32) NOT NULL COMMENT 'TIER_1_REGEX|TIER_2_LLM|TIER_3_FALLBACK|TIER_SKIPPED',
    provider_used VARCHAR(32) NOT NULL DEFAULT 'none' COMMENT 'none|groq|openrouter|gemini|heuristic|cloud_vision|off',
    regex_score DECIMAL(6,2) NOT NULL DEFAULT 0,
    llm_confidence DECIMAL(6,2) NULL COMMENT 'AI risk_score 0-100 when Tier 2 ran',
    final_verdict VARCHAR(16) NOT NULL DEFAULT 'ALLOW' COMMENT 'ALLOW|FLAG|BLOCK',
    latency_tier1_ms DECIMAL(12,3) NOT NULL DEFAULT 0,
    latency_tier2_ms DECIMAL(12,3) NULL,
    total_latency_ms DECIMAL(12,3) NOT NULL DEFAULT 0,
    estimated_tokens INT UNSIGNED NULL,
    estimated_cost_usd DECIMAL(12,8) NOT NULL DEFAULT 0,
    escalated TINYINT(1) NOT NULL DEFAULT 0,
    matched_patterns JSON NULL,
    provider_errors JSON NULL,
    text_length INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cmt_created (created_at),
    INDEX idx_cmt_tier (tier_reached, created_at),
    INDEX idx_cmt_provider (provider_used, created_at),
    INDEX idx_cmt_verdict (final_verdict, created_at),
    INDEX idx_cmt_message (message_id),
    INDEX idx_cmt_hash (message_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
