-- MyÚčto.cz — MZ-14: právní podklad navázaný na případ nelze změnit ani přesunout do koše.

SET NAMES utf8mb4;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_documents_payroll_enforcement_evidence_update
BEFORE UPDATE ON documents
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_enforcement_case_documents case_document
     WHERE case_document.dms_document_id = OLD.id
  ) AND (
    NOT (NEW.supplier_id <=> OLD.supplier_id)
    OR NOT (NEW.sha256 <=> OLD.sha256)
    OR NOT (NEW.deleted_at <=> OLD.deleted_at)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document is retained as payroll enforcement evidence';
  END IF;
END//

DELIMITER ;
