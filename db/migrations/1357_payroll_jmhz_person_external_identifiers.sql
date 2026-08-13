-- MyÚčto.cz — MZ-22-W01e-e: environment-scoped OIČ / IK MPSV osoby.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_person_external_ids (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  identifier_type       ENUM('ik_mpsv') NOT NULL,
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

  UNIQUE KEY uq_payroll_person_external_id_supplier
    (supplier_id, id),
  UNIQUE KEY uq_payroll_person_external_id_environment
    (supplier_id, environment, id),
  UNIQUE KEY uq_payroll_person_external_id_active
    (supplier_id, environment, employee_id, active_identifier_type),
  UNIQUE KEY uq_payroll_person_external_id_value
    (supplier_id, environment, identifier_type, value_hash),
  KEY idx_payroll_person_external_id_history
    (supplier_id, employee_id, environment, identifier_type, valid_from, valid_to),
  KEY idx_payroll_person_external_id_receipt
    (supplier_id, environment, source_receipt_id),
  CONSTRAINT fk_payroll_person_external_id_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_person_external_id_receipt
    FOREIGN KEY (supplier_id, environment, source_receipt_id)
    REFERENCES payroll_submission_receipts (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_person_external_id_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_person_external_id_updater
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_person_external_id_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_person_external_id_ciphertext
    CHECK (value_ciphertext LIKE 'enc:v2:%'),
  CONSTRAINT chk_payroll_person_external_id_mask
    CHECK (value_masked <> ''),
  CONSTRAINT chk_payroll_person_external_id_source_hash
    CHECK (source_reference_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_person_external_id_source
    CHECK (
      (source_kind = 'trusted_receipt' AND source_receipt_id IS NOT NULL)
      OR
      (source_kind = 'verified_manual_import' AND source_receipt_id IS NULL)
    ),
  CONSTRAINT chk_payroll_person_external_id_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
