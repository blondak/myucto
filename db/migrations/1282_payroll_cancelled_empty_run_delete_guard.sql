-- MyÚčto.cz — prázdný běh lze odstranit i po jediném kanonickém zrušení.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_run_event_append_only_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_run_event_append_only_delete
BEFORE DELETE ON payroll_run_events
FOR EACH ROW
BEGIN
  DECLARE allowed_empty_run_delete TINYINT DEFAULT 0;

  IF @payroll_empty_run_delete_supplier_id <=> OLD.supplier_id
     AND @payroll_empty_run_delete_run_id <=> OLD.run_id
     AND OLD.revision_id IS NULL
     AND NOT EXISTS (
       SELECT 1
         FROM payroll_run_revisions revision
        WHERE revision.supplier_id = OLD.supplier_id
          AND revision.run_id = OLD.run_id
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
     ) THEN
    IF @payroll_empty_run_delete_event_id <=> OLD.id
       AND OLD.event_type = 'created'
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
            AND (
              (run.status = 'draft' AND run.row_version = 1)
              OR (run.status = 'cancelled' AND run.row_version = 2)
            )
            AND run.current_revision_no = 0
            AND OLD.actor_user_id <=> run.created_by
       )
       AND NOT EXISTS (
         SELECT 1
           FROM payroll_run_commands command_receipt
          WHERE command_receipt.supplier_id = OLD.supplier_id
            AND command_receipt.run_id = OLD.run_id
       ) THEN
      SET allowed_empty_run_delete = 1;
    ELSEIF @payroll_empty_run_delete_cancel_event_id <=> OLD.id
       AND OLD.event_type = 'cancel'
       AND OLD.from_status = 'draft'
       AND OLD.to_status = 'cancelled'
       AND OLD.reason IS NOT NULL
       AND TRIM(OLD.reason) <> ''
       AND EXISTS (
         SELECT 1
           FROM payroll_runs run
           JOIN payroll_run_commands command_receipt
             ON command_receipt.supplier_id = run.supplier_id
            AND command_receipt.run_id = run.id
            AND command_receipt.id =
                @payroll_empty_run_delete_cancel_command_id
          WHERE run.supplier_id = OLD.supplier_id
            AND run.id = OLD.run_id
            AND run.status = 'cancelled'
            AND run.row_version = @payroll_empty_run_delete_row_version
            AND run.row_version = 2
            AND run.current_revision_no = 0
            AND command_receipt.command_name = 'cancel'
            AND command_receipt.revision_id IS NULL
            AND command_receipt.expected_row_version = 1
            AND command_receipt.from_status = 'draft'
            AND command_receipt.to_status = 'cancelled'
            AND command_receipt.actor_user_id <=> run.updated_by
            AND OLD.actor_user_id <=> command_receipt.actor_user_id
            AND JSON_UNQUOTE(
              JSON_EXTRACT(OLD.metadata_json, '$.request_hash')
            ) = command_receipt.request_hash
            AND JSON_UNQUOTE(
              JSON_EXTRACT(
                OLD.metadata_json,
                '$.idempotency_key_hash'
              )
            ) = LOWER(HEX(command_receipt.idempotency_key_hash))
            AND CAST(JSON_UNQUOTE(
              JSON_EXTRACT(OLD.metadata_json, '$.row_version')
            ) AS UNSIGNED) = run.row_version
       ) THEN
      SET allowed_empty_run_delete = 1;
    END IF;
  END IF;

  IF allowed_empty_run_delete = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run events are append-only';
  END IF;
END//

DELIMITER ;
