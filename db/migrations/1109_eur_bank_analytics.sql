-- MyÚčto.cz — #35: dedikovaná analytika pro cizoměnové (EUR) bankovní účty.
--
-- PROBLÉM: firma může vést VŠECHNY běžné bankovní účty na plochém syntetickém 221
-- a měnu nést až na řádku deníku (currency_code / fx_rate / amount_foreign,
-- §4/12 ZoÚ). Na 221 pak leží CZK běžný + spořící + několik cizoměnových účtů a navíc
-- převodové nohy termínovaného vkladu (221 ⇄ 221100). Cizoměnová pozice je promíchaná
-- a holé 221 může v EUR vykázat i ZÁPORNÝ „zůstatek" — to není zůstatek žádného
-- reálného účtu, ale artefakt míchání. Poloautomat přecenění
-- k rozvahovému dni (FxRevaluationService slot 2 / ClosingRepository::bankProposals)
-- takový účet ZÁMĚRNĚ vyloučí (Kč zůstatek účtu ≠ Kč hodnota cizoměnových řádků), takže
-- EUR banka se pak musí přeceňovat ručně.
--
-- ŘEŠENÍ: každému vlastnímu EUR bankovnímu účtu (supplier_bank_accounts) nastavíme
-- `analytic_suffix`. Bankovní noha jeho pohybů pak nepadá na holé 221, ale na 221<suffix>
-- (BankAnalyticResolver, viz api/src/Service/Accounting/Bank). Vznikne ČISTÝ jednoměnový
-- účet, který bankProposals nabídne a FxRevaluationService přecení AUTOMATICKY per účet:
--   1. EUR účet (dle pořadí založení) → 221500
--   2. EUR účet                       → 221510
-- CZK účty i termínovaný vklad (221100) zůstávají beze změny.
--
-- ⚠️ Tahle migrace mění jen KONFIGURACI (suffix + založení analytiky v osnově). NEpřesouvá
--    historické pohyby z plochého 221 na 221500/221510 — to je účetní reklasifikace k
--    datu (reklasifikační zápis 221500/221 MD/D v cizoměnové stopě), kterou je nutné
--    odsouhlasit a zaúčtovat zvlášť; do té doby se automaticky přeceňují jen NOVÉ pohyby.
--    (Resolver navíc analytiku dohraje sám při prvním zaúčtování, i kdyby se osnova níže
--    nenaseedovala — proto je INSERT IGNORE bezpečný a idempotentní.)

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- 1) Založ analytiky pod syntetickým 221 (dědí typ/stranu z rodiče), idempotentně.
INSERT IGNORE INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
SELECT p.supplier_id, x.code, x.name, p.account_type, p.normal_side, 0, p.id, 1
FROM chart_of_accounts p
JOIN (
    SELECT 1 AS supplier_id, '221500' AS code, 'Běžný účet EUR (1)' AS name
    UNION ALL SELECT 1, '221510', 'Běžný účet EUR (2)'
) x ON x.supplier_id = p.supplier_id
WHERE p.account_code = '221' AND p.is_synthetic = 1;

-- 2) Namapuj EUR bankovní účty na jejich analytiku. Pořadí je dané `id` (pořadím
--    založení), ne konkrétním číslem účtu — migrace tak nenese žádná tenant data
--    a na cizím nasazení se chová stejně jako na tom, kde vznikla.
UPDATE supplier_bank_accounts a
  JOIN (
      SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn
        FROM supplier_bank_accounts
       WHERE supplier_id = 1 AND currency = 'EUR'
         AND (analytic_suffix IS NULL OR analytic_suffix = '')
  ) d ON d.id = a.id
   SET a.analytic_suffix = CASE d.rn WHEN 1 THEN '500' WHEN 2 THEN '510' END
 WHERE d.rn <= 2;
