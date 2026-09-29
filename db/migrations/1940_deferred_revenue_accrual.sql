-- MyÚčto.cz — časové rozlišení výnosů příštích období (384) u vydaných faktur.
--
-- Zrcadlo nákladové strany z 1100_prepaid_expense_accrual.sql.
--
-- 1) invoice_items.accrual_from / accrual_to (DATE NULL): řádek vydané faktury patří
--    výnosem do OBDOBÍ od–do (typicky roční předplatné, nájem, servisní smlouva).
--    NULL/NULL = bez rozlišení. Faktura se PŘESTO zaúčtuje celá do výnosu dne
--    zdanitelného plnění; část připadající na další účetní období odloží na 384 až
--    UZÁVĚRKA (ClosingService::runDeferredRevenueAccrual) k rozvahovému dni.
--
-- 2) journal_entries.source_type: přidává 'deferred_revenue_accrual'. Idempotentní klíč
--    (supplier_id, source_type, source_id): odklad = period_id, rozpuštění v N+1 =
--    DEFERRED_REVENUE_RELEASE_BASE + period_id (ClosingSourceId).
--    Kontace: MD 6xx (výnosový účet řádku) / D 384 (kredit pravidla
--    accrual.deferred.revenue); rozpuštění v N+1 obráceně.
--
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS; MODIFY na úplný výčet dává po prvním běhu
-- týž výsledek.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS accrual_from DATE NULL
      COMMENT 'časové rozlišení výnosu (384) — začátek období, do kterého výnos patří; NULL = bez rozlišení',
  ADD COLUMN IF NOT EXISTS accrual_to DATE NULL
      COMMENT 'časové rozlišení výnosu (384) — konec období; výnos přesahující konec účetního období se v uzávěrce odloží na 384';

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
    'card_settlement','card_writeoff','other_item',
    'payroll_accident_insurance','payroll_travel','deferred_revenue_accrual'
  ) NOT NULL DEFAULT 'manual';
