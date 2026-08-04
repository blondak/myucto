-- MyÚčto.cz — MZ-17: ověření zaměstnaneckých platebních cílů.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_accounts
  ADD COLUMN IF NOT EXISTS verification_source
    ENUM('employee_confirmation','bank_document','user_verified') NULL
    AFTER row_version,
  ADD COLUMN IF NOT EXISTS verified_on DATE NULL
    AFTER verification_source,
  ADD COLUMN IF NOT EXISTS verified_by BIGINT UNSIGNED NULL
    AFTER verified_on,
  ADD KEY IF NOT EXISTS idx_payroll_person_account_verified_by (verified_by),
  ADD CONSTRAINT fk_payroll_person_account_verified_by
    FOREIGN KEY IF NOT EXISTS (verified_by)
    REFERENCES users (id) ON DELETE SET NULL;

DROP TRIGGER IF EXISTS trg_payroll_payment_liability_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_person_account_verify_insert;
DROP TRIGGER IF EXISTS trg_payroll_person_account_verify_update;

DELIMITER //

CREATE TRIGGER trg_payroll_person_account_verify_insert
BEFORE INSERT ON payroll_person_accounts
FOR EACH ROW
BEGIN
  IF NOT (
    (
      NEW.verification_source IS NULL
      AND NEW.verified_on IS NULL
      AND NEW.verified_by IS NULL
    )
    OR
    (
      NEW.verification_source IS NOT NULL
      AND NEW.verified_on IS NOT NULL
      AND NEW.verified_by IS NOT NULL
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll person account verification is incomplete';
  END IF;
END//

CREATE TRIGGER trg_payroll_person_account_verify_update
BEFORE UPDATE ON payroll_person_accounts
FOR EACH ROW
BEGIN
  IF NOT (
    NEW.bank_account_ciphertext <=> OLD.bank_account_ciphertext
    AND NEW.bank_account_hash <=> OLD.bank_account_hash
    AND NEW.effective_from <=> OLD.effective_from
    AND NEW.effective_to <=> OLD.effective_to
    AND NEW.is_active <=> OLD.is_active
  ) THEN
    SET NEW.verification_source = NULL;
    SET NEW.verified_on = NULL;
    SET NEW.verified_by = NULL;
  END IF;

  IF NOT (
    (
      NEW.verification_source IS NULL
      AND NEW.verified_on IS NULL
      AND NEW.verified_by IS NULL
    )
    OR
    (
      NEW.verification_source IS NOT NULL
      AND NEW.verified_on IS NOT NULL
      AND NEW.verified_by IS NOT NULL
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll person account verification is incomplete';
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_liability_validate_insert
BEFORE INSERT ON payroll_payment_liabilities
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.revision_id
       AND revision.status = 'approved'
       AND revision.result_snapshot_hash IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability requires the current approved revision';
  END IF;

  IF NEW.employee_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_run_persons person_result
     WHERE person_result.supplier_id = NEW.supplier_id
       AND person_result.revision_id = NEW.revision_id
       AND person_result.employee_id = NEW.employee_id
       AND person_result.status = 'calculated'
       AND person_result.result_hash IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employee liability requires a calculated person result';
  END IF;

  IF NEW.previous_liability_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_payment_liabilities previous_liability
      JOIN payroll_run_revisions previous_revision
        ON previous_revision.supplier_id = previous_liability.supplier_id
       AND previous_revision.id = previous_liability.revision_id
      JOIN payroll_run_revisions current_revision
        ON current_revision.supplier_id = NEW.supplier_id
       AND current_revision.id = NEW.revision_id
     WHERE previous_liability.supplier_id = NEW.supplier_id
       AND previous_liability.id = NEW.previous_liability_id
       AND previous_liability.liability_reference = NEW.liability_reference
       AND previous_liability.liability_kind = NEW.liability_kind
       AND previous_revision.run_id = current_revision.run_id
       AND current_revision.revision_no > previous_revision.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability correction chain is inconsistent';
  END IF;
END//

DELIMITER ;
