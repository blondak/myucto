-- MyÚčto.cz — mzdový list (§38j ZDP), povinná roční evidence za zaměstnance.
--
-- PROČ: mzdová rekapitulace (Fáze F, migrace 1085) počítá a účtuje hrubý rozpad měsíc po
-- měsíci, ale NEZNÁ zaměstnance jako entitu — `PayrollAction::post()` bere jen (rok, měsíc,
-- hrubá mzda, typ poplatníka) a zaúčtuje JEDEN souhrnný zápis. To stačí na účtování, ale
-- §38j ZDP chce evidenci VÁZANOU NA KONKRÉTNÍHO ČLOVĚKA (jméno, rodné číslo/datum narození,
-- adresa) s měsíčním rozpadem za celý rok a slevami (§35ba, §35c ZDP), které rekapitulace
-- záměrně neřeší (viz PayrollCalculator class docblock — „slevy na poplatníka řeší mzdový
-- list"). Proto dvě nové tabulky:
--
--   payroll_employees        — identifikace poplatníka + jeho prohlášení (sleva na
--                               poplatníka, počet dětí pro daňové zvýhodnění).
--   payroll_monthly_records  — SNAPSHOT rozpadu a slev za KONKRÉTNÍ měsíc v okamžiku
--                               zaúčtování. Snapshot záměrně, ne přepočet za chodu:
--                                 (a) `payroll_employees.child_count` se v čase mění
--                                     (narození dítěte), ale mzdový list za leden musí
--                                     ukázat slevu platnou V LEDNU, ne dnešní stav;
--                                 (b) `breakdown` je celý výstup PayrollCalculator::compute()
--                                     k danému roku — budoucí změna sazeb (TaxConstants)
--                                     nesmí tiše přepsat historii.
--
-- NEMĚNÍ ÚČETNÍ ZÁPIS: `journal_entry_id` je jen odkaz na zápis založený rekapitulací
-- (informační vazba pro drill-down), slevy se do zaúčtovaných částek nepromítají — to by
-- byla samostatná, mnohem širší změna zaúčtování, o kterou tady nejde. Mzdový list je
-- evidenční sestava (§38j), ne oprava kontace.
--
-- UNIQUE (employee_id, year, month): idempotence stejná jako u journal_entries
-- (uq_je_supplier_source) — opakované zaúčtování téhož měsíce záznam přepíše.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_employees (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  full_name            VARCHAR(191) NOT NULL,
  birth_date           DATE NULL COMMENT 'datum narození — náhrada, chybí-li rodné číslo (§38j odst. 2 písm. a) ZDP)',
  birth_number         VARCHAR(20) NULL COMMENT 'rodné číslo',
  address              VARCHAR(255) NULL COMMENT 'bydliště poplatníka',
  taxpayer_type        VARCHAR(20) NOT NULL DEFAULT 'employee'
                       COMMENT 'employee=521/331, managing_partner=522/366 — viz PayrollCalculator::TYPE_*',
  tax_credit_taxpayer  TINYINT(1) NOT NULL DEFAULT 1
                       COMMENT 'podepsané prohlášení poplatníka (§38k ZDP) — uplatňuje měsíční slevu na poplatníka',
  child_count          TINYINT UNSIGNED NOT NULL DEFAULT 0
                       COMMENT 'počet dětí pro daňové zvýhodnění (§35c ZDP); zjednodušeně bez rozlišení ZTP/P a bez bonusu nad rámec sražené daně',
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_pe_supplier (supplier_id, is_active),
  CONSTRAINT fk_pe_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_pe_taxpayer_type CHECK (taxpayer_type IN ('employee', 'managing_partner'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_monthly_records (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  year                  SMALLINT UNSIGNED NOT NULL,
  month                 TINYINT UNSIGNED NOT NULL,
  gross                 INT NOT NULL COMMENT 'hrubá mzda za měsíc, celé Kč',
  breakdown             JSON NOT NULL COMMENT 'PayrollCalculator::compute() výstup pro tento měsíc',
  tax_credit_taxpayer   INT NOT NULL DEFAULT 0 COMMENT 'měsíční sleva na poplatníka uplatněná v tomto měsíci (§35ba ZDP), 1/12 roční částky',
  tax_credit_children   INT NOT NULL DEFAULT 0 COMMENT 'měsíční daňové zvýhodnění na děti (§35c ZDP), 1/12 roční částky',
  advance_tax_final     INT NOT NULL COMMENT 'záloha na daň po slevách, floor 0 (bonus nad rámec sražené daně se nemodeluje)',
  net_final             INT NOT NULL COMMENT 'čistá mzda po slevách',
  journal_entry_id      BIGINT UNSIGNED NULL COMMENT 'informační odkaz na zápis mzdové rekapitulace — slevy zápis nemění',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pmr_employee_period (employee_id, year, month),
  KEY idx_pmr_supplier_period (supplier_id, year),
  CONSTRAINT fk_pmr_supplier FOREIGN KEY (supplier_id)      REFERENCES supplier(id)          ON DELETE CASCADE,
  CONSTRAINT fk_pmr_employee FOREIGN KEY (employee_id)      REFERENCES payroll_employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmr_entry    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)   ON DELETE SET NULL,
  CONSTRAINT chk_pmr_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
