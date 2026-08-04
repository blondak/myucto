-- MyÚčto.cz — MZ-16: hardening ročních dokumentových anchorů.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  ADD KEY IF NOT EXISTS idx_payroll_document_annual_revision (
    supplier_id,
    annual_revision_id
  );

ALTER TABLE payroll_generated_documents
  DROP INDEX IF EXISTS uq_payroll_document_annual_revision;

ALTER TABLE payroll_generated_documents
  DROP COLUMN IF EXISTS annual_scope_id;

ALTER TABLE payroll_generated_documents
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_document_annual_revision (
    supplier_id,
    annual_revision_id,
    document_kind,
    employee_scope_id,
    document_revision_no
  );

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_annual_revision_validate_insert
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

CREATE OR REPLACE TRIGGER trg_payroll_annual_source_validate_insert
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
