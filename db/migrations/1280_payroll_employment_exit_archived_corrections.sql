-- MyÚčto.cz — výstupní dokumenty po doložené archivaci pracovního vztahu.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_employment_exit_revision_validate_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_employment_exit_revision_validate_insert
BEFORE INSERT ON payroll_employment_exit_revisions
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_employments employment
     WHERE employment.supplier_id = NEW.supplier_id
       AND employment.id = NEW.employment_id
       AND employment.employee_id = NEW.employee_id
       AND employment.end_date = NEW.employment_end_date
       AND (
         employment.status = 'ended'
         OR (
           employment.status = 'archived'
           AND EXISTS (
             SELECT 1
               FROM payroll_employment_events lifecycle
              WHERE lifecycle.supplier_id = employment.supplier_id
                AND lifecycle.employment_id = employment.id
                AND lifecycle.event_type = 'status_changed'
                AND lifecycle.to_status = 'ended'
                AND lifecycle.effective_on = NEW.employment_end_date
           )
         )
       )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employment exit revision requires the matching ended employment';
  END IF;

  IF NEW.previous_revision_id IS NULL THEN
    IF NEW.revision_no <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'First employment exit revision must have revision number 1';
    END IF;
  ELSEIF NOT EXISTS (
    SELECT 1
      FROM payroll_employment_exit_revisions previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.previous_revision_id
       AND previous.employee_id = NEW.employee_id
       AND previous.employment_id = NEW.employment_id
       AND previous.purpose = NEW.purpose
       AND previous.employment_end_date = NEW.employment_end_date
       AND previous.revision_no + 1 = NEW.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employment exit revision chain is inconsistent';
  END IF;
END//

DELIMITER ;
