-- MyÚčto.cz — MZ-21: datový základ identity pro omezený REGZEC.
--
-- Jméno a příjmení zůstávají explicitními historickými poli. Migrace nikdy
-- neodvozuje strukturované údaje z payroll_employees.full_name.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_identity_history
  ADD COLUMN IF NOT EXISTS title_prefix VARCHAR(64) NULL
    AFTER last_name,
  ADD COLUMN IF NOT EXISTS title_suffix VARCHAR(64) NULL
    AFTER title_prefix,
  ADD COLUMN IF NOT EXISTS birth_date DATE NULL
    AFTER birth_surname,
  ADD COLUMN IF NOT EXISTS birth_place VARCHAR(128) NULL
    AFTER birth_date,
  ADD COLUMN IF NOT EXISTS birth_country_code CHAR(2) NULL
    AFTER birth_place,
  ADD COLUMN IF NOT EXISTS citizenship_country_code CHAR(2) NULL
    AFTER birth_country_code,
  ADD COLUMN IF NOT EXISTS sex
    ENUM('female','male','unspecified') NULL
    AFTER citizenship_country_code,
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_identity_birth_country
    CHECK (
      birth_country_code IS NULL
      OR birth_country_code REGEXP '^[A-Z]{2}$'
    ),
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_identity_citizenship
    CHECK (
      citizenship_country_code IS NULL
      OR citizenship_country_code REGEXP '^[A-Z]{2}$'
    );

CREATE TABLE IF NOT EXISTS payroll_employment_external_ids (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  identifier_type       ENUM('id_ppv') NOT NULL,
  value_ciphertext      VARCHAR(1024) NOT NULL,
  value_hash            BINARY(32) NOT NULL,
  value_masked          VARCHAR(191) NOT NULL,
  valid_from            DATE NOT NULL,
  valid_to              DATE NULL,
  source_kind           ENUM('trusted_receipt','verified_manual_import') NOT NULL,
  source_receipt_id     BIGINT UNSIGNED NULL,
  source_reference_hash CHAR(64) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  updated_by            BIGINT UNSIGNED NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  active_identifier_type VARCHAR(32)
    GENERATED ALWAYS AS (
      CASE WHEN valid_to IS NULL THEN identifier_type ELSE NULL END
    ) STORED,

  UNIQUE KEY uq_payroll_employment_external_id_supplier
    (supplier_id, id),
  UNIQUE KEY uq_payroll_employment_external_id_environment
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_employment_external_id_active
    (
      supplier_id, environment, employment_id,
      active_identifier_type
    ),
  UNIQUE KEY uq_payroll_employment_external_id_value
    (supplier_id, environment, identifier_type, value_hash),
  KEY idx_payroll_employment_external_id_history
    (
      supplier_id, employment_id, environment,
      identifier_type, valid_from, valid_to
    ),
  CONSTRAINT fk_payroll_employment_external_id_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_external_id_receipt
    FOREIGN KEY (supplier_id, source_receipt_id)
    REFERENCES payroll_submission_receipts (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_external_id_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_external_id_updater
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_employment_external_id_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_employment_external_id_ciphertext
    CHECK (value_ciphertext LIKE 'enc:v2:%'),
  CONSTRAINT chk_payroll_employment_external_id_source_hash
    CHECK (source_reference_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_employment_external_id_source
    CHECK (
      (source_kind = 'trusted_receipt' AND source_receipt_id IS NOT NULL)
      OR
      (source_kind = 'verified_manual_import' AND source_receipt_id IS NULL)
    ),
  CONSTRAINT chk_payroll_employment_external_id_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_identity_resolution_tasks (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  employee_id             BIGINT UNSIGNED NOT NULL,
  employment_id           BIGINT UNSIGNED NOT NULL,
  environment             ENUM('production','test') NOT NULL,
  task_kind               ENUM(
                              'person_identity',
                              'employment_external_id'
                            ) NOT NULL,
  reason_code             VARCHAR(64) NOT NULL,
  status                  ENUM(
                              'open',
                              'manual_review',
                              'resolved',
                              'cancelled'
                            ) NOT NULL DEFAULT 'open',
  candidate_count         SMALLINT UNSIGNED NULL,
  source_receipt_id       BIGINT UNSIGNED NULL,
  resolved_external_id_id BIGINT UNSIGNED NULL,
  resolution_evidence_hash CHAR(64) NULL,
  assigned_to             BIGINT UNSIGNED NULL,
  resolved_by             BIGINT UNSIGNED NULL,
  resolved_at             DATETIME NULL,
  row_version             INT UNSIGNED NOT NULL DEFAULT 1,
  created_by              BIGINT UNSIGNED NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  active_task_kind        VARCHAR(32)
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('open','manual_review') THEN task_kind
        ELSE NULL
      END
    ) STORED,

  UNIQUE KEY uq_payroll_identity_resolution_task_supplier
    (supplier_id, id),
  UNIQUE KEY uq_payroll_identity_resolution_task_active
    (
      supplier_id, environment, employment_id,
      active_task_kind
    ),
  KEY idx_payroll_identity_resolution_task_queue
    (supplier_id, status, assigned_to, created_at),
  CONSTRAINT fk_payroll_identity_resolution_task_employment
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_identity_resolution_task_receipt
    FOREIGN KEY (supplier_id, source_receipt_id)
    REFERENCES payroll_submission_receipts (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_identity_resolution_task_external_id
    FOREIGN KEY (
      supplier_id, environment, resolved_external_id_id
    )
    REFERENCES payroll_employment_external_ids (
      supplier_id, environment, id
    )
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_identity_resolution_task_assignee
    FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_identity_resolution_task_resolver
    FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_identity_resolution_task_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_identity_resolution_task_reason
    CHECK (reason_code REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'),
  CONSTRAINT chk_payroll_identity_resolution_task_candidate_count
    CHECK (candidate_count IS NULL OR candidate_count <= 1500),
  CONSTRAINT chk_payroll_identity_resolution_task_resolution
    CHECK (
      (
        status = 'resolved'
        AND resolved_at IS NOT NULL
        AND resolved_by IS NOT NULL
        AND resolution_evidence_hash REGEXP '^[0-9a-f]{64}$'
        AND (
          task_kind <> 'employment_external_id'
          OR resolved_external_id_id IS NOT NULL
        )
      )
      OR
      (
        status <> 'resolved'
        AND resolved_at IS NULL
        AND resolved_by IS NULL
        AND resolved_external_id_id IS NULL
        AND resolution_evidence_hash IS NULL
      )
    ),
  CONSTRAINT chk_payroll_identity_resolution_task_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
