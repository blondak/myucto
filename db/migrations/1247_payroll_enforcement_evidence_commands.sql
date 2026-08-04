-- MyÚčto.cz — MZ-14: klasifikace rozhodnutí pro aktivaci a obnovení deponování.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_enforcement_event_document_insert//

CREATE TRIGGER trg_payroll_enforcement_event_document_insert
BEFORE INSERT ON payroll_enforcement_events
FOR EACH ROW
BEGIN
  IF NEW.decision_document_id IS NULL
     AND (NEW.decision_evidence_hash IS NOT NULL
       OR NEW.decision_case_document_id IS NOT NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision reference is incomplete';
  END IF;

  IF NEW.decision_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_documents case_document
     WHERE case_document.supplier_id = NEW.supplier_id
       AND case_document.id = NEW.decision_case_document_id
       AND case_document.case_id = NEW.case_id
       AND case_document.dms_document_id = NEW.decision_document_id
       AND case_document.document_sha256 = NEW.decision_evidence_hash
       AND case_document.evidence_kind = CASE NEW.command_name
         WHEN 'mark_final' THEN 'initial_order'
         WHEN 'authorize_remittance' THEN 'remittance'
         WHEN 'defer_no_withholding' THEN 'deferment'
         WHEN 'defer_hold' THEN 'deferment'
         WHEN 'resume_holding' THEN 'resumption'
         WHEN 'resume_remittance' THEN 'resumption'
         WHEN 'stop' THEN 'termination'
         ELSE ''
       END
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement decision classification mismatch';
  END IF;
END//

DELIMITER ;
