-- MyUcto.cz - NEMPRI: ošetřovné, otcovská, PPM a DLO, rozhodné období,
-- pravděpodobný příjem, kontaktní pracovník a HZUPN bez návratu do práce.
--
-- ## Proč se druhy dávky se žádostí přestávají blokovat
--
-- Migrace 1654 vedla OSE, OPP, PPM a DLO jako „údaje, které zaměstnavatel
-- nedrží“. To neplatí: § 97 odst. 1 zák. č. 187/2006 Sb. ukládá zaměstnavateli
-- žádosti o dávky PŘIJÍMAT a PŘEDÁVAT („Zaměstnavatel je povinen přijímat
-- žádosti podle § 109 odst. 1 písm. b) bodu 1 svých zaměstnaných osob o dávky,
-- s výjimkou nemocenského, … a neprodleně je … předávat“). Údaje o dítěti,
-- důvodu péče nebo otcovské opisuje z žádosti, kterou mu zaměstnanec předal;
-- nic si nedomýšlí. Přijatá podání jiných mzdových programů to dokládají:
-- ošetřovné i otcovská se od zaměstnavatele přijímají běžně.
--
-- ## Proč dítě nese odkaz, ne rodné číslo
--
-- Rodné číslo se v mzdové evidenci drží jen šifrované. Ošetřovaná osoba nebo
-- dítě se proto vybírá z evidovaných vyživovaných osob (`cared_dependant_id`)
-- a rodné číslo se odhalí až při sestavení věty. Osoba mimo evidenci nese jen
-- jméno a datum narození — `CtOsoba` má rodné číslo nepovinné.
--
-- ## Rozhodné období
--
-- Měsíce rozhodného období, které nepokrývá jednotné měsíční hlášení
-- podané z MyÚčta (před rokem 2026 a před začátkem vedení mezd), se berou
-- z převzatých mezd. Tabulka `payroll_sickness_case_decisive_months` drží
-- jen RUČNÍ doplnění nebo opravu takového měsíce; spočítaná strana se sem
-- neukládá, aby se nemohla rozejít s převzatými daty.
--
-- ## HZUPN bez návratu do práce
--
-- `navratDoPrace = N` s datem a důvodem ČSSZ přijímá (převedení na PPM,
-- skončení zaměstnání). Omezení z 1654 kombinaci „ne + datum“ zakazovalo.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_cases
  ADD COLUMN IF NOT EXISTS action_start TINYINT(1) NOT NULL DEFAULT 1
    AFTER additional_note,
  ADD COLUMN IF NOT EXISTS action_continuation TINYINT(1) NOT NULL DEFAULT 0
    AFTER action_start,
  ADD COLUMN IF NOT EXISTS action_end TINYINT(1) NOT NULL DEFAULT 0
    AFTER action_continuation,
  ADD COLUMN IF NOT EXISTS application_from DATE NULL AFTER action_end,
  ADD COLUMN IF NOT EXISTS application_to DATE NULL AFTER application_from,
  ADD COLUMN IF NOT EXISTS cared_dependant_id BIGINT UNSIGNED NULL
    AFTER application_to,
  ADD COLUMN IF NOT EXISTS cared_first_name VARCHAR(100) NULL
    AFTER cared_dependant_id,
  ADD COLUMN IF NOT EXISTS cared_last_name VARCHAR(100) NULL
    AFTER cared_first_name,
  ADD COLUMN IF NOT EXISTS cared_birth_date DATE NULL AFTER cared_last_name,
  ADD COLUMN IF NOT EXISTS care_reason
    ENUM('ill','quarantine','cannot_care','school_closed') NULL
    AFTER cared_birth_date,
  ADD COLUMN IF NOT EXISTS school_name VARCHAR(200) NULL AFTER care_reason,
  ADD COLUMN IF NOT EXISTS school_business_id VARCHAR(35) NULL
    AFTER school_name,
  ADD COLUMN IF NOT EXISTS shared_household TINYINT(1) NULL
    AFTER school_business_id,
  ADD COLUMN IF NOT EXISTS lone_caregiver TINYINT(1) NULL
    AFTER shared_household,
  ADD COLUMN IF NOT EXISTS child_under_16 TINYINT(1) NULL
    AFTER lone_caregiver,
  ADD COLUMN IF NOT EXISTS other_maternity_claim TINYINT(1) NULL
    AFTER child_under_16,
  ADD COLUMN IF NOT EXISTS other_parental_claim TINYINT(1) NULL
    AFTER other_maternity_claim,
  ADD COLUMN IF NOT EXISTS other_person_s57 TINYINT(1) NULL
    AFTER other_parental_claim,
  ADD COLUMN IF NOT EXISTS cared_personally TINYINT(1) NULL
    AFTER other_person_s57,
  ADD COLUMN IF NOT EXISTS care_days LONGTEXT NULL AFTER cared_personally,
  ADD COLUMN IF NOT EXISTS relationship_code VARCHAR(3) NULL AFTER care_days,
  ADD COLUMN IF NOT EXISTS alternation TINYINT(1) NULL
    AFTER relationship_code,
  ADD COLUMN IF NOT EXISTS paternity_reason VARCHAR(3) NULL AFTER alternation,
  ADD COLUMN IF NOT EXISTS maternity_care_reason VARCHAR(3) NULL
    AFTER paternity_reason,
  ADD COLUMN IF NOT EXISTS child_order TINYINT UNSIGNED NULL
    AFTER maternity_care_reason,
  ADD COLUMN IF NOT EXISTS worked_last_day TINYINT(1) NULL AFTER child_order,
  ADD COLUMN IF NOT EXISTS planned_shifts TINYINT(1) NULL
    AFTER worked_last_day,
  ADD COLUMN IF NOT EXISTS planned_shifts_worked TINYINT(1) NULL
    AFTER planned_shifts,
  ADD COLUMN IF NOT EXISTS probable_income_czk BIGINT UNSIGNED NULL
    AFTER planned_shifts_worked,
  ADD COLUMN IF NOT EXISTS contact_worker_name VARCHAR(100) NULL
    AFTER probable_income_czk,
  ADD COLUMN IF NOT EXISTS contact_worker_phone VARCHAR(33) NULL
    AFTER contact_worker_name,
  ADD COLUMN IF NOT EXISTS contact_worker_email VARCHAR(250) NULL
    AFTER contact_worker_phone;

