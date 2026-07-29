-- MyÚčto.cz — časové rozlišení nákladů příštích období (381) per přijatá faktura (§DČR / Task 12).
--
-- 1) purchase_invoice_items.accrual_from / accrual_to (DATE NULL): účetní u ŘÁDKU přijaté
--    faktury označí, že náklad patří do OBDOBÍ od–do (typicky pojistné, parkovné, předplatné),
--    ne celý do měsíce vystavení. NULL/NULL = bez rozlišení (dosavadní chování). Rozlišení je
--    ITEM-level — faktura může mít víc řádků a rozlišit se má jen některý (pojistné = 1 řádek).
--    Faktura se PŘESTO zaúčtuje celá do nákladu roku; odklad na 381 řeší až UZÁVĚRKA
--    (ClosingService::runPrepaidExpenseAccrual) k rozvahovému dni, protože časové rozlišení je
--    uzávěrkový úkon (§19/1 ZoÚ). Zde jen ULOŽENÍ období.
--
-- 2) journal_entries.source_type: append 'prepaid_expense_accrual' na konec ENUM (R6 — jen
--    přidání hodnoty, žádná reorganizace). Idempotentní klíč (supplier_id, source_type,
--    source_id): defer zápis = ('prepaid_expense_accrual', period_id), rozpuštění v N+1 =
--    ('prepaid_expense_accrual', release_base + period_id) — viz
--    ClosingSourceId::prepaidExpenseAccrual / prepaidExpenseAccrualRelease.
--
--    Kontace: MD 381 (debit z pravidla accrual.prepaid.expense, tenant si ho může přesměrovat)
--    / D 5xx (nákladový účet DANÉHO řádku — expense_account_code, jinak dle expense_kind).
--    Rozpuštění v N+1 (MD 5xx / D 381) NENÍ samostatné pravidlo — openNext zrcadlí zaúčtovaný
--    defer zápis prohozením stran, takže sedí na haléř i při per-tenant overridu účtů.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
  ADD COLUMN accrual_from DATE NULL
      COMMENT 'časové rozlišení nákladu (381) — začátek období, do kterého náklad patří; NULL = bez rozlišení'
      AFTER expense_account_code,
  ADD COLUMN accrual_to DATE NULL
      COMMENT 'časové rozlišení nákladu (381) — konec období; náklad přesahující konec účetního období se v uzávěrce odloží na 381'
      AFTER accrual_from;

-- journal_entries je system-versioned → ALTER historie vyžaduje tento přepínač (vzor 1046/1099).
SET @@system_versioning_alter_history = 1;

-- POZOR: MODIFY nesmí vynechat žádnou existující hodnotu (jinak by se DROPla) — kompletní
-- seznam z 1099 + nová prepaid_expense_accrual.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset','small_asset_accrual',
       'prepaid_expense_accrual')
  NOT NULL DEFAULT 'manual';
