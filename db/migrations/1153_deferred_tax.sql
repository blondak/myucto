-- 1153: Odložená daň — ČÚS 003 a § 59 vyhlášky 500/2002 Sb. (481 / 592)
--
-- Účty 481 (Odložený daňový závazek a pohledávka) a 592 (Daň z příjmů — odložená) byly
-- v šabloně osnovy a výkazy je měly namapované (rozvaha C.II.1.4. / P.C.I.8. podle strany
-- zůstatku, VZZ L.2.), ale nic na ně nikdy nepřistálo: chyběl výpočet přechodných rozdílů,
-- kontace i krok uzávěrky. Splatná daň 591/341 přitom hotová byla — asymetrie, kterou
-- audit označil za vysoké riziko.
--
-- Krok průvodce `deferred_tax` navazuje na `income_tax`: odložená daň stojí na rozdílech,
-- které jsou známé až po zaúčtování odpisů a po vyčíslení splatné daně.
--
-- POZOR (vzor 1046/1099/1100): `journal_entries` je system-versioned → ALTER historie
-- vyžaduje přepínač níž. MODIFY navíc nesmí vynechat žádnou existující hodnotu ENUM,
-- jinak by ji DROPl i s navázanými řádky — proto je seznam kompletní a nová hodnota
-- se APPENDUJE na konec.

SET NAMES utf8mb4;

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset','small_asset_accrual',
       'prepaid_expense_accrual','settlement','deferred_tax')
  NOT NULL DEFAULT 'manual';

ALTER TABLE accounting_closing_steps
  MODIFY COLUMN step_key
  ENUM('precheck','depreciation','fx_revaluation','estimates','deferrals',
       'provisions','income_tax','stock','close_books','open_next','deferred_tax') NOT NULL;

-- Kontace odložené daně. Dvě pravidla, protože každá strana má jiný účetní smysl:
--   závazek    — daňové odpisy předběhly účetní; budoucí základ daně bude vyšší (592/481)
--   pohledávka — opačný směr, typicky daňová ztráta k převedení (481/592)
-- Samostatná „release" pravidla nejsou potřeba: krok uzávěrky vždy dorovnává zůstatek 481
-- na aktuálně vypočtenou hodnotu, takže rozpuštění je prostě zápis opačného směru.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'deferred_tax.liability', 'Odložený daňový závazek (592/481)', '592', '481', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'deferred_tax.liability');

INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'deferred_tax.asset', 'Odložená daňová pohledávka (481/592)', '481', '592', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'deferred_tax.asset');
