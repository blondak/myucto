-- MZ-22-W01e-a: upgrade guardy konzistence režimu složky a aktivního mapování.

DROP TRIGGER IF EXISTS trg_payroll_component_jmhz_mapping_insert_guard;
DROP TRIGGER IF EXISTS trg_payroll_component_jmhz_mapping_update_guard;
DROP TRIGGER IF EXISTS trg_payroll_component_jmhz_treatment_update_guard;

DELIMITER $$

CREATE TRIGGER trg_payroll_component_jmhz_mapping_insert_guard
BEFORE INSERT ON payroll_component_jmhz_mappings
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM payroll_component_definitions component
     WHERE component.supplier_id = NEW.supplier_id
       AND component.id = NEW.component_definition_id
       AND component.jmhz_treatment = 'included'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Active JMHZ mapping requires an included payroll component';
  END IF;
END$$

CREATE TRIGGER trg_payroll_component_jmhz_mapping_update_guard
BEFORE UPDATE ON payroll_component_jmhz_mappings
FOR EACH ROW
BEGIN
  IF NEW.is_active = 1 AND NOT EXISTS (
    SELECT 1 FROM payroll_component_definitions component
     WHERE component.supplier_id = NEW.supplier_id
       AND component.id = NEW.component_definition_id
       AND component.jmhz_treatment = 'included'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Active JMHZ mapping requires an included payroll component';
  END IF;
END$$

CREATE TRIGGER trg_payroll_component_jmhz_treatment_update_guard
BEFORE UPDATE ON payroll_component_definitions
FOR EACH ROW
BEGIN
  IF NEW.jmhz_treatment <> 'included' AND EXISTS (
    SELECT 1 FROM payroll_component_jmhz_mappings mapping
     WHERE mapping.supplier_id = NEW.supplier_id
       AND mapping.component_definition_id = NEW.id
       AND mapping.is_active = 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Disable active JMHZ mapping before changing component treatment';
  END IF;
END$$

DELIMITER ;
