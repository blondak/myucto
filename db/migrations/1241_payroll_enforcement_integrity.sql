-- MyÚčto.cz — MZ-14: databázová neměnnost a tenantová integrita srážek.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_events
  ADD COLUMN IF NOT EXISTS decision_document_id BIGINT UNSIGNED NULL
    AFTER decision_evidence_hash,
  ADD INDEX IF NOT EXISTS idx_payroll_enforcement_event_document
    (decision_document_id);

ALTER TABLE payroll_enforcement_events
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_event_document;

ALTER TABLE payroll_enforcement_events
  ADD CONSTRAINT fk_payroll_enforcement_event_document
    FOREIGN KEY (decision_document_id) REFERENCES documents (id)
    ON DELETE RESTRICT;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_result_immutable_update
BEFORE UPDATE ON payroll_enforcement_month_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement results are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_result_immutable_delete
BEFORE DELETE ON payroll_enforcement_month_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement results are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_allocation_immutable_update
BEFORE UPDATE ON payroll_enforcement_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement allocations are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_allocation_immutable_delete
BEFORE DELETE ON payroll_enforcement_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement allocations are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_event_immutable_update
BEFORE UPDATE ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement events are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_event_immutable_delete
BEFORE DELETE ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement events are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_ledger_immutable_update
BEFORE UPDATE ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement ledger is immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_ledger_immutable_delete
BEFORE DELETE ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement ledger is append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_result_revision_insert
BEFORE INSERT ON payroll_enforcement_month_results
FOR EACH ROW
BEGIN
  IF NEW.revision_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
      JOIN payroll_runs run
        ON run.supplier_id = revision.supplier_id
       AND run.id = revision.run_id
      JOIN payroll_run_persons person
        ON person.supplier_id = revision.supplier_id
       AND person.revision_id = revision.id
       AND person.employee_id = NEW.employee_id
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.revision_id
       AND run.period_start = NEW.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement result does not match run person and period';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_allocation_consistency_insert
BEFORE INSERT ON payroll_enforcement_allocations
FOR EACH ROW
BEGIN
  IF (NEW.case_id IS NULL) <> (NEW.claim_id IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement allocation case and claim mismatch';
  END IF;

  IF NEW.case_id IS NULL THEN
    IF NEW.allocation_key <> 'insolvency-administrator' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement virtual allocation is not supported';
    END IF;
  ELSEIF NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_claims claim
      JOIN payroll_enforcement_cases enforcement_case
        ON enforcement_case.supplier_id = claim.supplier_id
       AND enforcement_case.id = claim.case_id
      JOIN payroll_enforcement_month_results result
        ON result.supplier_id = enforcement_case.supplier_id
       AND result.id = NEW.month_result_id
       AND result.employee_id = enforcement_case.employee_id
     WHERE claim.supplier_id = NEW.supplier_id
       AND claim.id = NEW.claim_id
       AND claim.case_id = NEW.case_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement allocation owner mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_ledger_consistency_insert
BEFORE INSERT ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  IF NEW.claim_id IS NOT NULL AND NEW.case_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement ledger claim requires a case';
  END IF;

  IF NEW.case_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_cases enforcement_case
      JOIN payroll_enforcement_month_results result
        ON result.supplier_id = enforcement_case.supplier_id
       AND result.id = NEW.month_result_id
       AND result.employee_id = enforcement_case.employee_id
      LEFT JOIN payroll_enforcement_claims claim
        ON claim.supplier_id = enforcement_case.supplier_id
       AND claim.id = NEW.claim_id
       AND claim.case_id = enforcement_case.id
     WHERE enforcement_case.supplier_id = NEW.supplier_id
       AND enforcement_case.id = NEW.case_id
       AND (NEW.claim_id IS NULL OR claim.id IS NOT NULL)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement ledger owner mismatch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_event_document_insert
BEFORE INSERT ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  IF NEW.decision_document_id IS NULL AND NEW.decision_evidence_hash IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision hash requires a DMS document';
  END IF;

  IF NEW.decision_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.id = NEW.decision_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.decision_evidence_hash
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision document mismatch';
  END IF;
END//

DELIMITER ;
