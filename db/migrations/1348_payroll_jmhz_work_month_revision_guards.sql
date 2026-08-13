-- MyÚčto.cz — MZ-22-W01e-d-a: integrita immutable měsíční revize JMHZ.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_jmhz_work_month_insert_guard;

DELIMITER //

CREATE TRIGGER trg_payroll_jmhz_work_month_insert_guard
BEFORE INSERT ON payroll_jmhz_work_month_revisions
FOR EACH ROW
BEGIN
  DECLARE matching_months INT DEFAULT 0;

  SELECT COUNT(*) INTO matching_months
    FROM payroll_time_months month_row
   WHERE month_row.supplier_id = NEW.supplier_id
     AND month_row.id = NEW.time_month_id
     AND month_row.employment_id = NEW.employment_id
     AND month_row.period_start = NEW.period_start
     AND month_row.status = 'approved'
     AND month_row.revision_no = NEW.time_month_revision_no;

  IF matching_months <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'JMHZ work summary must match the approved time month revision';
  END IF;
END//

DELIMITER ;
