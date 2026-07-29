-- MyÚčto.cz — Task 34: obecné předuzávěrkové šablony ručních zápisů (dohadné položky,
-- časové rozlišení, kurzové rozdíly, rezervy, opravné položky/odpis pohledávky), aby
-- je uživatel měl v /templates rovnou k dispozici bez ručního vyklikávání.
--
-- journal_entry_templates už má is_seeded (1050, „doporučená šablona Mzdy" lazy
-- naseedovaná per firma přes JournalEntryTemplateRepository::ensurePayrollSeed) — ale
-- is_seeded = 1 samo o sobě nerozliší VÍC seedovaných šablon od sebe (existence check
-- by se zastavil na první nalezené). seed_key = stabilní klíč konkrétní seedované
-- šablony ('payroll', 'closing.accrued_liability', …), díky němu jde přidat další
-- doporučené šablony (JournalEntryTemplateRepository::ensureClosingTemplatesSeed) beze
-- změny chování stávajícího ensurePayrollSeed.
--
-- Žádné INSERT dat pro existující firmy — stejně jako u payroll seedu (1050) se
-- šablony seedují lazy z GET /accounting/journal-templates (JournalTemplateAction::list),
-- protože firem s double_entry přibývá průběžně, ne jen v okamžiku migrace.
--
-- Idempotence: ADD COLUMN/KEY IF NOT EXISTS (MariaDB 10.6+/11.8 native). Backfill
-- seed_key = 'payroll' pro existující řádky is_seeded = 1 (jediná dosavadní seedovaná
-- šablona).

SET NAMES utf8mb4;

ALTER TABLE journal_entry_templates
  ADD COLUMN IF NOT EXISTS seed_key VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'stabilní klíč seedované šablony (payroll, closing.*…); NULL u uživatelských šablon' AFTER is_seeded;

ALTER TABLE journal_entry_templates
  ADD UNIQUE KEY IF NOT EXISTS uq_jet_supplier_seed_key (supplier_id, seed_key);

UPDATE journal_entry_templates
   SET seed_key = 'payroll'
 WHERE is_seeded = 1 AND seed_key IS NULL;
