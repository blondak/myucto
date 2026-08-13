-- MyÚčto.cz — MZ-22-W01e-d-b: fail-closed a immutable approval eventy.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_time_month_event_jmhz_guard;
DROP TRIGGER IF EXISTS trg_payroll_time_month_event_no_update;
DROP TRIGGER IF EXISTS trg_payroll_time_month_event_no_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_time_month_event_jmhz_guard
BEFORE INSERT ON payroll_time_month_events
FOR EACH ROW
BEGIN
  DECLARE matching_summary_count INT DEFAULT 0;
  DECLARE expected_summary_count INT DEFAULT 0;

  IF NEW.action = 'approved' THEN
    SELECT COUNT(*) INTO expected_summary_count
      FROM payroll_jmhz_work_month_revisions summary
     WHERE summary.supplier_id = NEW.supplier_id
       AND summary.time_month_id = NEW.time_month_id
       AND summary.time_month_revision_no = NEW.revision_no;
  END IF;

  IF NEW.jmhz_work_summary_revision_id IS NOT NULL OR expected_summary_count > 0 THEN
    SELECT COUNT(*) INTO matching_summary_count
      FROM payroll_jmhz_work_month_revisions summary
     WHERE summary.id = NEW.jmhz_work_summary_revision_id
       AND summary.supplier_id = NEW.supplier_id
       AND summary.time_month_id = NEW.time_month_id
       AND summary.time_month_revision_no = NEW.revision_no
       AND summary.summary_sha256 = NEW.jmhz_work_summary_hash;

    IF matching_summary_count <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll time approval event does not match JMHZ summary';
    END IF;
  END IF;
END//

CREATE TRIGGER trg_payroll_time_month_event_no_update
BEFORE UPDATE ON payroll_time_month_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll time month events are immutable';
END//

CREATE TRIGGER trg_payroll_time_month_event_no_delete
BEFORE DELETE ON payroll_time_month_events
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll time month events are immutable';
END//

DELIMITER ;
