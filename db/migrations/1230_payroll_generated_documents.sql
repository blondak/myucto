-- MyÚčto.cz — MZ-16: neměnné mzdové dokumenty a krátkodobé download granty.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_generated_documents (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NULL,
  employee_scope_id     BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(employee_id, 0)) STORED,
  document_kind         VARCHAR(48) NOT NULL,
  document_revision_no  INT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_document_id BIGINT UNSIGNED NULL,
  revision_snapshot_hash CHAR(64) NOT NULL,
  source_snapshot_hash  CHAR(64) NOT NULL,
  template_version      VARCHAR(64) NOT NULL,
  renderer_version      VARCHAR(64) NOT NULL,
  file_sha256           CHAR(64) NOT NULL,
  size_bytes            BIGINT UNSIGNED NOT NULL,
  mime_type             VARCHAR(96) NOT NULL,
  storage_key           CHAR(64) NOT NULL,
  suggested_filename    VARCHAR(160) NOT NULL,
  manifest_json         LONGTEXT NULL CHECK (manifest_json IS NULL OR JSON_VALID(manifest_json)),
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_idempotency (supplier_id, idempotency_key_hash),
  UNIQUE KEY uq_payroll_document_revision (
    supplier_id, revision_id, document_kind, employee_scope_id, document_revision_no
  ),
  UNIQUE KEY uq_payroll_document_supplier_id (supplier_id, id),
  KEY idx_payroll_document_run (supplier_id, run_id, revision_id, document_kind),
  KEY idx_payroll_document_employee (supplier_id, employee_id, created_at),
  CONSTRAINT fk_payroll_document_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_supersedes
    FOREIGN KEY (supplier_id, supersedes_document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_document_kind CHECK (
    document_kind IN (
      'payslip','payroll_sheet','taxable_income_advance_certificate',
      'taxable_income_withholding_certificate',
      'employment_certificate','average_earnings_certificate','monthly_bundle'
    )
  ),
  CONSTRAINT chk_payroll_document_hashes CHECK (
    revision_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND file_sha256 REGEXP '^[0-9a-f]{64}$'
    AND storage_key = file_sha256
  ),
  CONSTRAINT chk_payroll_document_filename CHECK (
    suggested_filename REGEXP '^[a-z0-9][a-z0-9._-]{0,159}$'
  ),
  CONSTRAINT chk_payroll_document_size CHECK (size_bytes > 0),
  CONSTRAINT chk_payroll_document_revision_no CHECK (document_revision_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_document_download_grants (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  document_id           BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  token_hash            BINARY(32) NOT NULL,
  expires_at            DATETIME NOT NULL,
  used_at               DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_grant_token (token_hash),
  KEY idx_payroll_document_grant_document (supplier_id, document_id, expires_at),
  CONSTRAINT fk_payroll_document_grant_document
    FOREIGN KEY (supplier_id, document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_grant_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
