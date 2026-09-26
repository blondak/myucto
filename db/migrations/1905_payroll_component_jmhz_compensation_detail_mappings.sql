-- MyÚčto.cz — výchozí zařazení náhrad mzdy za svátek a při překážkách v práci.
--
-- Měsíční hlášení má pro náhrady mzdy vlastní kolonky: za svátek (10339), při
-- překážkách na straně zaměstnavatele (10340) a na straně zaměstnance (10341).
-- Obecná složka NAHRADA_MZDY je nerozliší, takže se náhrady vykazovaly jen
-- v úhrnu 10337 a detail zůstával prázdný. Výchozí číselník proto nese tři
-- vlastní složky; zakládá je aplikace sama při čtení číselníku
-- (`PayrollComponentRepository::ensureDefaults()`) i se zařazením.
--
-- Tahle migrace dorovná zařazení u firem, kde složka se stejným kódem už
-- existuje bez zařazení. Pravidlo je totéž jako v
-- `PayrollComponentJmhzMappingDefaults::targetFor()`; shodu obou míst hlídá
-- `PayrollComponentJmhzKindDefaultsMigrationTest`.
--
-- Rozhodnutí účetní se nepřepisuje: složka, která JAKÝKOLI záznam zařazení má
-- (i vědomě deaktivovaný), se přeskočí. `created_by` zůstává NULL, aby bylo
-- poznat, že zařazení vyplnila aplikace. Opakované spuštění nepřidá nic.

SET NAMES utf8mb4;

INSERT INTO payroll_component_jmhz_mappings
    (supplier_id, component_definition_id, spec_package_id, target_attribute_id,
     created_by, updated_by)
SELECT target.supplier_id,
       target.id,
       attribute.package_id,
       attribute.attribute_id,
       NULL,
       NULL
  FROM (
        SELECT definition.supplier_id,
               definition.id,
               by_code.attribute_id
          FROM payroll_component_definitions definition
          JOIN (
                SELECT 'NAHRADA_MZDY_SVATEK' AS code, '10339' AS attribute_id
                UNION ALL SELECT 'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', '10340'
                UNION ALL SELECT 'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', '10341'
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.9_manifest-v1'
   AND package.manifest_sha256 = '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = target.attribute_id
 WHERE target.attribute_id IS NOT NULL
   AND NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = target.supplier_id
            AND existing.component_definition_id = target.id
       );
