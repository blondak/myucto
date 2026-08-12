-- ==========================================================================
-- 1323 — analytiky DPH 343.100 / 343.200 / 343.900
-- ==========================================================================
-- Do teď každá daňová noha mířila na holé 343. Na jednom saldním účtu se ale
-- daň na vstupu a na výstupu okamžitě vzájemně vynetuje, takže:
--   * zůstatek 343 v průběhu období neříká nic ani o odpočtu, ani o závazku,
--   * úhrada na FÚ padne do téže hromady jako plnění období, takže zůstatek
--     účtu nejde srovnat se saldem u správce daně,
--   * a hlavně: nejde udělat doklad, kterým účetní na konci období převádí daň
--     období na zúčtovací účet (dělá ho ke KAŽDÉMU zdaňovacímu období).
--
-- Nově tedy:
--   343.100  Daň z přidané hodnoty vstup       (nárok na odpočet, obvykle MD)
--   343.200  Daň z přidané hodnoty výstup      (povinnost přiznat, obvykle D)
--   343.900  Daň z přidané hodnoty zúčtování   (saldo vůči FÚ)
--
-- Interní doklad na konci období (VatClearingService, migrace 1324):
--   MD 343.200 / D 343.900     MD 343.900 / D 343.100
-- Po něm jsou vstup i výstup za období nulové a na 343.900 leží přesně to, co
-- se odvádí — bankovní úhrada (kontace `vat.payment`) ho pak vynuluje.
--
-- ⚠️ ANALYTIKY DOSTÁVÁ KAŽDÝ TENANT, KTERÝ MÁ 343. Bez toho by se globální
--    kontace níž odkazovaly na účet, který u tenanta neexistuje, a postDocument
--    by spadl na `unknown_account`. Firmy v daňové evidenci osnovu nemají, takže
--    se jich to netýká.
--
-- ⚠️ SYNTETIKA 343 ZŮSTÁVÁ AKTIVNÍ. Historie na ní visí a ruční zápisy na holé
--    343 musí dál projít; is_synthetic se nastavuje jen jako informace pro UI.
--    Tenant, který chce zůstat na plochém účtu, si přepíše kontace níž zpět na
--    '343' a chování se vrátí do stavu před touhle migrací.
--
-- Idempotence: INSERTy jsou gate-ované NOT EXISTS, UPDATE jsou podmíněné
-- konkrétní starou hodnotou. Opakovaný běh nemá co dělat.

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
-- 1) Analytiky do osnovy každému tenantovi, který má syntetiku 343.
--    JOIN na `supplier` — v chart_of_accounts jsou historicky osiřelé osnovy
--    po smazaných firmách; bez JOINu by INSERT spadl na fk_coa_supplier
--    (stejný důvod jako v migraci 1127).
--    normal_side = NULL (saldní účet): každá z analytik může být z principu na
--    obou stranách (dobropis, opravný DDKP, nadměrný odpočet).
-- --------------------------------------------------------------------------
INSERT INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
SELECT s.supplier_id, a.code, a.name, s.account_type, NULL, 0, s.id, 1
  FROM chart_of_accounts s
  JOIN supplier sup ON sup.id = s.supplier_id
  JOIN (
        SELECT '343.100' AS code, 'Daň z přidané hodnoty vstup' AS name
  UNION SELECT '343.200',          'Daň z přidané hodnoty výstup'
  UNION SELECT '343.900',          'Daň z přidané hodnoty zúčtování'
       ) a
 WHERE s.account_code = '343'
   AND s.parent_id IS NULL
   AND NOT EXISTS (
         SELECT 1 FROM (SELECT supplier_id, account_code FROM chart_of_accounts) x
          WHERE x.supplier_id = s.supplier_id AND x.account_code = a.code
       );

-- 2) 343 právě dostalo potomky → označit jako syntetiku (informace pro UI/výkazy).
UPDATE chart_of_accounts p
  JOIN (SELECT DISTINCT parent_id FROM chart_of_accounts WHERE parent_id IS NOT NULL) k
    ON k.parent_id = p.id
   SET p.is_synthetic = 1
 WHERE p.account_code = '343';

-- --------------------------------------------------------------------------
-- 3) Nové globální kontace pro daňové nohy dokladů. Pojmenování kopíruje
--    stávající `invoice.services.issued` / `invoice.services.received`:
--    v páru je vždy daňový účet a jeho typický protiúčet, aby pravidlo dávalo
--    smysl i v UI kontací.
--    Globální (supplier_id NULL) je tady bezpečné právě proto, že krok 1 dal
--    analytiky VŠEM tenantům s osnovou.
-- --------------------------------------------------------------------------
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'invoice.vat.output', 'DPH na výstupu (analytika 343.200)', '311', '343.200', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'invoice.vat.output');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'invoice.vat.input', 'DPH na vstupu — nárok na odpočet (analytika 343.100)', '343.100', '321', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'invoice.vat.input');

-- 4) Kontace zúčtovacího dokladu — obě jsou přesně MD/D pár jednoho jeho řádku.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'vat.clearing.output', 'Zúčtování DPH — daň na výstupu (343.200/343.900)', '343.200', '343.900', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'vat.clearing.output');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'vat.clearing.input', 'Zúčtování DPH — daň na vstupu (343.900/343.100)', '343.900', '343.100', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'vat.clearing.input');

-- --------------------------------------------------------------------------
-- 5) Stávající globální kontace přesměrovat ze syntetiky na analytiku.
--    Per-tenant override (supplier_id NOT NULL) se ZÁMĚRNĚ nesahá — kdo si účet
--    vědomě přepsal, má mít pořád svůj.
--
--    DDKP (daňový doklad k záloze): vydaná strana přiznává daň → výstup,
--    přijatá uplatňuje odpočet → vstup.
-- --------------------------------------------------------------------------
UPDATE posting_rules
   SET credit_account_code = '343.200'
 WHERE supplier_id IS NULL AND rule_key = 'advance.received.vatdocument' AND credit_account_code = '343';

UPDATE posting_rules
   SET debit_account_code = '343.100'
 WHERE supplier_id IS NULL AND rule_key = 'advance.paid.vatdocument' AND debit_account_code = '343';

--    Peněžní vypořádání s FÚ jde proti ZÚČTOVACÍMU účtu — tam po interním
--    dokladu leží přesně odváděná (resp. vracená) částka.
UPDATE posting_rules
   SET debit_account_code = '343.900'
 WHERE supplier_id IS NULL AND rule_key IN ('vat.payment', 'vat.settlement.liability') AND debit_account_code = '343';

UPDATE posting_rules
   SET credit_account_code = '343.900'
 WHERE supplier_id IS NULL AND rule_key = 'vat.settlement.excess' AND credit_account_code = '343';
