-- MyÚčto.cz — MZ-14: oprava unikátnosti a finančních invariantů exekučního ledgeru.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_claims
  DROP INDEX IF EXISTS uq_payroll_enforcement_claim_key,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_enforcement_claim_key
    (supplier_id, claim_key);

ALTER TABLE payroll_enforcement_allocations
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_allocation_result;

ALTER TABLE payroll_enforcement_ledger
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_ledger_result;

ALTER TABLE payroll_enforcement_month_results
  ADD COLUMN IF NOT EXISTS revision_scope_id BIGINT UNSIGNED
    AS (IFNULL(revision_id, 0)) PERSISTENT AFTER revision_id,
  DROP INDEX IF EXISTS uq_payroll_enforcement_result_revision,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_enforcement_result_revision
    (supplier_id, revision_scope_id, period_start, employee_id);

ALTER TABLE payroll_enforcement_ledger
  ADD COLUMN IF NOT EXISTS calculation_entry_key VARCHAR(100)
    AS (
      IF(
        entry_kind IN ('withheld','held','employer_fee'),
        CONCAT(entry_kind, ':', IFNULL(case_id, 0), ':', IFNULL(claim_id, 0)),
        NULL
      )
    ) PERSISTENT AFTER amount_minor_units,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_enforcement_ledger_calculation_entry
    (supplier_id, month_result_id, calculation_entry_key),
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_ledger_amount,
  ADD CONSTRAINT chk_payroll_enforcement_ledger_amount
    CHECK (
      (entry_kind = 'adjustment' AND amount_minor_units <> 0)
      OR (entry_kind <> 'adjustment' AND amount_minor_units > 0)
    ),
  ADD CONSTRAINT IF NOT EXISTS chk_payroll_enforcement_ledger_owner
    CHECK (
      (entry_kind = 'employer_fee' AND case_id IS NULL AND claim_id IS NULL)
      OR (
        entry_kind IN ('withheld','held','remitted','released_to_employee')
        AND ((case_id IS NULL AND claim_id IS NULL)
          OR (case_id IS NOT NULL AND claim_id IS NOT NULL))
      )
      OR (
        entry_kind = 'adjustment'
        AND (claim_id IS NULL OR case_id IS NOT NULL)
      )
    );

ALTER TABLE payroll_enforcement_allocations
  ADD CONSTRAINT fk_payroll_enforcement_allocation_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id)
    ON DELETE RESTRICT;

ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT fk_payroll_enforcement_ledger_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id)
    ON DELETE RESTRICT;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_case_immutable_delete
BEFORE DELETE ON payroll_enforcement_cases
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement cases cannot be hard-deleted';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_enforcement_claim_immutable_delete
BEFORE DELETE ON payroll_enforcement_claims
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement claims cannot be hard-deleted';
END//

DROP TRIGGER IF EXISTS trg_payroll_enforcement_ledger_consistency_insert//

CREATE TRIGGER trg_payroll_enforcement_ledger_consistency_insert
BEFORE INSERT ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  DECLARE allocation_total BIGINT DEFAULT NULL;
  DECLARE already_disposed BIGINT DEFAULT 0;
  DECLARE result_fee BIGINT DEFAULT NULL;

  IF NEW.entry_kind = 'employer_fee' THEN
    SELECT employer_fee_minor_units
      INTO result_fee
      FROM payroll_enforcement_month_results
     WHERE supplier_id = NEW.supplier_id
       AND id = NEW.month_result_id;

    IF result_fee IS NULL OR NEW.amount_minor_units <> result_fee THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement employer fee does not match result';
    END IF;
  ELSEIF NEW.entry_kind IN ('withheld','held','remitted','released_to_employee') THEN
    SELECT total_minor_units
      INTO allocation_total
      FROM payroll_enforcement_allocations
     WHERE supplier_id = NEW.supplier_id
       AND month_result_id = NEW.month_result_id
       AND case_id <=> NEW.case_id
       AND claim_id <=> NEW.claim_id
     LIMIT 1;

    IF allocation_total IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement ledger has no matching allocation';
    END IF;

    IF NEW.entry_kind IN ('withheld','held')
       AND NEW.amount_minor_units <> allocation_total THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement calculation entry differs from allocation';
    END IF;

    IF NEW.entry_kind IN ('remitted','released_to_employee') THEN
      SELECT COALESCE(SUM(amount_minor_units), 0)
        INTO already_disposed
        FROM payroll_enforcement_ledger
       WHERE supplier_id = NEW.supplier_id
         AND month_result_id = NEW.month_result_id
         AND case_id <=> NEW.case_id
         AND claim_id <=> NEW.claim_id
         AND entry_kind IN ('remitted','released_to_employee');

      IF already_disposed + NEW.amount_minor_units > allocation_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement allocation is over-remitted';
      END IF;
    END IF;
  ELSEIF NEW.entry_kind = 'adjustment' AND NEW.actor_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement adjustment requires an actor';
  END IF;
END//

DELIMITER ;
