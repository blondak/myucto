-- MyÚčto.cz — příznak „roční předplatné přes přelom roku" na pravidle klasifikace nákladů
-- (Automatizace 2026). Doplněk k 1093 (expense_classification_rules) + 1100 (accrual_from/to).
--
-- PROČ: pravidlo dnes umí říct, CO dodavatel dodává (expense_kind) a KAM to jde
-- (target_account_code, 1095). Neumí ale říct, že dodavatel účtuje ROČNÍ PŘEDPLATNÉ, které
-- přesahuje přes rozvahový den a patří časově rozlišit na 381 (1100). Přesně tuhle vlastnost
-- mají opakující se vzory z praxe: cloudové/hostingové služby, parkovné a pojistné —
-- každý rok stejně, každý rok se ručně dohledává accrual_from/to.
--
-- CO TENTO PŘÍZNAK NEDĚLÁ: nic neúčtuje a sám accrual_from/to NEZAPISUJE. Je to jen VSTUP pro
-- RecurringPrepaidSuggestionService, který u faktury takového dodavatele NAVRHNE období rozlišení
-- (od data plnění na 12 měsíců), účetní ho v editoru potvrdí a teprve pak ho uzávěrka
-- (ClosingService::prepaidExpenseAccrualPreview / runPrepaidExpenseAccrual) odloží na 381.
-- Read-only návrh, žádná automatika bez potvrzení (§DM „Nikdy neúčtuj automaticky, když si nejsi jistý").
--
-- ŽÁDNÝ SAMOSTATNÝ „nedaňový" PŘÍZNAK: nedaňovost výdaje NESE ÚČET (target_account_code → 528/513,
-- které mají v chart_of_accounts tax_deductibility='non_deductible'), odkud ji ř.40 DPPO
-- (DppoReturnDataProvider::nonDeductibleCosts) sečte sám. Druhý příznak by byl redundantní zdroj pravdy.

SET NAMES utf8mb4;

ALTER TABLE expense_classification_rules
  ADD COLUMN IF NOT EXISTS recurring_prepaid TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'dodavatel s ročním předplatným přes přelom roku (cloud, pojištění, parkovné) — návrh časového rozlišení 381; 0 = ne'
      AFTER target_account_code;
