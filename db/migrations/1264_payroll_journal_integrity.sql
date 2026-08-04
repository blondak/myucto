-- 1264: MZ-18 — journal entries require and preserve their payroll batch context.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_posting_batch_immutable_update//

CREATE TRIGGER trg_payroll_posting_batch_immutable_update
BEFORE UPDATE ON payroll_posting_batches
FOR EACH ROW
BEGIN
  IF OLD.status <> 'prepared'
    OR NEW.status NOT IN ('posted', 'no_change')
    OR NOT (NEW.supplier_id <=> OLD.supplier_id)
    OR NOT (NEW.run_id <=> OLD.run_id)
    OR NOT (NEW.revision_id <=> OLD.revision_id)
    OR NOT (NEW.previous_batch_id <=> OLD.previous_batch_id)
    OR NOT (NEW.entry_date <=> OLD.entry_date)
    OR NOT (NEW.target_hash <=> OLD.target_hash)
    OR NOT (NEW.delta_hash <=> OLD.delta_hash)
    OR NOT (NEW.created_by <=> OLD.created_by)
    OR NOT (NEW.created_at <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Completed payroll posting batches are immutable';
  END IF;
  IF NEW.status = 'posted' AND NOT EXISTS (
    SELECT 1
      FROM journal_entries journal
     WHERE journal.supplier_id = NEW.supplier_id
       AND journal.id = NEW.journal_entry_id
       AND journal.source_type = 'payroll'
       AND journal.source_id = NEW.revision_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll posting batch journal does not match revision';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_journal_payroll_batch_insert//

CREATE TRIGGER trg_journal_payroll_batch_insert
BEFORE INSERT ON journal_entries
FOR EACH ROW
BEGIN
  IF NEW.source_type = 'payroll' AND (
    NEW.source_id IS NULL
    OR NOT EXISTS (
      SELECT 1
        FROM payroll_posting_batches batch
        JOIN payroll_run_revisions revision
          ON revision.supplier_id = batch.supplier_id
         AND revision.id = batch.revision_id
         AND revision.run_id = batch.run_id
        JOIN payroll_runs run
          ON run.supplier_id = revision.supplier_id
         AND run.id = revision.run_id
       WHERE batch.supplier_id = NEW.supplier_id
         AND batch.revision_id = NEW.source_id
         AND batch.status = 'prepared'
         AND revision.status = 'approved'
         AND run.current_revision_no = revision.revision_no
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll journal requires a prepared approved revision batch';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_journal_payroll_immutable_update//

CREATE TRIGGER trg_journal_payroll_immutable_update
BEFORE UPDATE ON journal_entries
FOR EACH ROW
BEGIN
  IF OLD.source_type = 'payroll' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll journal entries are immutable';
  END IF;
END//

DELIMITER ;
