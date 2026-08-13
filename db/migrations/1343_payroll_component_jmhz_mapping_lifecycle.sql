-- MZ-22-W01e-a: auditovatelný lifecycle mapování a úplný katalog peněžních cílů.

ALTER TABLE payroll_component_jmhz_mappings
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER target_attribute_id,
  ADD COLUMN IF NOT EXISTS disabled_at TIMESTAMP NULL AFTER is_active;

ALTER TABLE payroll_component_jmhz_mappings
  DROP FOREIGN KEY IF EXISTS fk_payroll_component_jmhz_mapping_component,
  DROP CONSTRAINT IF EXISTS chk_payroll_component_jmhz_mapping_target,
  DROP CONSTRAINT IF EXISTS chk_payroll_component_jmhz_mapping_lifecycle;

ALTER TABLE payroll_component_jmhz_mappings
  ADD CONSTRAINT fk_payroll_component_jmhz_mapping_component
    FOREIGN KEY (supplier_id, component_definition_id)
    REFERENCES payroll_component_definitions(supplier_id, id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  ADD CONSTRAINT chk_payroll_component_jmhz_mapping_target CHECK (
    target_attribute_id IN (
      '10328','10329','10330','10331','10332','10333','10334','10335','10336',
      '10337','10338','10339','10340','10341','10342','10343','10417'
    )
  ),
  ADD CONSTRAINT chk_payroll_component_jmhz_mapping_lifecycle CHECK (
    (is_active = 1 AND disabled_at IS NULL)
    OR (is_active = 0 AND disabled_at IS NOT NULL)
  );

DELIMITER $$

CREATE TRIGGER IF NOT EXISTS trg_payroll_component_jmhz_mapping_insert_guard
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

CREATE TRIGGER IF NOT EXISTS trg_payroll_component_jmhz_mapping_update_guard
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

CREATE TRIGGER IF NOT EXISTS trg_payroll_component_jmhz_treatment_update_guard
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
