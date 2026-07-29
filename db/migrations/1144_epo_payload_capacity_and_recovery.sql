-- 1144: Kapacita a obnova šifrovaných EPO payloadů.
--
-- Aplikační šifrování binárních CMS používá base64 před i uvnitř šifrovacího
-- obalu. LONGTEXT proto ponechává bezpečnou rezervu i pro rozsáhlá podání.

ALTER TABLE tax_submission_attempts
  ADD COLUMN IF NOT EXISTS submitted_signed_ciphertext LONGTEXT NULL AFTER state_password_ciphertext,
  ADD COLUMN IF NOT EXISTS last_response_ciphertext LONGTEXT NULL AFTER confirmation_ciphertext,
  MODIFY COLUMN test_signed_ciphertext LONGTEXT NULL,
  MODIFY COLUMN confirmation_ciphertext LONGTEXT NULL;
