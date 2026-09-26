-- MyÚčto.cz — nástup převzatého vztahu odhadnutý z hlášení se eviduje trvale.
--
-- Import měsíčních hlášení JMHZ bez data nástupu vezme jako nástup začátek
-- pojištění v nejstarším hlášeném měsíci. Začíná-li řada hlášení až v březnu,
-- vyjde nástup na 1. 3. a vztah se tváří, jako by v lednu a únoru netrval.
-- Varování o tom viselo jen v náhledu importu a po zápisu zmizelo, takže
-- chybějící leden a únor roku přechodu neodhalila kontrola převzetí ani
-- vyúčtování daně.
--
-- start_estimated = 1: nástup je jen dolní odhad z hlášení, vztah mohl trvat
-- i dřív. Příznak zruší oprava nástupu na kartě vztahu, potvrzení nástupu
-- nebo import, který přinese skutečné datum nástupu.
--
-- Doplnění stávajících dat: vztah převzatý z hlášení JMHZ, jehož nástup je
-- první den měsíce, ve kterém začínají převzaté mzdy celé firmy i vztahu
-- samotného. Přesně tak vypadá odhad z nejstaršího hlášeného měsíce. Ostatní
-- převody (PAMICA, POHODA, PREMIER) nesou skutečné datum nástupu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, UPDATE nad deterministickou podmínkou
-- jen u vztahů bez příznaku.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD COLUMN IF NOT EXISTS start_estimated TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'nástup je dolní odhad z hlášení (první den nejstaršího hlášeného měsíce)'
    AFTER actual_start_date;

UPDATE payroll_employments employment
  JOIN (
        SELECT totals.supplier_id, totals.employment_id, MIN(totals.period_start) AS first_period
          FROM payroll_migration_reference_totals totals
         WHERE totals.employment_id IS NOT NULL
           AND totals.source = 'jmhz'
         GROUP BY totals.supplier_id, totals.employment_id
       ) relation_first
    ON relation_first.supplier_id = employment.supplier_id
   AND relation_first.employment_id = employment.id
  JOIN (
        SELECT totals.supplier_id, MIN(totals.period_start) AS first_period
          FROM payroll_migration_reference_totals totals
         WHERE totals.source = 'jmhz'
         GROUP BY totals.supplier_id
       ) supplier_first
    ON supplier_first.supplier_id = employment.supplier_id
   SET employment.start_estimated = 1
 WHERE employment.start_estimated = 0
   AND employment.status NOT IN ('no_show', 'archived')
   AND COALESCE(employment.actual_start_date, employment.start_date) = relation_first.first_period
   AND relation_first.first_period = supplier_first.first_period
   AND DAY(relation_first.first_period) = 1;
