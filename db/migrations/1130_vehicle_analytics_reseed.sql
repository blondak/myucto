-- MyÚčto.cz — doseed analytik PHM/servis (501.x, 511.x) pro už aktivované firmy.
--
-- PROČ ZNOVU, když to dělala 1127: 1127 byla čistě backfill nad EXISTUJÍCÍMI tenanty.
-- Na čerstvém nasazení (setup wizard zakládá firmu až PO migracích) neměla co potkat
-- a proběhla jako no-op — a protože je evidovaná v `migrations`, už se nespustí. Firma
-- založená po migraci tak analytiky nikdy nedostala. Stejně dopadl každý `reset.php`.
--
-- SYSTÉMOVÁ OPRAVA je v kódu: účty jsou nově v ChartOfAccountsTemplate a override
-- předkontace zakládá ChartOfAccountsSeeder::seedAnalyticPostingRules(), takže KAŽDÁ
-- nově aktivovaná firma je dostane sama. Tahle migrace je jen doručení téhož do firem,
-- které účetnictví aktivovaly DŘÍV (seeder se jim už znovu nespustí).
-- Viz db/migrations/README-post-setup.md.
--
-- Idempotentní: každý krok je gate-ovaný NOT EXISTS / IS NULL, bezpečně opakovatelné.
-- Na nasazení bez firmy je to no-op — a to je v pořádku, tam nastupuje šablona v kódu.

SET NAMES utf8mb4;

-- 1) Analytiky pod 501 a 511 pro každou firmu, která má příslušnou syntetiku.
INSERT INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
SELECT p.supplier_id, x.code, x.name, 'expense', 'debit', 0, p.id, 1
  FROM chart_of_accounts p
  JOIN (
            SELECT '501' AS parent, '501.100' AS code, 'PHM — pohonné hmoty'     AS name
      UNION SELECT '501',           '501.900',         'Ostatní materiál'
      UNION SELECT '511',           '511.100',         'Servis a opravy vozidel'
      UNION SELECT '511',           '511.900',         'Ostatní opravy'
       ) x ON x.parent = p.account_code
 WHERE NOT EXISTS (
         SELECT 1 FROM chart_of_accounts c
          WHERE c.supplier_id = p.supplier_id AND c.account_code = x.code
       );

-- 2) Syntetika, která právě dostala potomky, zůstává syntetická (účtuje se na analytiky).
--    Derived table kvůli MariaDB omezení „nelze číst z updatované tabulky poddotazem".
UPDATE chart_of_accounts p
  JOIN (SELECT DISTINCT parent_id FROM chart_of_accounts WHERE parent_id IS NOT NULL) k
    ON k.parent_id = p.id
   SET p.is_synthetic = 1
 WHERE p.account_code IN ('501', '511');

-- 3) Override předkontace na zbytkovou 501.900. Globální pravidla (supplier_id IS NULL)
--    se ZÁMĚRNĚ nemění — platí pro firmy bez analytik. priority 100 = OVERRIDE_PRIORITY
--    (PostingRuleRepository), takže resolve() vybere override nad globálem.
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

-- 4) Předvolba analytik v nastavení — jen kde si firma ještě nic nevybrala.
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
