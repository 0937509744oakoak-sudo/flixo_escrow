-- Add read tracking for chat messages (support inbox unread badge)
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS is_read TINYINT(1) NOT NULL DEFAULT 0;
