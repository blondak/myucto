-- MyÚčto.cz — MZ-16: oddělení otisku schválené revize a zdrojového DTO dokumentu.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  ADD COLUMN IF NOT EXISTS revision_snapshot_hash CHAR(64) NULL
  AFTER supersedes_document_id;

UPDATE payroll_generated_documents document
JOIN payroll_run_revisions revision
  ON revision.supplier_id = document.supplier_id
 AND revision.id = document.revision_id
SET document.revision_snapshot_hash = revision.result_snapshot_hash
WHERE document.revision_snapshot_hash IS NULL;

ALTER TABLE payroll_generated_documents
  MODIFY COLUMN revision_snapshot_hash CHAR(64) NOT NULL;

DROP TRIGGER IF EXISTS trg_payroll_document_approved_revision_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_document_approved_revision_insert
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
