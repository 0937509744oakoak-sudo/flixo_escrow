-- Fix: kyc_status default was 'pending', so new users appeared as "already submitted"
-- Use 'none' = not started, 'in_progress' = started (e.g. Sumsub SDK), 'pending' = awaiting review

ALTER TABLE users
    MODIFY COLUMN kyc_status VARCHAR(20) NOT NULL DEFAULT 'none';

-- Reset false "pending" users who never uploaded docs / never got a Sumsub applicant
UPDATE users
SET kyc_status = 'none'
WHERE kyc_status = 'pending'
  AND (id_card_image IS NULL OR id_card_image = '')
  AND (selfie_image IS NULL OR selfie_image = '')
  AND (ekyc_applicant_id IS NULL OR ekyc_applicant_id = '')
  AND (is_verified IS NULL OR is_verified = 0);
