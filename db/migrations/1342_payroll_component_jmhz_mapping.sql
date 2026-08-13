-- MyÚčto.cz — MZ-22-W01e-a: explicitní mapování mzdové složky na atribut JMHZ.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_component_jmhz_mappings (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  component_definition_id  BIGINT UNSIGNED NOT NULL,
  spec_package_id          BIGINT UNSIGNED NOT NULL,
  target_attribute_id      VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_by               BIGINT UNSIGNED NULL,
  updated_by               BIGINT UNSIGNED NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_component_jmhz_mapping
    (supplier_id, component_definition_id, spec_package_id),
  UNIQUE KEY uq_payroll_component_jmhz_mapping_tenant_id (supplier_id, id),
  CONSTRAINT fk_payroll_component_jmhz_mapping_component
    FOREIGN KEY (supplier_id, component_definition_id)
    REFERENCES payroll_component_definitions(supplier_id, id)
    ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_payroll_component_jmhz_mapping_attribute
    FOREIGN KEY (spec_package_id, target_attribute_id)
    REFERENCES payroll_jmhz_dictionary_attributes(package_id, attribute_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_component_jmhz_mapping_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_component_jmhz_mapping_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_component_jmhz_mapping_version CHECK (row_version > 0),
  CONSTRAINT chk_payroll_component_jmhz_mapping_target CHECK (
    target_attribute_id IN (
      '10328','10329','10330','10331','10332','10333','10334','10335','10336',
      '10337','10338','10339','10340','10341','10342','10343','10417'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