-- MariaDB neumí IF NOT EXISTS u cizího klíče ani u CHECK, takže se každé
-- omezení nejdřív zahodí.

ALTER TABLE payroll_sickness_cases
  DROP FOREIGN KEY IF EXISTS fk_payroll_sickness_case_cared_dependant;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT fk_payroll_sickness_case_cared_dependant
    FOREIGN KEY (supplier_id, cared_dependant_id)
    REFERENCES payroll_dependants (supplier_id, id) ON DELETE RESTRICT;

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_care_days;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_care_days
    CHECK (care_days IS NULL OR JSON_VALID(care_days));

-- `StCiselnik` v NEMPRI25.xsd: 1 až 3 znaky 0-9 a A-Z.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_codes;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_codes
    CHECK (
      (relationship_code IS NULL
        OR relationship_code REGEXP BINARY '^[0-9A-Z]{1,3}$')
      AND (paternity_reason IS NULL
        OR paternity_reason REGEXP BINARY '^[0-9A-Z]{1,3}$')
      AND (maternity_care_reason IS NULL
        OR maternity_care_reason REGEXP BINARY '^[0-9A-Z]{1,3}$')
    );

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_application;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_application
    CHECK (
      application_to IS NULL OR application_from IS NULL
      OR application_to >= application_from
    );

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_child_order;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_child_order
    CHECK (child_order IS NULL OR child_order BETWEEN 1 AND 10);

-- Návrat do práce z HZUPN. „Ne“ smí nést datum i důvod (skončení zaměstnání,
-- nástup na PPM); „ano“ bez data zůstává chybou, protože z data ČSSZ počítá
-- poslední den dávky.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_return;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_return
    CHECK (
      returned_to_work IS NULL
      OR returned_to_work = 0
      OR returned_on IS NOT NULL
    );

CREATE TABLE IF NOT EXISTS payroll_sickness_case_decisive_months (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  environment             ENUM('production','test') NOT NULL,
  case_id                 BIGINT UNSIGNED NOT NULL,
  period_year             SMALLINT UNSIGNED NOT NULL,
  period_month            TINYINT UNSIGNED NOT NULL,
  -- Započitatelný příjem v haléřích; do věty jde v Kč.
  countable_income_minor  BIGINT UNSIGNED NOT NULL,
  excluded_days           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_sickness_decisive_month
    (supplier_id, environment, case_id, period_year, period_month),

  CONSTRAINT fk_payroll_sickness_decisive_month_case
    FOREIGN KEY (supplier_id, environment, case_id)
    REFERENCES payroll_sickness_cases (supplier_id, environment, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_sickness_case_decisive_months
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_decisive_month_range;
ALTER TABLE payroll_sickness_case_decisive_months
  ADD CONSTRAINT chk_payroll_sickness_decisive_month_range
    CHECK (
      period_month BETWEEN 1 AND 12
      AND period_year BETWEEN 1900 AND 2999
      AND excluded_days <= 31
    );
