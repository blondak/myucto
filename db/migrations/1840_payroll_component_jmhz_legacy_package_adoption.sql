-- MyÚčto.cz — zařazení mzdových složek do JMHZ přechází do aktuálního balíku
-- specifikace.
--
-- Zařazení je vázané na balík specifikace. Snímek měsíčního hlášení čte jen
-- balík, který aplikace používá (`PayrollComponentJmhzTargetCatalog::PACKAGE_KEY`),
-- obrazovka zařazení ale ukazuje i aktivní zařazení ze staršího balíku. Firma,
-- která zařazovala nad starším balíkem, proto po přechodu na nový balík viděla
-- složky zařazené, příprava hlášení je blokovala jako nezařazené a migrace 1839
-- ani aplikace výchozí zařazení nedoplnily, protože složka záznam už měla.
--
-- Pravidlo je doslova `PayrollComponentJmhzMappingRepository::adoptLegacy()`;
-- shodu obou míst hlídá `PayrollComponentJmhzLegacyPackageAdoptionTest`.
--  - Bere se rozhodnutí, které ukazuje obrazovka zařazení: aktivní zařazení
--    z nejnovějšího staršího balíku, jinak nejnovější deaktivované.
--  - Převádí se jen tam, kde v aktuálním balíku záznam ještě není a kde
--    aktuální balík cílový atribut zná. Jinak zůstane starší zařazení, jak je,
--    a složka se dál hlásí jako nezařazená.
--  - Deaktivované zařazení se převede jako deaktivované (vědomé „nezařazovat").
--  - Autor zůstává, takže předvyplnění aplikací se dál pozná podle prázdného
--    `created_by`.
--  - Aktivní starší zařazení se deaktivuje všude, kde už aktuální balík záznam
--    má; jinak by blokovalo zápis zařazení i změnu zacházení složky.
--
-- Když aktuální balík ještě nainstalovaný není, neudělá se nic a převzetí
-- provede aplikace sama při čtení složek. Opakované spuštění nic nemění.

SET NAMES utf8mb4;

INSERT INTO payroll_component_jmhz_mappings
    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
     is_active, disabled_at, created_by, updated_by)
SELECT legacy.supplier_id,
       legacy.component_definition_id,
       attribute.package_id,
       attribute.attribute_id,
       legacy.is_active,
       legacy.disabled_at,
       legacy.created_by,
       legacy.updated_by
  FROM (
        SELECT mapping.supplier_id,
               mapping.component_definition_id,
               mapping.spec_package_id,
               mapping.target_attribute_id,
               mapping.is_active,
               mapping.disabled_at,
               mapping.created_by,
               mapping.updated_by,
               ROW_NUMBER() OVER (
                 PARTITION BY mapping.supplier_id, mapping.component_definition_id
                 ORDER BY mapping.is_active DESC, mapping.spec_package_id DESC
               ) AS pick
          FROM payroll_component_jmhz_mappings mapping
       ) legacy
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.9_manifest-v1'
   AND package.manifest_sha256 = '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3'
  JOIN payroll_component_definitions definition
    ON definition.supplier_id = legacy.supplier_id
   AND definition.id = legacy.component_definition_id
   AND definition.jmhz_treatment = 'included'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = legacy.target_attribute_id
 WHERE legacy.pick = 1
   AND legacy.spec_package_id <> package.id
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = legacy.supplier_id
            AND existing.component_definition_id = legacy.component_definition_id
            AND existing.spec_package_id = package.id
       );

UPDATE payroll_component_jmhz_mappings legacy
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.9_manifest-v1'
   AND package.manifest_sha256 = '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3'
  JOIN payroll_component_jmhz_mappings current_mapping
    ON current_mapping.supplier_id = legacy.supplier_id
   AND current_mapping.component_definition_id = legacy.component_definition_id
   AND current_mapping.spec_package_id = package.id
   SET legacy.is_active = 0,
       legacy.disabled_at = CURRENT_TIMESTAMP,
       legacy.updated_by = NULL,
       legacy.row_version = legacy.row_version + 1
 WHERE legacy.spec_package_id <> package.id
   AND legacy.is_active = 1;
