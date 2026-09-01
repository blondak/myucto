-- Mzdové dimenze: overlap triggery nesmějí míchat kolaci tabulky s výchozí
-- kolací lokální proměnné databáze. Typ nové dimenze porovnáváme přímo mezi
-- dvěma aliasy payroll_dimensions, takže INSERT i UPDATE fungují nezávisle na
-- serverovém/database defaultu.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_insert//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_insert
BEFORE INSERT ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_dimensions selected_dimension
     WHERE selected_dimension.supplier_id = NEW.supplier_id
       AND selected_dimension.id = NEW.dimension_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions existing_dimension
        ON existing_dimension.supplier_id = ed.supplier_id
       AND existing_dimension.id = ed.dimension_id
      JOIN payroll_dimensions selected_dimension
        ON selected_dimension.supplier_id = NEW.supplier_id
       AND selected_dimension.id = NEW.dimension_id
       AND selected_dimension.dimension_type
           = existing_dimension.dimension_type
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_update//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_update
BEFORE UPDATE ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension row version must increase';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM payroll_dimensions selected_dimension
     WHERE selected_dimension.supplier_id = NEW.supplier_id
       AND selected_dimension.id = NEW.dimension_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions existing_dimension
        ON existing_dimension.supplier_id = ed.supplier_id
       AND existing_dimension.id = ed.dimension_id
      JOIN payroll_dimensions selected_dimension
        ON selected_dimension.supplier_id = NEW.supplier_id
       AND selected_dimension.id = NEW.dimension_id
       AND selected_dimension.dimension_type
           = existing_dimension.dimension_type
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND ed.id <> NEW.id
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DELIMITER ;
