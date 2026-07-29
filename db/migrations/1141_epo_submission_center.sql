-- 1141: Asistované EPO podání a důkazní archiv.
--
-- `tax_submissions` zůstává neměnným XML snapshotem. Každé předání do EPO je
-- samostatný auditovaný pokus; krátkodobé URL EPO se z bezpečnostních důvodů
-- nikdy neukládá. Artefakty (XML/P7S/PDF) fyzicky žijí v existujícím DMS a zde
-- se pouze vážou na snapshot a pokus.

CREATE TABLE IF NOT EXISTS tax_submission_settings (
  supplier_id              INT UNSIGNED NOT NULL PRIMARY KEY,
  vat_root_folder_id       BIGINT UNSIGNED NULL,
  income_tax_root_folder_id BIGINT UNSIGNED NULL,
  updated_by               BIGINT UNSIGNED NULL,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tss_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tss_vat_folder FOREIGN KEY (vat_root_folder_id) REFERENCES document_folders(id) ON DELETE SET NULL,
  CONSTRAINT fk_tss_income_folder FOREIGN KEY (income_tax_root_folder_id) REFERENCES document_folders(id) ON DELETE SET NULL,
  CONSTRAINT fk_tss_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_submission_attempts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  tax_submission_id INT UNSIGNED NOT NULL,
  channel           ENUM('epo_assisted') NOT NULL DEFAULT 'epo_assisted',
  status            ENUM(
    'prepared','handoff_created','awaiting_confirmation','confirmed',
    'failed','expired','cancelled'
  ) NOT NULL DEFAULT 'prepared',
  idempotency_key   CHAR(32) NOT NULL,
  request_sha256    CHAR(64) NOT NULL,
  response_http_status SMALLINT UNSIGNED NULL,
  error_code        VARCHAR(64) NULL,
  error_message     VARCHAR(500) NULL,
  requested_by      BIGINT UNSIGNED NULL,
  requested_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handoff_expires_at TIMESTAMP NULL,
  confirmed_at      TIMESTAMP NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tsa_idempotency (supplier_id, idempotency_key),
  KEY idx_tsa_submission (supplier_id, tax_submission_id, requested_at),
  KEY idx_tsa_active (supplier_id, tax_submission_id, status, handoff_expires_at),
  CONSTRAINT fk_tsa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsa_submission FOREIGN KEY (tax_submission_id) REFERENCES tax_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tsa_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_submission_artifacts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  tax_submission_id INT UNSIGNED NOT NULL,
  attempt_id        BIGINT UNSIGNED NULL,
  document_id       BIGINT UNSIGNED NOT NULL,
  artifact_kind     ENUM('source_xml','epo_xml','confirmation_p7s','receipt_pdf','other') NOT NULL,
  sha256            CHAR(64) NOT NULL,
  verification_status ENUM('not_applicable','pending','valid','warning','invalid') NOT NULL DEFAULT 'pending',
  verification_json JSON NULL,
  uploaded_by       BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tart_document (document_id),
  UNIQUE KEY uq_tart_snapshot_kind_sha (tax_submission_id, artifact_kind, sha256),
  KEY idx_tart_submission (supplier_id, tax_submission_id, created_at),
  KEY idx_tart_attempt (attempt_id),
  CONSTRAINT fk_tart_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tart_submission FOREIGN KEY (tax_submission_id) REFERENCES tax_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tart_attempt FOREIGN KEY (attempt_id) REFERENCES tax_submission_attempts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tart_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_tart_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
