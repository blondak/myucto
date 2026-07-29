-- 1158: ČÚS 018 — rezerva na daň z příjmů (599 / 453)
--
-- Rezervy 451 (zákonné) a 459 (ostatní) už uzávěrkový průvodce nabízí; 453 zůstávala
-- posledním účtem třídy 45, na který nemířila žádná kontace — účet v osnově byl, ale
-- nedalo se na něj nic zaúčtovat.
--
-- ── K čemu 453 slouží ───────────────────────────────────────────────────────────────
-- Účetní jednotka sestavuje závěrku dřív, než zná skutečnou daňovou povinnost (přiznání
-- se podává až po sestavení). Aby výsledek hospodaření nebyl nadhodnocený o daň, která
-- prokazatelně vznikne, tvoří se na ni rezerva. Po podání přiznání se rezerva rozpustí
-- a zaúčtuje se skutečná splatná daň (591/341, krok `income_tax`).
--
-- Protiúčtem je 599 — a nevymýšlím ho: účtová osnova projektu ho má pod názvem
-- „Tvorba a zúčtování rezervy na daň z příjmů" (`ChartOfAccountsTemplate`).
--
-- Poznámka k daňové uznatelnosti: 599 se stejně jako 591/592 do
-- `NON_DEDUCTIBLE_SYNTHETICS` NEPŘIDÁVÁ. Účty 59x leží pod výsledkem hospodaření před
-- zdaněním, takže do daňové uznatelnosti vůbec nevstupují — komentář u té konstanty
-- to říká už dnes a platí to i tady.

SET NAMES utf8mb4;

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'reserve.income_tax.create', 'Tvorba rezervy na daň z příjmů (599/453)', '599', '453', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'reserve.income_tax.create');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'reserve.income_tax.release', 'Rozpuštění rezervy na daň z příjmů (453/599)', '453', '599', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'reserve.income_tax.release');
