-- MyÚčto.cz — MZ-10: sazbová kategorie zaměstnavatele podle § 5a odst. 1 ZPSZ.
--
-- § 5a odst. 1 zák. č. 589/1992 Sb. dělá ze zaměstnanců TŘI vyměřovací základy
-- zaměstnavatele a § 7 odst. 1 na každý pouští jinou sazbu — v roce 2026 24,8 %
-- písm. a), 29,8 % písm. b) zdravotničtí záchranáři a jednotky HZS podniku
-- a 27,8 % písm. c) rizikové zaměstnání.
--
-- Do teď uměl vztah jen boolean `risky_work`. Ten sice šel zaškrtnout na kartě
-- vztahu, ale do vstupu sociálního výpočtu nikdy nedotekl: mzdový běh počítal
-- i zaškrtnutému vztahu běžných 24,8 % a o rozdílu se uživatel nedozvěděl.
-- Kategorie proto dostává vlastní sloupec a stává se zdrojem pravdy; boolean
-- zůstává kvůli JMHZ (10273/10274) a drží ho s kategorií CHECK.
--
-- Zařazení mimo běžnou sazbu se dokládá (rizikové zaměstnání podle § 37d odst. 2
-- zákona o důchodovém pojištění vzniká z kategorizace prací). Odkaz na podklad
-- je proto samostatný sloupec; bez něj skončí výpočet na `manual_review`.
-- Historickým řádkům s `risky_work = 1` se odkaz NEDOPLŇUJE — vymyslet za
-- účetní důkaz je horší než ji o něj požádat.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS social_employer_rate_category
    ENUM('ordinary', 'rescue_and_company_fire_service', 'risk_employment')
    NOT NULL DEFAULT 'ordinary'
    AFTER risky_work;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS social_employer_rate_category_evidence VARCHAR(190) NULL
    AFTER social_employer_rate_category;

UPDATE payroll_employment_terms
   SET social_employer_rate_category = 'risk_employment'
 WHERE risky_work = 1
   AND social_employer_rate_category = 'ordinary';

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_rate_category;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_rate_category CHECK (
    (risky_work = 1) = (social_employer_rate_category = 'risk_employment')
  );

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_rate_category_evidence;

-- Podklad smí nést jen zařazení nad běžnou sazbu; u běžné sazby by to byl
-- osiřelý text, který nikdo nečte a nikdo neruší.
ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_term_rate_category_evidence CHECK (
    social_employer_rate_category = 'ordinary'
      AND social_employer_rate_category_evidence IS NULL
    OR social_employer_rate_category <> 'ordinary'
  );
