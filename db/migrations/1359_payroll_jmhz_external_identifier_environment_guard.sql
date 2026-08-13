-- MyÚčto.cz — MZ-22-W01e-e: identifikátory nelze přesunout mezi test/prod.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_person_external_id_environment_guard;
DROP TRIGGER IF EXISTS trg_payroll_employment_external_id_environment_guard;

DELIMITER $$

CREATE TRIGGER trg_payroll_person_external_id_environment_guard
BEFORE UPDATE ON payroll_person_external_ids
FOR EACH ROW
BEGIN
  IF NOT (OLD.environment <=> NEW.environment) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll person external identifier environment is immutable';
  END IF;
END$$

CREATE TRIGGER trg_payroll_employment_external_id_environment_guard
BEFORE UPDATE ON payroll_employment_external_ids
FOR EACH ROW
BEGIN
  IF NOT (OLD.environment <=> NEW.environment) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment external identifier environment is immutable';
  END IF;
END$$

DELIMITER ;
