-- MyÚčto.cz — sjednaná sazba odměny za pracovní pohotovost (§ 140 ZP).
--
-- Odměna za pohotovost náleží nejméně ve výši 10 % průměrného výdělku; zákonné
-- minimum drží sada pravidel (`surcharge.standby.rate`). Vyšší sjednaná sazba
-- je vlastnost pracovního vztahu, a proto bydlí vedle sjednaných sazeb
-- příplatků § 114 až § 118 v téže verzované zásadě.
--
-- NULL = nic sjednáno, platí zákonné minimum. Podlahu proti zákonu hlídá
-- služba při uložení (závisí na sadě pravidel účinné k datu zásady), CHECK tu
-- drží jen rozsah proti překlepu o řád: 1 až 50 000 bázových bodů (0,01 % až 500 %).

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_surcharge_policies
  ADD COLUMN IF NOT EXISTS standby_rate_bp SMALLINT UNSIGNED NULL
    AFTER difficult_environment_rate_bp;

ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_standby_rate;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_standby_rate
  CHECK (standby_rate_bp IS NULL OR standby_rate_bp BETWEEN 1 AND 50000);

-- Výchozí zařazení složky ODMENA_POHOTOVOST do JMHZ (10343, blok
-- `odmeny/pohotovost`). Složku zakládá aplikace sama při čtení číselníku
-- (`PayrollComponentRepository::ensureDefaults()`) i se zařazením; tohle dorovná
-- firmy, kde složka se stejným kódem už existuje bez zařazení. Pravidlo je
-- totéž jako v `PayrollComponentJmhzMappingDefaults::targetFor()`; shodu hlídá
-- `PayrollComponentJmhzKindDefaultsMigrationTest`. Rozhodnutí účetní se
-- nepřepisuje a opakované spuštění nepřidá nic.
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
                SELECT 'ODMENA_POHOTOVOST' AS code, '10343' AS attribute_id
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  JOIN payroll_jmhz_spec_packages package
    ON package.package_key = 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.10_manifest-v1'
   AND package.manifest_sha256 = 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d'
  JOIN payroll_jmhz_dictionary_attributes attribute
    ON attribute.package_id = package.id
   AND attribute.attribute_id = target.attribute_id
 WHERE NOT EXISTS (
         SELECT 1
           FROM payroll_component_jmhz_mappings existing
          WHERE existing.supplier_id = target.supplier_id
            AND existing.component_definition_id = target.id
       );
