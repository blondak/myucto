-- MyÚčto.cz — nákladové analytiky pro auto: PHM a servis vozidel.
--
-- Do teď měl každý tenant jen syntetiky 501 (Spotřeba materiálu) a 511 (Opravy a
-- udržování) a účtovalo se přímo na ně. Účetní chce PHM a servis vozu odděleně,
-- proto se ke každé syntetice zakládá dvojice analytik:
--
--   501.100  PHM — pohonné hmoty        501.900  Ostatní materiál   (nový default)
--   511.100  Servis a opravy vozidel    511.900  Ostatní opravy     (nový default)
--
-- Jakmile syntetika dostane potomky, NESMÍ se na ni dál účtovat (jinak by součet
-- analytik neseděl na syntetiku) — proto vznikají i „zbytkové" .900 analytiky a
-- posting_rules se přesměrovávají na ně. Existující zápisy na holé 501/511 zůstávají;
-- převede je až back-fill skript (api/bin/backfill-vehicle-expenses.php).
--
-- Idempotence: každý INSERT je gate-ovaný NOT EXISTS, UPDATE jsou samy o sobě
-- idempotentní. Migrace je bezpečně opakovatelná.

SET NAMES utf8mb4;

-- 1) Volba analytik v nastavení firmy. NULL = firma analytiku PHM/servisu nepoužívá
--    a účtuje se postaru (default z posting_rules). Vzor sloupce z 1047.
ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS fuel_account_code VARCHAR(10) NULL
    COMMENT 'analytika PHM (např. 501.100); NULL = neurčeno, použije se default materiálu';

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS vehicle_repair_account_code VARCHAR(10) NULL
    COMMENT 'analytika servisu vozidel (např. 511.100); NULL = neurčeno, použije se default oprav';

-- 2) Analytiky pro každého tenanta, který má příslušnou syntetiku v osnově.
--    parent_id = id syntetiky, is_synthetic = 0 (na analytiku se účtuje).
--
--    JOIN na `supplier` není zbytečný: v chart_of_accounts jsou historicky osiřelé osnovy
--    po firmách, které v `supplier` už neexistují (FK je sice ON DELETE CASCADE, ale řádky
--    tam z nějakého dřívějšího stavu zůstaly). Bez toho JOINu by INSERT spadl na
--    fk_coa_supplier. Osiřelým osnovám analytiky zakládat nechceme.
INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
SELECT s.supplier_id, a.code, a.name, 'expense', 'debit', 0, s.id, 1
  FROM chart_of_accounts s
  JOIN supplier sup ON sup.id = s.supplier_id
  JOIN (
        SELECT '501' AS parent, '501.100' AS code, 'PHM — pohonné hmoty' AS name
  UNION SELECT '501',            '501.900',        'Ostatní materiál'
  UNION SELECT '511',            '511.100',        'Servis a opravy vozidel'
  UNION SELECT '511',            '511.900',        'Ostatní opravy a udržování'
       ) a ON a.parent = s.account_code
 WHERE s.parent_id IS NULL
   AND NOT EXISTS (
         SELECT 1 FROM chart_of_accounts x
          WHERE x.supplier_id = s.supplier_id AND x.account_code = a.code
       );

-- 3) Syntetiky, které právě dostaly potomky, označit jako syntetické (účtuje se na analytiky).
--    Derived table kvůli MariaDB omezení „nelze číst z updatované tabulky poddotazem".
UPDATE chart_of_accounts p
  JOIN (SELECT DISTINCT parent_id FROM chart_of_accounts WHERE parent_id IS NOT NULL) k
    ON k.parent_id = p.id
   SET p.is_synthetic = 1
 WHERE p.account_code IN ('501', '511');

-- 4) Předvolba analytik v nastavení — jen tam, kde ještě není nic vybráno.
UPDATE accounting_supplier_settings ass
   SET ass.fuel_account_code = '501.100'
 WHERE ass.fuel_account_code IS NULL
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = ass.supplier_id AND c.account_code = '501.100');

UPDATE accounting_supplier_settings ass
   SET ass.vehicle_repair_account_code = '511.100'
 WHERE ass.vehicle_repair_account_code IS NULL
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = ass.supplier_id AND c.account_code = '511.100');

-- 5) Default druhu výdaje přesměrovat ze syntetiky 501 na zbytkovou analytiku 501.900.
--    Globální pravidla (supplier_id IS NULL) se NEMĚNÍ — platí pro všechny tenanty včetně
--    těch bez analytik. Místo toho vzniká per-tenant override (priority 100, vzor
--    PostingRuleRepository::OVERRIDE_PRIORITY), který v resolve() vyhraje nad globálem.
--
--    POZOR: přesměrovává se `material` I `small_asset` — obojí dnes končí na holé 501,
--    takže tímhle se chování jen posouvá o úroveň níž, nic se nesluje ani nerozděluje.
--    Kdyby chtěla účetní drobný majetek zvlášť (501.200), je to samostatná analytika
--    a samostatný override; tahle migrace to záměrně nepředjímá.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT c.supplier_id, k.rule_key, k.description, '501.900', '321', 100, 1
  FROM chart_of_accounts c
  JOIN (
        SELECT 'invoice.material.received' AS rule_key, 'Materiál — ostatní (analytika 501.900)' AS description
  UNION SELECT 'invoice.small_asset.received',           'Drobný majetek (analytika 501.900)'
       ) k
 WHERE c.account_code = '501.900'
   AND NOT EXISTS (
         SELECT 1 FROM posting_rules p
          WHERE p.supplier_id = c.supplier_id AND p.rule_key = k.rule_key
       );

-- 6) Existující pravidla klasifikace přesměrovat na analytiky (PHM → 501.100,
--    servis vozu → 511.100). Cílíme jen pravidla, která dnes míří na holou syntetiku.
UPDATE expense_classification_rules r
   SET r.target_account_code = '501.100'
 WHERE r.target_account_code = '501'
   AND r.expense_kind = 'material'
   AND (LOWER(r.name) LIKE '%phm%' OR LOWER(r.name) LIKE '%palivo%'
        OR LOWER(r.name) LIKE '%nafta%' OR LOWER(r.name) LIKE '%benzin%')
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = r.supplier_id AND c.account_code = '501.100');

UPDATE expense_classification_rules r
   SET r.target_account_code = '511.100'
 WHERE r.target_account_code = '511'
   AND EXISTS (SELECT 1 FROM chart_of_accounts c
                WHERE c.supplier_id = r.supplier_id AND c.account_code = '511.100');
