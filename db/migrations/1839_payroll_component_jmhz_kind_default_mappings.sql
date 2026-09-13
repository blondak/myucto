-- MyÚčto.cz — výchozí zařazení mzdových složek do JMHZ i u firem založených
-- po migraci 1730 a u složek, které nejsou ve výchozím číselníku.
--
-- Migrace 1730 doplnila zařazení jen firmám, které v tu chvíli existovaly.
-- Výchozí číselník, který si aplikace zakládá sama, pak u nových firem vznikal
-- BEZ zařazení a doplnilo se až při otevření obrazovky zařazení nebo přípravě
-- hlášení. Kontrola před mzdovým během proto hlásila „složka nemá zařazení"
-- u Základní měsíční mzdy, Úkolové mzdy i Odměny, a u složek, které založil
-- import docházky podle profilu, zařazení nevzniklo vůbec.
--
-- Pravidlo je doslova `PayrollComponentJmhzMappingDefaults::targetFor()`:
-- nejdřív kód výchozího číselníku, pak druh složky. Shodu obou míst hlídá
-- `PayrollComponentJmhzKindDefaultsMigrationTest`. Druhy, u kterých je
-- zařazení úsudek účetní (`other` — složky „podle hlavičky" z importu,
-- `commission`, `allowance`, benefity, …), se nezařazují.
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
               COALESCE(
                 by_code.attribute_id,
                 CASE
                   WHEN definition.component_kind IN ('base_wage', 'hourly_wage', 'task_wage') THEN '10329'
                   WHEN definition.component_kind = 'premium' THEN '10332'
                   WHEN definition.component_kind = 'compensation'
                    AND definition.tax_treatment = 'included' THEN '10337'
                   WHEN definition.component_kind = 'bonus'
                    AND definition.frequency_kind = 'one_off' THEN '10331'
                   WHEN definition.component_kind = 'bonus'
                    AND definition.frequency_kind = 'regular' THEN '10330'
                 END
               ) AS attribute_id
          FROM payroll_component_definitions definition
          LEFT JOIN (
                SELECT 'MZDA_MESICNI' AS code, '10329' AS attribute_id
          UNION SELECT 'MZDA_HODINOVA', '10329'
          UNION SELECT 'MZDA_UKOLOVA', '10329'
          UNION SELECT 'ODMENA', '10331'
          UNION SELECT 'PREMIE_PRIPLATKY', '10332'
          UNION SELECT 'PRIPLATEK_PRESCAS', '10333'
          UNION SELECT 'PRIPLATEK_NOCNI', '10334'
          UNION SELECT 'PRIPLATEK_VIKEND', '10335'
          UNION SELECT 'PRIPLATEK_SVATEK', '10336'
          UNION SELECT 'PRIPLATEK_ZTIZENE_PROSTREDI', '10332'
          UNION SELECT 'NAHRADA_MZDY', '10337'
          UNION SELECT 'NAHRADA_MZDY_DOVOLENA', '10338'
          UNION SELECT 'NAHRADA_MZDY_DPN', '10342'
          UNION SELECT 'PRISPEVEK_DLOUHODOBA_PECE', '10418'
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
