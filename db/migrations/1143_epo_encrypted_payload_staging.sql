-- 1143: Bezpečné interní uložení testovacího CMS a potvrzení EPO.
--
-- Sloupce záměrně nejsou součástí veřejných repository selectů. Umožňují
-- uchovat testovací podpis bez možnosti stažení a neztratit unikátní
-- potvrzení, pokud po přijetí podání selže DMS.

ALTER TABLE tax_submission_attempts
  ADD COLUMN IF NOT EXISTS test_signed_ciphertext LONGTEXT NULL AFTER test_messages_json,
  ADD COLUMN IF NOT EXISTS submitted_signed_ciphertext LONGTEXT NULL AFTER state_password_ciphertext,
  ADD COLUMN IF NOT EXISTS confirmation_ciphertext LONGTEXT NULL AFTER submitted_signed_ciphertext,
  ADD COLUMN IF NOT EXISTS last_response_ciphertext LONGTEXT NULL AFTER confirmation_ciphertext;
