-- Admin payout proof for manual bank withdrawals
ALTER TABLE withdrawal_requests
    ADD COLUMN payout_slip VARCHAR(255) NULL AFTER admin_note;
