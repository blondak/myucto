-- MyÚčto.cz — výchozí zařazení složky „Zdanitelná část stravování" do JMHZ.
--
-- Složka STRAVOVANI_ZDANITELNE přibyla do výchozího číselníku až po migraci
-- 1839, která zařazení dorovnávala. U firem, které si číselník mezitím
-- založily, tedy složka existuje bez zařazení — a bez zařazení nejde zmrazit
-- měsíční hlášení, přestože plnění chodí z importu docházky každý měsíc.
--
-- Cílem je 10328, úhrn zúčtované mzdy: nepeněžní stravování je součástí hrubé
-- mzdy a vstupuje do vyměřovacích základů na sociální i zdravotní pojištění.
-- Detailní uzel pod 10328 pro ně katalog cílů nenabízí (10329 jsou tarifní
-- mzdy, 10330 a 10331 odměny, 10332 příplatky), proto sběrný součet. Obecná
-- složka NEPENEZNI_PRIJEM zařazení záměrně nemá a tahle migrace na ni nesahá:
-- nese i plnění, která do úhrnu zúčtované mzdy nepatří.
--
-- Pravidlo je totéž jako v `PayrollComponentJmhzMappingDefaults::targetFor()`;
-- shodu obou míst hlídá `PayrollComponentJmhzKindDefaultsMigrationTest`.
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
                SELECT 'STRAVOVANI_ZDANITELNE' AS code, '10328' AS attribute_id
               ) by_code ON by_code.code = definition.code
         WHERE definition.jmhz_treatment = 'included'
       ) target
  -- Balík specifikace je ten, který aplikace čte
  -- (`PayrollComponentJmhzTargetCatalog::PACKAGE_KEY`). Zařazení ve starším
  -- balíku by snímek hlášení neviděl a navíc by zablokovalo pozdější zápis
  -- zařazení aplikací („nejprve deaktivujte mapování ze staršího balíku").
  -- Když balík ještě nainstalovaný není, nevloží se nic a zařazení doplní
  -- aplikace sama při založení nebo čtení složek.
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
