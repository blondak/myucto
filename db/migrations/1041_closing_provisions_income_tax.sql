-- MyÚčto.cz — Fáze D (audit 2026-07): D9 opravné položky k pohledávkám,
-- D10 rozdělení výsledku hospodaření, D11 splatná daň z příjmů v uzávěrce.
--
-- Rozšiřuje ENUMy deníku a uzávěrkových kroků o nové zdrojové typy a kroky,
-- seeduje kontace splatné daně (591/341) a udržuje zákonné/účetní OP (558/559 →
-- 391) z 1006. Idempotence: MODIFY je opakovatelný, seed přes NOT EXISTS (vzor 1006_).
--
-- Pozn.: step_key ENUM v 1015_ nikdy neobsahoval 'stock' (krok „Zásoby" se přidal
-- do kódu později bez ALTERu) — doplňujeme ho tady zpět, aby upsertStep('stock')
-- neztrácel hodnotu při STRICT sql_mode (latentní chyba, opraveno aditivně).

SET NAMES utf8mb4;

-- journal_entries má od 1029_ SYSTEM VERSIONING (MariaDB temporal table) — MODIFY sloupce
-- vyžaduje povolit ALTER historie (KEEP), jinak MariaDB 4119. Session scope, drží se pro
-- zbytek migrace (migrate.php běží všechny statementy na jednom spojení).
SET @@system_versioning_alter_history = 1;

-- Nové zdrojové typy deníku (append na konec ENUM):
--   provision           — tvorba opravné položky k pohledávce (558/559 vs 391), source_id = invoice_id (idempotence per pohledávka)
--   income_tax          — předpis splatné daně z příjmů (591/341), source_id = period_id
--   profit_distribution — rozdělení VH po schválení závěrky (431 → 428/429/364…), source_id = schválené období
ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution') NOT NULL DEFAULT 'manual';

-- Nové kroky uzávěrkového průvodce (+ doplnění chybějícího 'stock', viz hlavička).
ALTER TABLE accounting_closing_steps
  MODIFY COLUMN step_key
  ENUM('precheck','depreciation','fx_revaluation','estimates','deferrals',
       'provisions','income_tax','stock','close_books','open_next') NOT NULL;

-- Kontace splatné daně z příjmů (591/341) — MD 591 (daň splatná) / D 341 (závazek k FÚ).
-- Opravné položky (558/559 → 391) i rozpouštění (391/558) už jsou seedovány v 1006_.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'income_tax.payable', 'Předpis splatné daně z příjmů (591/341)', '591', '341', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'income_tax.payable');

-- Rozpuštění účetní opravné položky (391/559) — protějšek k 391/558 (allowance.receivable.release
-- z 1006_ řeší zákonnou OP 558). Účetní OP na 559 potřebuje vlastní protistranu.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'allowance.receivable.release.acct', 'Rozpuštění / zúčtování účetní opravné položky (391/559)', '391', '559', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'allowance.receivable.release.acct');
