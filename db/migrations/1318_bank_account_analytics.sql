-- MyÚčto.cz — každý bankovní účet firmy má vlastní analytiku 221xxx.
--
-- PROBLÉM: `analytic_suffix` byl volitelný a plnil se ručně, takže v praxi ho měly jen
-- cizoměnové účty (migrace 1109). Všechny ostatní účty se účtovaly na PLOCHOU syntetiku
-- 221. Zůstatek 221 pak neodpovídá žádnému reálnému výpisu, inventarizace k rozvahovému
-- dni (§ 29/30 ZoÚ) se nedá doložit „výpisem k datu" a jakýkoli převod mezi vlastními
-- účty je na syntetice neviditelný (obě nohy padnou na týž účet).
--
-- ŘEŠENÍ: suffix má KAŽDÝ aktivní bankovní účet. Přiděluje ho BankAnalyticAssigner
-- (api/src/Service/Accounting/Bank) a tahle migrace dělá tentýž výpočet pro účty, které
-- už existují.
--
-- POŘADÍ PŘIDĚLOVÁNÍ (shodné s BankAnalyticAssigner::candidateSuffixes()):
--   tier 1 — násobky sta   100, 200 … 900   → 221100, 221200 …
--   tier 2 — násobky deseti 010, 020 … 990
--   tier 3 — zbytek        001 … 999
-- Kandidát je volný, když ho nedrží jiný účet téže firmy A ZÁROVEŇ pod ním v osnově
-- ještě žádný účet není. Analytika s vlastní historií (typicky ručně vedený termínovaný
-- vklad na 221100) se tak automaticky NEADOPTUJE — bankovní účet by zdědil cizí
-- zůstatek. Namapovat ji jde ručně v nastavení bankovních účtů.
--
-- ⚠️ Migrace mění jen KONFIGURACI (vazba účtu na analytiku + její založení v osnově).
--    HISTORICKÉ pohyby zůstávají tam, kde jsou — přesun z plochého 221 na analytiky je
--    účetní reklasifikace k datu, kterou nelze provést v uzavřeném/schváleném období.
--    Podklad a návrh zápisu vypíše `php api/bin/reclassify-bank-analytics.php` (dry-run).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- 1) Normalizace: prázdný řetězec je „bez analytiky", ne hodnota.
UPDATE supplier_bank_accounts SET analytic_suffix = NULL WHERE analytic_suffix = '';

-- 2) Duplicity uvolni (dva účty na téže analytice = promíchaný zůstatek). Číslo si
--    nechá nejstarší účet, ostatní dostanou nové v kroku 4.
UPDATE supplier_bank_accounts a
  JOIN (
    SELECT id FROM (
      SELECT id,
             ROW_NUMBER() OVER (PARTITION BY supplier_id, analytic_suffix ORDER BY id) AS rn
        FROM supplier_bank_accounts
       WHERE analytic_suffix IS NOT NULL
    ) ranked
     WHERE ranked.rn > 1
  ) dup ON dup.id = a.id
   SET a.analytic_suffix = NULL;

-- 3) Jedna analytika = jeden účet (NULL se v unique indexu opakovat smí).
ALTER TABLE supplier_bank_accounts
  ADD UNIQUE KEY IF NOT EXISTS uq_sba_analytic (supplier_id, analytic_suffix);

-- 4) Backfill: aktivním účtům bez analytiky přiděl první volné číslo dle pořadí výše.
--    Neaktivní účty se přeskakují (neúčtuje se přes ně) — dostanou číslo, až kdyby se
--    zase aktivovaly a začaly účtovat (dohraje resolver).
--    (CTE je uvnitř odvozené tabulky — MariaDB nepodporuje `WITH … UPDATE` u vícetabulkového
--    UPDATE; materializace derived table zároveň řeší čtení a zápis téže tabulky.)
UPDATE supplier_bank_accounts a
  JOIN (
    WITH RECURSIVE seq (n) AS (
        SELECT 1
        UNION ALL
        SELECT n + 1 FROM seq WHERE n < 999
    ),
    cand AS (
        SELECT LPAD(n, 3, '0') AS suffix,
               CASE WHEN n % 100 = 0 THEN 1 WHEN n % 10 = 0 THEN 2 ELSE 3 END AS tier,
               n
          FROM seq
    ),
    todo AS (
        SELECT id, supplier_id,
               ROW_NUMBER() OVER (PARTITION BY supplier_id ORDER BY id) AS rn
          FROM supplier_bank_accounts
         WHERE is_active = 1 AND analytic_suffix IS NULL
    ),
    free AS (
        SELECT s.supplier_id, c.suffix,
               ROW_NUMBER() OVER (PARTITION BY s.supplier_id ORDER BY c.tier, c.n) AS rn
          FROM (SELECT DISTINCT supplier_id FROM todo) s
          JOIN cand c
         WHERE NOT EXISTS (
                 SELECT 1 FROM supplier_bank_accounts u
                  WHERE u.supplier_id = s.supplier_id AND u.analytic_suffix = c.suffix
               )
           AND NOT EXISTS (
                 SELECT 1 FROM chart_of_accounts x
                  WHERE x.supplier_id = s.supplier_id AND x.account_code = CONCAT('221', c.suffix)
               )
    )
    SELECT t.id, f.suffix
      FROM todo t
      JOIN free f ON f.supplier_id = t.supplier_id AND f.rn = t.rn
  ) m ON m.id = a.id
   SET a.analytic_suffix = m.suffix;

-- 5) Dohraj analytiky do osnovy (dědí typ/stranu ze syntetiky 221), idempotentně.
--    Bez nich by postDocument spadl na `unknown_account`.
INSERT IGNORE INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
SELECT p.supplier_id,
       CONCAT('221', a.analytic_suffix),
       COALESCE(NULLIF(TRIM(a.label), ''), CONCAT('Bankovní účet 221', a.analytic_suffix)),
       p.account_type, p.normal_side, 0, p.id, 1
  FROM supplier_bank_accounts a
  JOIN chart_of_accounts p
    ON p.supplier_id = a.supplier_id AND p.account_code = '221' AND p.is_synthetic = 1
 WHERE a.analytic_suffix IS NOT NULL AND a.is_active = 1;
