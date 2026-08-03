-- MyÚčto.cz — MZ-16: append-only ochrana mzdových artefaktů.

SET NAMES utf8mb4;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_generated_document_immutable_update
BEFORE UPDATE ON payroll_generated_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Generated payroll documents are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_generated_document_immutable_delete
BEFORE DELETE ON payroll_generated_documents
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Generated payroll documents are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_document_approved_revision_insert
BEFORE INSERT ON payroll_generated_documents
FOR EACH ROW
BEGIN
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
END//

DELIMITER ;
