-- 1142: Přímé EPO API se ZAREP, osobní trezor certifikátů a audit lifecycle.
--
-- Soukromý klíč i heslo PFX jsou v DB pouze v aplikačně šifrované podobě.
-- Certifikát vlastní uživatel (fyzická osoba); vazba níže určuje firmy, za
-- které jej smí v MyÚčtu použít.

CREATE TABLE IF NOT EXISTS epo_signing_credentials (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id         BIGINT UNSIGNED NOT NULL,
  label                 VARCHAR(120) NOT NULL,
  pfx_ciphertext        MEDIUMTEXT NOT NULL,
  passphrase_ciphertext TEXT NOT NULL,
  fingerprint_sha256    CHAR(64) NOT NULL,
  subject_dn            VARCHAR(1000) NOT NULL,
  issuer_dn             VARCHAR(1000) NOT NULL,
  serial_hex            VARCHAR(128) NULL,
  valid_from            DATETIME NOT NULL,
  valid_to              DATETIME NOT NULL,
  ik_mpsv_present       TINYINT(1) NOT NULL DEFAULT 0,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL,
  UNIQUE KEY uq_eposc_owner_fingerprint (owner_user_id, fingerprint_sha256),
  KEY idx_eposc_owner_active (owner_user_id, deleted_at, valid_to),
  CONSTRAINT fk_eposc_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epo_signing_credential_suppliers (
  credential_id BIGINT UNSIGNED NOT NULL,
  supplier_id   INT UNSIGNED NOT NULL,
  enabled_by    BIGINT UNSIGNED NULL,
  enabled_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (credential_id, supplier_id),
  KEY idx_eposcs_supplier (supplier_id, credential_id),
  CONSTRAINT fk_eposcs_credential FOREIGN KEY (credential_id) REFERENCES epo_signing_credentials(id) ON DELETE CASCADE,
  CONSTRAINT fk_eposcs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_eposcs_user FOREIGN KEY (enabled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tax_submission_attempts
  MODIFY COLUMN channel ENUM('epo_assisted','epo_direct') NOT NULL DEFAULT 'epo_assisted',
  MODIFY COLUMN status ENUM(
    'prepared','handoff_created','awaiting_confirmation',
    'testing','test_passed','test_failed','submitting','processing',
    'submitted','confirmed','rejected','uncertain',
    'failed','expired','cancelled'
  ) NOT NULL DEFAULT 'prepared',
  ADD COLUMN IF NOT EXISTS signing_credential_id BIGINT UNSIGNED NULL AFTER request_sha256,
  ADD COLUMN IF NOT EXISTS signing_fingerprint CHAR(64) NULL AFTER signing_credential_id,
  ADD COLUMN IF NOT EXISTS test_passed TINYINT(1) NULL AFTER response_http_status,
  ADD COLUMN IF NOT EXISTS test_messages_json JSON NULL AFTER test_passed,
  ADD COLUMN IF NOT EXISTS test_signed_ciphertext LONGTEXT NULL AFTER test_messages_json,
  ADD COLUMN IF NOT EXISTS tested_at TIMESTAMP NULL AFTER test_signed_ciphertext,
  ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL AFTER handoff_expires_at,
  ADD COLUMN IF NOT EXISTS remote_submission_ref VARCHAR(100) NULL AFTER submitted_at,
  ADD COLUMN IF NOT EXISTS state_password_ciphertext TEXT NULL AFTER remote_submission_ref,
  ADD COLUMN IF NOT EXISTS submitted_signed_ciphertext LONGTEXT NULL AFTER state_password_ciphertext,
  ADD COLUMN IF NOT EXISTS confirmation_ciphertext LONGTEXT NULL AFTER submitted_signed_ciphertext,
  ADD COLUMN IF NOT EXISTS last_response_ciphertext LONGTEXT NULL AFTER confirmation_ciphertext,
  ADD COLUMN IF NOT EXISTS offline_transfer_id VARCHAR(100) NULL AFTER last_response_ciphertext,
  ADD COLUMN IF NOT EXISTS offline_password_ciphertext TEXT NULL AFTER offline_transfer_id,
  ADD COLUMN IF NOT EXISTS last_status_json JSON NULL AFTER offline_password_ciphertext,
  ADD COLUMN IF NOT EXISTS last_status_at TIMESTAMP NULL AFTER last_status_json,
  ADD KEY IF NOT EXISTS idx_tsa_direct_status (channel, status, requested_at);

ALTER TABLE tax_submission_attempts
  DROP FOREIGN KEY IF EXISTS fk_tsa_signing_credential;

ALTER TABLE tax_submission_attempts
  ADD CONSTRAINT fk_tsa_signing_credential
  FOREIGN KEY (signing_credential_id) REFERENCES epo_signing_credentials(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS tax_submission_status_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  tax_submission_id INT UNSIGNED NOT NULL,
  attempt_id        BIGINT UNSIGNED NOT NULL,
  event_type        VARCHAR(64) NOT NULL,
  status            VARCHAR(64) NOT NULL,
  http_status       SMALLINT UNSIGNED NULL,
  details_json      JSON NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tsse_attempt (attempt_id, created_at),
  KEY idx_tsse_submission (supplier_id, tax_submission_id, created_at),
  CONSTRAINT fk_tsse_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsse_submission FOREIGN KEY (tax_submission_id) REFERENCES tax_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsse_attempt FOREIGN KEY (attempt_id) REFERENCES tax_submission_attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsse_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tax_submission_artifacts
  MODIFY COLUMN artifact_kind ENUM(
    'source_xml','epo_xml','signed_submission_p7s','confirmation_p7s',
    'epo_error_xml','epo_status_xml','receipt_pdf','other'
  ) NOT NULL;
