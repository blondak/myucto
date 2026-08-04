-- MyÚčto.cz — MZ-16: neměnné roční zdroje mzdových dokumentů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_annual_document_revisions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  tax_year              SMALLINT UNSIGNED NOT NULL,
  purpose               VARCHAR(48) NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  previous_revision_id  BIGINT UNSIGNED NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_hash         CHAR(64) NOT NULL,
  source_manifest_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_manifest_json)),
  source_manifest_hash  CHAR(64) NOT NULL,
  approved_by           BIGINT UNSIGNED NULL,
  approved_at           DATETIME NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_revision_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_annual_revision_sequence (
    supplier_id, employee_id, tax_year, purpose, revision_no
  ),
  UNIQUE KEY uq_payroll_annual_revision_source (
    supplier_id, employee_id, tax_year, purpose, source_manifest_hash
  ),
  KEY idx_payroll_annual_revision_latest (
    supplier_id, tax_year, purpose, employee_id, revision_no
  ),
  CONSTRAINT fk_payroll_annual_revision_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_revision_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_revision_previous
    FOREIGN KEY (supplier_id, previous_revision_id)
    REFERENCES payroll_annual_document_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_revision_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_annual_revision_purpose CHECK (
    purpose IN (
      'payroll_sheet',
      'taxable_income_advance_certificate',
      'taxable_income_withholding_certificate',
      'annual_settlement_result'
    )
  ),
  CONSTRAINT chk_payroll_annual_revision_year CHECK (tax_year BETWEEN 2000 AND 2199),
  CONSTRAINT chk_payroll_annual_revision_number CHECK (revision_no > 0),
  CONSTRAINT chk_payroll_annual_revision_hashes CHECK (
    snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND source_manifest_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_annual_document_sources (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  annual_revision_id    BIGINT UNSIGNED NOT NULL,
  run_revision_id       BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  period_start          DATE NOT NULL,
  person_result_hash    CHAR(64) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_annual_source_revision (
    supplier_id, annual_revision_id, run_revision_id, employee_id
  ),
  KEY idx_payroll_annual_source_run (supplier_id, run_revision_id),
  CONSTRAINT fk_payroll_annual_source_annual
    FOREIGN KEY (supplier_id, annual_revision_id)
    REFERENCES payroll_annual_document_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_source_run_revision
    FOREIGN KEY (supplier_id, run_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_annual_source_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_annual_source_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_annual_source_hash CHECK (
    person_result_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_annual_revision_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_annual_revision_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_annual_revision_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_annual_source_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_annual_source_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_annual_source_validate_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_annual_revision_immutable_update
BEFORE UPDATE ON payroll_annual_document_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Annual payroll document revisions are immutable';
END//

CREATE TRIGGER trg_payroll_annual_revision_immutable_delete
BEFORE DELETE ON payroll_annual_document_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Annual payroll document revisions are append-only';
END//

CREATE TRIGGER trg_payroll_annual_revision_validate_insert
BEFORE INSERT ON payroll_annual_document_revisions
FOR EACH ROW
BEGIN
  IF NEW.previous_revision_id IS NULL THEN
    IF NEW.revision_no <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'First annual payroll revision must have revision number 1';
    END IF;
  ELSEIF NOT EXISTS (
    SELECT 1
      FROM payroll_annual_document_revisions previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.previous_revision_id
       AND previous.employee_id = NEW.employee_id
       AND previous.tax_year = NEW.tax_year
       AND previous.purpose = NEW.purpose
       AND previous.revision_no + 1 = NEW.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Annual payroll revision chain is inconsistent';
  END IF;
END//

CREATE TRIGGER trg_payroll_annual_source_immutable_update
BEFORE UPDATE ON payroll_annual_document_sources
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Annual payroll document sources are immutable';
END//

CREATE TRIGGER trg_payroll_annual_source_immutable_delete
BEFORE DELETE ON payroll_annual_document_sources
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Annual payroll document sources are append-only';
END//

CREATE TRIGGER trg_payroll_annual_source_validate_insert
BEFORE INSERT ON payroll_annual_document_sources
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_annual_document_revisions annual_revision
      JOIN payroll_run_revisions run_revision
        ON run_revision.supplier_id = NEW.supplier_id
       AND run_revision.id = NEW.run_revision_id
      JOIN payroll_runs payroll_run
        ON payroll_run.supplier_id = run_revision.supplier_id
       AND payroll_run.id = run_revision.run_id
      JOIN payroll_run_persons person_result
        ON person_result.supplier_id = run_revision.supplier_id
       AND person_result.revision_id = run_revision.id
       AND person_result.employee_id = NEW.employee_id
     WHERE annual_revision.supplier_id = NEW.supplier_id
       AND annual_revision.id = NEW.annual_revision_id
       AND annual_revision.employee_id = NEW.employee_id
       AND annual_revision.tax_year = YEAR(NEW.period_start)
       AND payroll_run.period_start = NEW.period_start
       AND run_revision.status IN ('approved', 'superseded')
       AND person_result.status = 'calculated'
       AND person_result.result_hash = NEW.person_result_hash
       AND NOT EXISTS (
         SELECT 1
           FROM payroll_run_revisions newer_revision
          WHERE newer_revision.supplier_id = run_revision.supplier_id
            AND newer_revision.run_id = run_revision.run_id
            AND newer_revision.revision_no > run_revision.revision_no
            AND newer_revision.status IN ('approved', 'superseded')
            AND newer_revision.result_snapshot_hash IS NOT NULL
       )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Annual payroll source does not match its approved revision';
  END IF;
END//

DELIMITER ;

ALTER TABLE payroll_generated_documents
  DROP FOREIGN KEY IF EXISTS fk_payroll_document_annual_revision,
  DROP CONSTRAINT IF EXISTS chk_payroll_document_anchor,
  DROP INDEX IF EXISTS uq_payroll_document_annual_revision;

ALTER TABLE payroll_generated_documents
  ADD COLUMN IF NOT EXISTS annual_revision_id BIGINT UNSIGNED NULL
    AFTER revision_id;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT fk_payroll_document_annual_revision
    FOREIGN KEY (supplier_id, annual_revision_id)
    REFERENCES payroll_annual_document_revisions (supplier_id, id) ON DELETE RESTRICT,
  ADD KEY IF NOT EXISTS idx_payroll_document_annual_revision (
    supplier_id, annual_revision_id
  ),
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_document_annual_revision (
    supplier_id, annual_revision_id, document_kind, employee_scope_id, document_revision_no
  ),
  ADD CONSTRAINT chk_payroll_document_anchor CHECK (
    (
      annual_revision_id IS NULL
      AND run_id IS NOT NULL
      AND revision_id IS NOT NULL
    )
    OR
    (
      annual_revision_id IS NOT NULL
      AND run_id IS NULL
      AND revision_id IS NULL
    )
  );

ALTER TABLE payroll_generated_documents
  DROP FOREIGN KEY IF EXISTS fk_payroll_document_run;

ALTER TABLE payroll_generated_documents
  MODIFY COLUMN run_id BIGINT UNSIGNED NULL;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT fk_payroll_document_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT;

ALTER TABLE payroll_generated_documents
  DROP FOREIGN KEY IF EXISTS fk_payroll_document_revision;

ALTER TABLE payroll_generated_documents
  MODIFY COLUMN revision_id BIGINT UNSIGNED NULL;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT fk_payroll_document_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_document_approved_revision_insert
BEFORE INSERT ON payroll_generated_documents
FOR EACH ROW
BEGIN
  IF NEW.annual_revision_id IS NULL THEN
    IF NOT EXISTS (
      SELECT 1
        FROM payroll_run_revisions revision
       WHERE revision.supplier_id = NEW.supplier_id
         AND revision.id = NEW.revision_id
         AND revision.run_id = NEW.run_id
         AND revision.status IN ('approved', 'superseded')
         AND revision.result_snapshot_hash = NEW.revision_snapshot_hash
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll document requires an approved matching revision';
    END IF;
  ELSE
    IF NOT EXISTS (
      SELECT 1
        FROM payroll_annual_document_revisions revision
       WHERE revision.supplier_id = NEW.supplier_id
         AND revision.id = NEW.annual_revision_id
         AND revision.employee_id <=> NEW.employee_id
         AND revision.snapshot_hash = NEW.revision_snapshot_hash
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll document requires an approved annual revision';
    END IF;
  END IF;
END//

DELIMITER ;
