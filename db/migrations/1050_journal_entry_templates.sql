-- MyÚčto.cz — Fáze F: šablony ručních zápisů + mzdový můstek (audit 2026-07,
-- nález „Ruční zápis nemá šablony ani opakování — mzdy a leasing se každý měsíc
-- vyklikávají znovu").
--
-- journal_entry_templates = hlavička šablony (per firma), journal_entry_template_lines
-- = řádky (účet, strana MD/D, volitelná výchozí částka — NULL = „doplň při vložení",
-- typicky variabilní položky jako mzdy z externí mzdovky). Šablony jsou čistě datové:
-- vytvoření zápisu ze šablony jde vždy přes běžný POST /accounting/journal (FE jen
-- předvyplní řádky ManualEntry) — PostingService zůstává jediné místo zaúčtování.
--
-- is_seeded = doporučená šablona „Mzdy" (521/524/331/336/342) lazy-naseedovaná per
-- firma při prvním načtení seznamu šablon (JournalEntryTemplateRepository::ensurePayrollSeed),
-- ne v této migraci — firem s double_entry přibývá průběžně, ne jen v okamžiku migrace.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entry_templates (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  description   VARCHAR(255) NULL,
  is_seeded     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'doporučená šablona „Mzdy" naseedovaná systémem',
  created_by    INT NULL COMMENT 'user id (evidenční, bez FK — vzor offset_agreements.created_by)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_jet_supplier (supplier_id, name),
  CONSTRAINT fk_jet_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entry_template_lines (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id    BIGINT UNSIGNED NOT NULL,
  line_no        SMALLINT UNSIGNED NOT NULL,
  label          VARCHAR(255) NULL COMMENT 'pojmenování řádku pro čitelnost (např. „Hrubé mzdy")',
  account_code   VARCHAR(20) NOT NULL,
  side           ENUM('debit','credit') NOT NULL,
  default_amount DECIMAL(14,2) NULL COMMENT 'NULL = doplň při vložení (variabilní částka, např. mzdy)',
  cost_center    VARCHAR(64) NULL,

  KEY idx_jetl_template (template_id, line_no),
  CONSTRAINT fk_jetl_template FOREIGN KEY (template_id) REFERENCES journal_entry_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
