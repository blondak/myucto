-- MyÚčto.cz — OSS-8: účtování daně odváděné v režimu jednoho správního místa.
--
-- Daň v režimu OSS (§ 110 a násl. ZDPH) NENÍ česká daň na výstupu: patří státu
-- spotřeby, do přiznání k DPH ani do kontrolního hlášení nevstupuje (VatLedgerService
-- OSS řádky vylučuje) a platí se samostatně, v měně podání. Dokud se účtovala na 343,
-- zůstatek účtu se s přiznáním z principu nemohl srovnat — u 850 zahraničních dokladů
-- rozdíl v řádu statisíců.
--
-- Řešení: vlastní analytika 345.100 (Ostatní daně a poplatky) + globální kontace
-- `oss.output.vat`. V rozvaze jde o tutéž položku „Stát — daňové závazky a dotace"
-- jako u 343 (mapa výkazů matchuje na prefix, takže 345.100 spadne pod 345 sama),
-- takže se mění jen členění v hlavní knize, ne výkaz.
--
-- Idempotentní: obojí přes NOT EXISTS / LEFT JOIN nad unikátními klíči.

SET NAMES utf8mb4;

-- 1) Analytika pro firmy, které osnovu dostaly PŘED touhle migrací. Nové firmy ji
--    dostanou ze šablony (ChartOfAccountsTemplate), tady se dorovnává historie.
--    Odvozený SELECT (AS s) materializuje čtení, aby MariaDB nevadil zápis do téže
--    tabulky, ze které se čte.
INSERT INTO chart_of_accounts
  (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.supplier_id, '345.100', 'DPH v režimu OSS — jiný členský stát', 'liability', 'credit', 0, s.id, 1, 'deductible'
FROM (
  SELECT p.supplier_id AS supplier_id, p.id AS id
    FROM chart_of_accounts p
    LEFT JOIN chart_of_accounts c
           ON c.supplier_id = p.supplier_id AND c.account_code = '345.100'
   WHERE p.account_code = '345' AND c.id IS NULL
) AS s;

-- 2) Globální kontace (supplier_id NULL). Strana MD zůstává NULL — protiúčet určuje
--    druh dokladu (u vydané faktury 311), stejně jako u fx.gain / accrual.accrued.expense.
--    Firma, která chce OSS daň jinam (typicky vlastní analytika k 343), si založí
--    per-tenant override; PostingService čte účet výhradně přes tuhle kontaci.
INSERT INTO posting_rules
  (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'oss.output.vat', 'DPH v režimu OSS — daň odváděná do státu spotřeby (§110 ZDPH)', NULL, '345.100', 0, 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
   WHERE pr.supplier_id IS NULL AND pr.rule_key = 'oss.output.vat' AND pr.priority = 0
);
