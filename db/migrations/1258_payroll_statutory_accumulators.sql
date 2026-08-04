-- MyÚčto.cz — auditovatelné roční kumulace zákonných mzdových výpočtů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_statutory_accumulator_openings (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  tax_year                   SMALLINT UNSIGNED NOT NULL,
  calculation_kind           ENUM('social_insurance','income_tax') NOT NULL,
  values_json                LONGTEXT NOT NULL CHECK (JSON_VALID(values_json)),
  source_reference           VARCHAR(190) NOT NULL,
  evidence_json              LONGTEXT NOT NULL CHECK (JSON_VALID(evidence_json)),
  replaces_opening_id        BIGINT UNSIGNED NULL,
  predecessor_scope_id       BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(replaces_opening_id, 0)) STORED,
  idempotency_key_hash       BINARY(32) NOT NULL,
  record_hash                CHAR(64) NOT NULL,
  created_by                 BIGINT UNSIGNED NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_statutory_opening_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_statutory_opening_scope_id
    (supplier_id, employee_id, tax_year, calculation_kind, id),
  UNIQUE KEY uq_payroll_statutory_opening_predecessor
    (supplier_id, employee_id, tax_year, calculation_kind, predecessor_scope_id),
  UNIQUE KEY uq_payroll_statutory_opening_idempotency
    (supplier_id, idempotency_key_hash),
  CONSTRAINT fk_payroll_statutory_opening_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_opening_previous
    FOREIGN KEY (
      supplier_id, employee_id, tax_year, calculation_kind, replaces_opening_id
    )
    REFERENCES payroll_statutory_accumulator_openings (
      supplier_id, employee_id, tax_year, calculation_kind, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_opening_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_statutory_opening_year
    CHECK (tax_year BETWEEN 2000 AND 2200),
  CONSTRAINT chk_payroll_statutory_opening_source
    CHECK (source_reference <> ''),
  CONSTRAINT chk_payroll_statutory_opening_hash
    CHECK (record_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_statutory_accumulator_entries (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  tax_year                   SMALLINT UNSIGNED NOT NULL,
  period_start               DATE NOT NULL,
  revision_id                BIGINT UNSIGNED NOT NULL,
  calculation_kind           ENUM('social_insurance','income_tax') NOT NULL,
  values_json                LONGTEXT NOT NULL CHECK (JSON_VALID(values_json)),
  source_result_hash         CHAR(64) NOT NULL,
  replaces_entry_id          BIGINT UNSIGNED NULL,
  predecessor_scope_id       BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(replaces_entry_id, 0)) STORED,
  record_hash                CHAR(64) NOT NULL,
  created_by                 BIGINT UNSIGNED NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_statutory_entry_supplier_id
    (supplier_id, id),
  UNIQUE KEY uq_payroll_statutory_entry_scope_id
    (supplier_id, employee_id, tax_year, period_start, calculation_kind, id),
  UNIQUE KEY uq_payroll_statutory_entry_revision
    (supplier_id, revision_id, employee_id, calculation_kind),
  UNIQUE KEY uq_payroll_statutory_entry_replacement
    (
      supplier_id, employee_id, tax_year, period_start, calculation_kind,
      predecessor_scope_id
    ),
  KEY idx_payroll_statutory_entry_read_model
    (supplier_id, employee_id, tax_year, calculation_kind, period_start),
  CONSTRAINT fk_payroll_statutory_entry_person
    FOREIGN KEY (supplier_id, revision_id, employee_id)
    REFERENCES payroll_run_persons (supplier_id, revision_id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_entry_previous
    FOREIGN KEY (
      supplier_id, employee_id, tax_year, period_start, calculation_kind,
      replaces_entry_id
    )
    REFERENCES payroll_statutory_accumulator_entries (
      supplier_id, employee_id, tax_year, period_start, calculation_kind, id
    ) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_entry_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_statutory_entry_year
    CHECK (tax_year BETWEEN 2000 AND 2200),
  CONSTRAINT chk_payroll_statutory_entry_period
    CHECK (DAY(period_start) = 1 AND YEAR(period_start) = tax_year),
  CONSTRAINT chk_payroll_statutory_entry_source_hash
    CHECK (source_result_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_statutory_entry_record_hash
    CHECK (record_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_opening_immutable_update
BEFORE UPDATE ON payroll_statutory_accumulator_openings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator openings are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_opening_immutable_delete
BEFORE DELETE ON payroll_statutory_accumulator_openings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator openings are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_entry_immutable_update
BEFORE UPDATE ON payroll_statutory_accumulator_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator entries are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_entry_immutable_delete
BEFORE DELETE ON payroll_statutory_accumulator_entries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator entries are append-only';
END//

DELIMITER ;
