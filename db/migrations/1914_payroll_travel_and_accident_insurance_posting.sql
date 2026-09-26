-- MyÚčto.cz — účetní zápisy mzdové agendy mimo mzdový předpis.
--
-- ── Zákonné pojištění odpovědnosti (vyhl. 125/1993 Sb.) ─────────────────────
-- Pojistné si zaměstnavatel počítá sám a pojišťovna žádný doklad nevystavuje,
-- takže předpis nákladu v deníku nevznikal vůbec: závazek existoval jen
-- v platební vrstvě a úhrada se pak nezaúčtovala proti ničemu. Předpis teď
-- vzniká spolu se závazkem (MD náklad / D závazek) pod vlastním `source_type`,
-- takže ho drží unikátní klíč `uq_je_supplier_source` — jeden závazek, jeden
-- zápis.
--
-- Nové předkontace mají stávající firmy srovnané na syntetiky (548 / 379),
-- které má v osnově každá firma. Analytiku 379.400 dostane jen nově seedovaná
-- osnova (ChartOfAccountsTemplate) — stejná konzervativní cesta jako
-- u migrací 1618, 1648 a 1658.
--
-- ── Vyúčtování pracovní cesty s vypořádáním pokladnou ───────────────────────
-- Nezdaněná část cestovní náhrady se při vypořádání pokladnou do mzdy nedostane,
-- takže ji musí zaúčtovat samo vyúčtování (MD cestovné / D pohledávka za
-- zaměstnancem, na které visí poskytnutá záloha).
--
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS; MODIFY na úplný výčet dává po prvním
-- běhu týž výsledek.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS accident_insurance_debit_account VARCHAR(16) NOT NULL DEFAULT '548'
      COMMENT 'Náklad zákonného pojištění odpovědnosti — vyhl. 125/1993 Sb.'
      AFTER travel_expense_debit_account,
  ADD COLUMN IF NOT EXISTS accident_insurance_credit_account VARCHAR(16) NOT NULL DEFAULT '379'
      COMMENT 'Závazek vůči pojistiteli zákonného pojištění odpovědnosti'
      AFTER accident_insurance_debit_account;

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
    'card_settlement','card_writeoff','other_item',
    'payroll_accident_insurance','payroll_travel'
  ) NOT NULL DEFAULT 'manual';
