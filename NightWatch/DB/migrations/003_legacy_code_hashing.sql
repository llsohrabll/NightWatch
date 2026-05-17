-- NightWatch migration 003: convert legacy plaintext 6-digit email/reset codes to SHA-256.
-- This is optional because PHP accepts both old plaintext and new hashed values during transition.

UPDATE users
SET email_verification_code = SHA2(email_verification_code, 256)
WHERE email_verification_code REGEXP '^[0-9]{6}$';

UPDATE users
SET reset_code = SHA2(reset_code, 256)
WHERE reset_code REGEXP '^[0-9]{6}$';

UPDATE users
SET email_change_code = SHA2(email_change_code, 256)
WHERE email_change_code REGEXP '^[0-9]{6}$';
