-- MyÚčto.cz — bezpečné odstranění prázdného technického obalu mzdového běhu.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_run_event_append_only_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_run_event_append_only_delete
BEFORE DELETE ON payroll_run_events
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_empty_run_delete_supplier_id <=> OLD.supplier_id
    AND @payroll_empty_run_delete_run_id <=> OLD.run_id
    AND @payroll_empty_run_delete_event_id <=> OLD.id
    AND OLD.event_type = 'created'
    AND OLD.revision_id IS NULL
    AND OLD.from_status IS NULL
    AND OLD.to_status = 'draft'
    AND OLD.reason IS NULL
    AND EXISTS (
      SELECT 1
        FROM payroll_runs run
       WHERE run.supplier_id = OLD.supplier_id
         AND run.id = OLD.run_id
         AND run.row_version = @payroll_empty_run_delete_row_version
         AND run.status IN ('draft', 'cancelled')
         AND run.current_revision_no = 0
         AND OLD.actor_user_id <=> run.created_by
    )
    AND NOT EXISTS (
      SELECT 1
        FROM payroll_run_revisions revision
       WHERE revision.supplier_id = OLD.supplier_id
         AND revision.run_id = OLD.run_id
    )
    AND NOT EXISTS (
      SELECT 1
        FROM payroll_run_commands command_receipt
       WHERE command_receipt.supplier_id = OLD.supplier_id
         AND command_receipt.run_id = OLD.run_id
    )
    AND NOT EXISTS (
      SELECT 1
        FROM payroll_generated_documents document
       WHERE document.supplier_id = OLD.supplier_id
         AND document.run_id = OLD.run_id
    )
    AND NOT EXISTS (
      SELECT 1
        FROM payroll_posting_batches posting
       WHERE posting.supplier_id = OLD.supplier_id
         AND posting.run_id = OLD.run_id
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run events are append-only';
  END IF;
END//

DELIMITER ;
