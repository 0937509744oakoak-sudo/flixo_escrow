-- Encrypted phone values are longer than the original plaintext phone field.
ALTER TABLE users
    MODIFY COLUMN phone VARCHAR(255) NULL DEFAULT NULL;
