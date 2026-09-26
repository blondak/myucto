-- MyÚčto.cz — riziková práce (JMHZ 10273/10274) a dočasné přidělení (10252,
-- 10492–10494) v podmínkách pracovního vztahu.
--
-- Riziková práce: sazbová kategorie § 5a odst. 1 ZPSZ už na vztahu je
-- (migrace 1493). Měsíční hlášení k ní ale chce kategorizaci rizika 10274
-- podle číselníku ČSSZ „Kategorizace rizika": 1 = práce zařazená do kategorie
-- 4 (rizikové zaměstnání, písm. c), 6 = práce zdravotnického záchranáře,
-- 7 = práce člena jednotky HZS podniku (obojí písm. b). U písm. c) je kód
-- jednoznačný, u písm. b) musí účetní říct, o který z obou případů jde —
-- proto sloupec jen pro 6 a 7.
--
-- Dočasné přidělení (agentura práce, § 43a ZP): s 10251 = ANO kontrola 103
-- chce identifikaci uživatele — buď IČO (10252), nebo zahraniční osobu
-- (stát 10492, identifikace 10493, název 10494). Rodné číslo nepodnikající
-- fyzické osoby (10457) se tu záměrně neukládá, viz handoff balíku.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_risk_categorization_code ENUM('6', '7') NULL DEFAULT NULL
    AFTER social_employer_rate_category_evidence;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_assignment_user_kind ENUM('ico', 'foreign') NULL DEFAULT NULL
    AFTER jmhz_temporary_assignment_status;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_assignment_user_ico CHAR(8) NULL DEFAULT NULL
    AFTER jmhz_assignment_user_kind;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_assignment_user_country_code CHAR(2) NULL DEFAULT NULL
    AFTER jmhz_assignment_user_ico;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_assignment_user_foreign_id VARCHAR(8) NULL DEFAULT NULL
    AFTER jmhz_assignment_user_country_code;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_assignment_user_name VARCHAR(100) NULL DEFAULT NULL
    AFTER jmhz_assignment_user_foreign_id;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se každé omezení nejdřív zahazuje.
ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_risk_categorization;

-- Kód 6/7 patří jen k písm. b); u jiné kategorie by byl osiřelý údaj, který
-- se do hlášení nikdy nedostane.
ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_risk_categorization CHECK (
    jmhz_risk_categorization_code IS NULL
    OR social_employer_rate_category = 'rescue_and_company_fire_service'
  );

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_assignment_user;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_assignment_user CHECK (
    (jmhz_assignment_user_kind IS NULL
      AND jmhz_assignment_user_ico IS NULL
      AND jmhz_assignment_user_country_code IS NULL
      AND jmhz_assignment_user_foreign_id IS NULL
      AND jmhz_assignment_user_name IS NULL)
    OR (jmhz_temporary_assignment_status = 'yes'
      AND jmhz_assignment_user_kind = 'ico'
      AND jmhz_assignment_user_ico IS NOT NULL
      AND jmhz_assignment_user_country_code IS NULL
      AND jmhz_assignment_user_foreign_id IS NULL
      AND jmhz_assignment_user_name IS NULL)
    OR (jmhz_temporary_assignment_status = 'yes'
      AND jmhz_assignment_user_kind = 'foreign'
      AND jmhz_assignment_user_ico IS NULL
      AND jmhz_assignment_user_country_code IS NOT NULL
      AND jmhz_assignment_user_foreign_id IS NOT NULL
      AND jmhz_assignment_user_name IS NOT NULL)
  );
