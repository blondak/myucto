-- 1168: Sdílení osobního šifrovaného trezoru mezi EPO, PDF a S/MIME podpisy.

DELETE FROM migrations
WHERE filename = '1200_shared_personal_certificate_vault.sql';

ALTER TABLE signing_credentials
  MODIFY COLUMN certificate_path VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS vault_credential_id BIGINT UNSIGNED NULL AFTER profile_id,
  ADD KEY IF NOT EXISTS idx_signing_credentials_vault (vault_credential_id);

ALTER TABLE signing_credentials
  DROP FOREIGN KEY IF EXISTS fk_signing_credential_vault;

ALTER TABLE signing_credentials
  ADD CONSTRAINT fk_signing_credential_vault
  FOREIGN KEY (vault_credential_id) REFERENCES epo_signing_credentials(id) ON DELETE SET NULL;
