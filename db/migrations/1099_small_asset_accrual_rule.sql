-- MyÚčto.cz — kontace + source_type pro časové rozlišení drobného majetku (§DM / Task 11).
--
-- 1) Kontační pravidlo `accrual.small_asset.defer` = MD 381 / D 501: odložení části
--    nákladu na drobný majetek do nákladů příštích období k rozvahovému dni. Účet 381
--    v osnově existuje (rule `accrual.prepaid.expense` ho už používá). Globální seed
--    (supplier_id NULL) — per-tenant override si firma může nastavit sama; idempotence
--    přes WHERE NOT EXISTS na (supplier_id, rule_key, priority), shodně s 1006.
--
--    Rozpuštění v N+1 (MD 501 / D 381) NENÍ samostatné pravidlo — openNext zrcadlí
--    zaúčtovaný defer zápis prohozením stran, takže sedí na haléř i při per-tenant
--    overridu účtů (vzor stock.opening = zrcadlo stock.closing).
--
-- 2) journal_entries.source_type: append 'small_asset_accrual' na konec ENUM (R6 —
--    jen přidání hodnoty, žádná reorganizace). Idempotentní klíč (supplier_id,
--    source_type, source_id): defer zápis = ('small_asset_accrual', period_id),
--    rozpuštění v N+1 = ('small_asset_accrual', release_base + period_id) — viz
--    ClosingSourceId::smallAssetAccrual/smallAssetAccrualRelease.

SET NAMES utf8mb4;

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'accrual.small_asset.defer',
       'Časové rozlišení drobného majetku — odložení nákladu (§7 ZoÚ)',
       '381', '501', 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = 'accrual.small_asset.defer' AND pr.priority = 0
);

-- journal_entries je system-versioned → ALTER historie vyžaduje tento přepínač (vzor 1046).
SET @@system_versioning_alter_history = 1;

-- POZOR: MODIFY nesmí vynechat žádnou existující hodnotu (jinak by se DROPla) — kompletní
-- seznam z 1015 + 1022 + 1041 + 1046 (offset) + nová small_asset_accrual.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset','small_asset_accrual')
  NOT NULL DEFAULT 'manual';
