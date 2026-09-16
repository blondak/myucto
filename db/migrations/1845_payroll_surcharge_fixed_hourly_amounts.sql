-- MyÚčto.cz — sjednaný příplatek PEVNOU ČÁSTKOU NA HODINU, ne jen procentem.
--
-- PROČ TO VŮBEC VZNIKÁ
--
-- Migrace 1624 uměla sjednat jen SAZBU v procentech průměrného výdělku. Praxe
-- ale běžně platí příplatek pevnou částkou: „přesčas 75 Kč/h, víkend 42 Kč/h",
-- protože takové číslo si zaměstnanec přečte na mzdovém výměru a nemusí čekat,
-- až se spočítá čtvrtletní průměr. Dokud to modul neuměl, musela účtárna pevnou
-- částku každý měsíc přepočítávat na procenta z právě platného průměru — tedy
-- na jiné procento pro každého člověka a každé čtvrtletí. Výsledek se od
-- sjednané částky vždycky o pár haléřů lišil a nikdo neuměl říct, které z těch
-- dvou čísel je to sjednané.
--
-- ZPĚTNÁ SLUČITELNOST
--
-- Sloupce jsou NULL a nic se nedoplňuje. NULL znamená „pevná částka sjednána
-- není", takže všechna dosavadní procentní sjednání platí dál beze změny a
-- výpočet se u nich nehne ani o haléř. Volba mezi procentem a částkou je tedy
-- vlastnost JEDNOHO DRUHU příplatku v JEDNÉ verzi zásady, ne přepínač celé
-- firmy: § 114 se dá mít procentem a § 118 zároveň pevnou částkou.
--
-- ČÁSTKA V HALÉŘÍCH
--
-- Stejný důvod jako u sazby v bázových bodech (migrace 1624): DECIMAL se
-- z ovladače vrací řetězcem, jehož tvar závisí na nastavení, a celočíselné
-- haléře jsou jednoznačné a bezztrátové. 75 Kč/h = 7500.
--
-- ZÁKONNÉ MINIMUM DATABÁZE NEZNÁ
--
-- U procenta i u pevné částky platí totéž: minimum je v sadě pravidel, ne ve
-- sloupci, a závisí navíc na průměrném výdělku KONKRÉTNÍHO člověka — pevná
-- částka 42 Kč/h je nad zákonným minimem u toho, kdo má průměr 400 Kč/h,
-- a pod ním u toho, kdo má 500 Kč/h. Týž sloupec je tedy jednou dost a jednou
-- málo a CHECK to rozhodnout NEMŮŽE. Hlídá to proto výpočet
-- (`PayrollSurchargeLine::calculate()`), který pod minimum nikdy nespadne:
-- dopočítá zákonnou částku a rozdíl vykáže jako nález. CHECK tu drží jen mez
-- rozsahu proti překlepu o řád.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_surcharge_policies
  ADD COLUMN IF NOT EXISTS overtime_fixed_hourly_minor INT UNSIGNED NULL
    AFTER overtime_rate_bp,
  ADD COLUMN IF NOT EXISTS holiday_fixed_hourly_minor INT UNSIGNED NULL
    AFTER holiday_rate_bp,
  ADD COLUMN IF NOT EXISTS night_fixed_hourly_minor INT UNSIGNED NULL
    AFTER night_rate_bp,
  ADD COLUMN IF NOT EXISTS weekend_fixed_hourly_minor INT UNSIGNED NULL
    AFTER weekend_rate_bp,
  ADD COLUMN IF NOT EXISTS difficult_environment_fixed_hourly_minor INT UNSIGNED NULL
    AFTER difficult_environment_rate_bp;

-- Mez je 100 000 haléřů, tedy 1 000 Kč za hodinu. Vědomě velkorysá: příplatek
-- za svátek je ze zákona celý průměrný výdělek, takže u dobře placené profese
-- jde o stovky korun na hodinu a nižší strop by legitimní sjednání zablokoval.
-- Proti překlepu o řád (750 místo 75 Kč) to chrání pořád.
ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_fixed_hourly;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_fixed_hourly
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

-- Procento a pevná částka u TÉHOŽ druhu se vylučují. Není to úklid schématu:
-- kdyby byla vyplněná obě, muselo by si pořadí větví ve výpočtu vybrat, které
-- z nich je to sjednané, a člověk by na výplatní pásce našel číslo, které ve
-- smlouvě nestojí. Sjednat lze právě jedno; obě prázdná znamenají zákonné
-- minimum, což je stav dosavadních řádků.
ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_one_form;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_one_form
  CHECK (
        (overtime_rate_bp IS NULL OR overtime_fixed_hourly_minor IS NULL)
    AND (holiday_rate_bp IS NULL OR holiday_fixed_hourly_minor IS NULL)
    AND (night_rate_bp IS NULL OR night_fixed_hourly_minor IS NULL)
    AND (weekend_rate_bp IS NULL OR weekend_fixed_hourly_minor IS NULL)
    AND (difficult_environment_rate_bp IS NULL
         OR difficult_environment_fixed_hourly_minor IS NULL)
  );
