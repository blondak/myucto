-- 1262: MZ-18 — immutable posting chain, revision ownership and account format.

SET NAMES utf8mb4;

ALTER TABLE IF EXISTS payroll_components
  DROP CONSTRAINT IF EXISTS chk_payroll_component_accounts;

ALTER TABLE IF EXISTS payroll_components
  ADD CONSTRAINT chk_payroll_component_accounts CHECK (
    (
      accounting_debit_code IS NULL
      OR accounting_debit_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
    )
    AND
    (
      accounting_credit_code IS NULL
      OR accounting_credit_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
    )
  );

ALTER TABLE payroll_run_revisions
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_run_revision_owner
    (supplier_id, id, run_id);

ALTER TABLE payroll_posting_batches
  DROP FOREIGN KEY IF EXISTS fk_payroll_posting_batch_revision;

ALTER TABLE payroll_posting_batches
  ADD CONSTRAINT fk_payroll_posting_batch_revision
    FOREIGN KEY (supplier_id, revision_id, run_id)
    REFERENCES payroll_run_revisions (supplier_id, id, run_id)
    ON DELETE RESTRICT;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_posting_batch_immutable_update
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

CREATE TRIGGER IF NOT EXISTS trg_payroll_posting_batch_immutable_delete
BEFORE DELETE ON payroll_posting_batches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll posting batches are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_posting_allocation_prepared_insert
BEFORE INSERT ON payroll_posting_allocations
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_posting_batches batch
     WHERE batch.supplier_id = NEW.supplier_id
       AND batch.id = NEW.batch_id
       AND batch.status = 'prepared'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll posting allocations require a prepared batch';
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_posting_allocation_immutable_update
BEFORE UPDATE ON payroll_posting_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll posting allocations are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_posting_allocation_immutable_delete
BEFORE DELETE ON payroll_posting_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll posting allocations are append-only';
END//

DELIMITER ;
