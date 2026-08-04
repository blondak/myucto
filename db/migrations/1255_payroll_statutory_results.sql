-- MyÚčto.cz — MZ-10 až MZ-13: neměnné auditní výsledky zákonných výpočtů.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_owner
    (supplier_id, id, employee_id);

ALTER TABLE payroll_run_employments
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_run_employment_owner
    (supplier_id, revision_id, employee_id, employment_id);

ALTER TABLE payroll_run_employments
  DROP FOREIGN KEY IF EXISTS fk_payroll_run_employment_owner;

ALTER TABLE payroll_run_employments
  ADD CONSTRAINT fk_payroll_run_employment_owner
    FOREIGN KEY (supplier_id, employment_id, employee_id)
    REFERENCES payroll_employments (supplier_id, id, employee_id)
    ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS payroll_statutory_results (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  calculation_kind      ENUM(
    'social_insurance','health_insurance','income_tax','net_pay'
  ) NOT NULL,
  schema_version        VARCHAR(64) NOT NULL,
  result_status         ENUM('calculated','manual_review','error') NOT NULL,
  ruleset_id            VARCHAR(96) NOT NULL,
  ruleset_hash          CHAR(64) NOT NULL,
  input_snapshot_json   LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash   CHAR(64) NOT NULL,
  result_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(result_snapshot_json)),
  result_snapshot_hash  CHAR(64) NOT NULL,
  result_set_hash       CHAR(64) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_statutory_result_revision
    (supplier_id, revision_id, calculation_kind),
  UNIQUE KEY uq_payroll_statutory_result_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_statutory_result_scope
    (supplier_id, id, revision_id, calculation_kind),
  KEY idx_payroll_statutory_result_status
    (supplier_id, revision_id, result_status, calculation_kind),
  CONSTRAINT fk_payroll_statutory_result_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_result_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_statutory_result_schema
    CHECK (schema_version REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'),
  CONSTRAINT chk_payroll_statutory_result_ruleset
    CHECK (
      ruleset_id <> ''
      AND ruleset_hash REGEXP '^[0-9a-f]{64}$'
    ),
  CONSTRAINT chk_payroll_statutory_result_hashes
    CHECK (
      input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_set_hash REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_statutory_person_results (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  statutory_result_id   BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  calculation_kind      ENUM(
    'social_insurance','health_insurance','income_tax','net_pay'
  ) NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  result_status         ENUM('calculated','manual_review','error') NOT NULL,
  input_snapshot_json   LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash   CHAR(64) NOT NULL,
  result_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(result_snapshot_json)),
  result_snapshot_hash  CHAR(64) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_statutory_person
    (supplier_id, statutory_result_id, employee_id),
  UNIQUE KEY uq_payroll_statutory_person_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_statutory_person_scope
    (
      supplier_id, id, statutory_result_id, revision_id,
      calculation_kind, employee_id
    ),
  KEY idx_payroll_statutory_person_revision
    (supplier_id, revision_id, employee_id, calculation_kind),
  CONSTRAINT fk_payroll_statutory_person_parent
    FOREIGN KEY (
      supplier_id, statutory_result_id, revision_id, calculation_kind
    )
    REFERENCES payroll_statutory_results
      (supplier_id, id, revision_id, calculation_kind)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_person_run
    FOREIGN KEY (supplier_id, revision_id, employee_id)
    REFERENCES payroll_run_persons (supplier_id, revision_id, employee_id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_statutory_person_hashes
    CHECK (
      input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_statutory_relationship_results (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  statutory_result_id   BIGINT UNSIGNED NOT NULL,
  person_result_id      BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  calculation_kind      ENUM(
    'social_insurance','health_insurance','income_tax','net_pay'
  ) NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  result_status         ENUM('calculated','manual_review','error') NOT NULL,
  input_snapshot_json   LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash   CHAR(64) NOT NULL,
  result_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(result_snapshot_json)),
  result_snapshot_hash  CHAR(64) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_statutory_relationship
    (supplier_id, person_result_id, employment_id),
  UNIQUE KEY uq_payroll_statutory_relationship_supplier_id (supplier_id, id),
  KEY idx_payroll_statutory_relationship_revision
    (supplier_id, revision_id, employment_id, calculation_kind),
  CONSTRAINT fk_payroll_statutory_relationship_parent
    FOREIGN KEY (
      supplier_id, person_result_id, statutory_result_id, revision_id,
      calculation_kind, employee_id
    )
    REFERENCES payroll_statutory_person_results
      (
        supplier_id, id, statutory_result_id, revision_id,
        calculation_kind, employee_id
      )
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_statutory_relationship_run
    FOREIGN KEY (supplier_id, revision_id, employee_id, employment_id)
    REFERENCES payroll_run_employments
      (supplier_id, revision_id, employee_id, employment_id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_statutory_relationship_hashes
    CHECK (
      input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_result_immutable_update
BEFORE UPDATE ON payroll_statutory_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory results are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_result_immutable_delete
BEFORE DELETE ON payroll_statutory_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory results are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_person_immutable_update
BEFORE UPDATE ON payroll_statutory_person_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory person results are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_person_immutable_delete
BEFORE DELETE ON payroll_statutory_person_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory person results are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_relationship_immutable_update
BEFORE UPDATE ON payroll_statutory_relationship_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory relationship results are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_statutory_relationship_immutable_delete
BEFORE DELETE ON payroll_statutory_relationship_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory relationship results are append-only';
END//

DELIMITER ;
