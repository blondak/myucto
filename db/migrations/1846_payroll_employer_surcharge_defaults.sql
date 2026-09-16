-- MyÚčto.cz — výchozí sazby zákonných příplatků § 114 až § 118 na úrovni FIRMY.
--
-- PROČ TO VZNIKÁ
--
-- Sjednané příplatky šly dosud zadat jen na pracovním vztahu
-- (`payroll_employment_surcharge_policies`, migrace 1624 a 1845). U firmy
-- s dvěma sty zaměstnanci to znamená zadat tutéž kolektivní sazbu dvěstěkrát
-- a při každé změně smlouvy znovu. To nikdo neudělá, takže sazba zůstala
-- nenastavená a mzda se počítala ze zákonného minima, i když firma platí víc.
--
-- Zásada je přitom v drtivé většině firemní: kolektivní smlouva nebo vnitřní
-- předpis platí pro celou firmu a vztah je výjimka, ne pravidlo.
--
-- DĚDĚNÍ
--
-- Vztah s vlastní zásadou ji má dál přednostně — sjednání s konkrétním
-- člověkem přebíjí firemní výchozí stav. Vztah bez vlastní zásady použije
-- tenhle firemní řádek a teprve když ani ten sazbu nenese, platí zákonné
-- minimum ze sady pravidel. Odkud sazba přišla, nese výpočet ve stopě, aby
-- účetní viděla důvod a nemusela hádat, proč vyšel příplatek zrovna takhle.
--
-- ZÁKONNÉ MINIMUM SE TÍM NEMĚNÍ
--
-- Hlídá ho dál výpočet nad skutečným základem konkrétního člověka
-- (`PayrollSurchargeLine`), protože minimum je podíl z JEHO průměrného
-- výdělku. Firemní výchozí sazba je jen jiný ZDROJ čísla, ne jiná pravidla.
--
-- TVAR SLOUPCŮ
--
-- Záměrně týž jako na vztahu: procento v bázových bodech (25 % = 2500) a pevná
-- částka v haléřích za hodinu (75 Kč/h = 7500). Dvojí tvar téhož údaje na dvou
-- úrovních by se dřív nebo později rozešel v zaokrouhlení.
--
-- ZPĚTNÁ SLUČITELNOST
--
-- Všechny sloupce jsou NULL a nic se nedoplňuje. NULL znamená „firma výchozí
-- sazbu nemá", takže se všechny dosavadní politiky i všechna dosavadní
-- sjednání na vztazích chovají úplně stejně jako dřív.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_policies
  ADD COLUMN IF NOT EXISTS overtime_rate_bp SMALLINT UNSIGNED NULL
    AFTER leave_entitlement_weeks,
  ADD COLUMN IF NOT EXISTS holiday_rate_bp SMALLINT UNSIGNED NULL
    AFTER overtime_rate_bp,
  ADD COLUMN IF NOT EXISTS night_rate_bp SMALLINT UNSIGNED NULL
    AFTER holiday_rate_bp,
  ADD COLUMN IF NOT EXISTS weekend_rate_bp SMALLINT UNSIGNED NULL
    AFTER night_rate_bp,
  ADD COLUMN IF NOT EXISTS difficult_environment_rate_bp SMALLINT UNSIGNED NULL
    AFTER weekend_rate_bp,
  ADD COLUMN IF NOT EXISTS overtime_fixed_hourly_minor INT UNSIGNED NULL
    AFTER difficult_environment_rate_bp,
  ADD COLUMN IF NOT EXISTS holiday_fixed_hourly_minor INT UNSIGNED NULL
    AFTER overtime_fixed_hourly_minor,
  ADD COLUMN IF NOT EXISTS night_fixed_hourly_minor INT UNSIGNED NULL
    AFTER holiday_fixed_hourly_minor,
  ADD COLUMN IF NOT EXISTS weekend_fixed_hourly_minor INT UNSIGNED NULL
    AFTER night_fixed_hourly_minor,
  ADD COLUMN IF NOT EXISTS difficult_environment_fixed_hourly_minor INT UNSIGNED NULL
    AFTER weekend_fixed_hourly_minor;

-- Meze jsou tytéž jako u zásady vztahu (migrace 1624 a 1845): sazba nad 100 %
-- je legitimní (§ 115 má zákonné minimum rovných 100 %), takže strop je jen
-- proti překlepu o řád. Spodní hranu hlídá výpočet proti sadě pravidel.
ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_surcharge_rates;
ALTER TABLE payroll_employer_policies
  ADD CONSTRAINT chk_payroll_employer_policy_surcharge_rates
  CHECK (
        (overtime_rate_bp IS NULL OR overtime_rate_bp BETWEEN 1 AND 50000)
    AND (holiday_rate_bp IS NULL OR holiday_rate_bp BETWEEN 1 AND 50000)
    AND (night_rate_bp IS NULL OR night_rate_bp BETWEEN 1 AND 50000)
    AND (weekend_rate_bp IS NULL OR weekend_rate_bp BETWEEN 1 AND 50000)
    AND (difficult_environment_rate_bp IS NULL
         OR difficult_environment_rate_bp BETWEEN 1 AND 50000)
  );

ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_surcharge_fixed;
ALTER TABLE payroll_employer_policies
  ADD CONSTRAINT chk_payroll_employer_policy_surcharge_fixed
  CHECK (
        (overtime_fixed_hourly_minor IS NULL
         OR overtime_fixed_hourly_minor BETWEEN 1 AND 100000)
    AND (holiday_fixed_hourly_minor IS NULL
         OR holiday_fixed_hourly_minor BETWEEN 1 AND 100000)
    AND (night_fixed_hourly_minor IS NULL
         OR night_fixed_hourly_minor BETWEEN 1 AND 100000)
    AND (weekend_fixed_hourly_minor IS NULL
         OR weekend_fixed_hourly_minor BETWEEN 1 AND 100000)
    AND (difficult_environment_fixed_hourly_minor IS NULL
         OR difficult_environment_fixed_hourly_minor BETWEEN 1 AND 100000)
  );

-- Procento a pevná částka u TÉHOŽ druhu se vylučují, stejně jako na vztahu:
-- kdyby bylo vyplněné obojí, musel by si výpočet vybrat, které z nich je to
-- sjednané, a na výplatní pásce by skončilo číslo, které ve smlouvě nestojí.
ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_surcharge_one_form;
ALTER TABLE payroll_employer_policies
  ADD CONSTRAINT chk_payroll_employer_policy_surcharge_one_form
  CHECK (
        (overtime_rate_bp IS NULL OR overtime_fixed_hourly_minor IS NULL)
    AND (holiday_rate_bp IS NULL OR holiday_fixed_hourly_minor IS NULL)
    AND (night_rate_bp IS NULL OR night_fixed_hourly_minor IS NULL)
    AND (weekend_rate_bp IS NULL OR weekend_fixed_hourly_minor IS NULL)
    AND (difficult_environment_rate_bp IS NULL
         OR difficult_environment_fixed_hourly_minor IS NULL)
  );
