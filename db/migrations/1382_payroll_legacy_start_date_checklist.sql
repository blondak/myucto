-- MyÚčto.cz — převzatý pracovní vztah nemá datum nástupu a uživatel neví proč.
--
-- Zdrojová tabulka starší agendy (`payroll_employees`) žádný sloupec s datem
-- nástupu ani ukončení nemá, takže materializační migrace 1188/1195/1196 neměly
-- co zkopírovat — NULL tam není omylem. Na kartě vztahu se ale ukážou jen tři
-- pomlčky a nic nenapoví, že se čeká na člověka.
--
-- Datum se ZÁMĚRNĚ neodvozuje z `payroll_monthly_records`: odhad není doklad
-- a pro ČSSZ nebo zdravotní pojišťovnu je špatné datum horší než žádné. Místo
-- toho přibude do onboarding checklistu položka „Doplnit datum nástupu".
--
-- Odškrtnout ji nejde dřív, než je datum vyplněné — hlídá to
-- PayrollEmploymentRepository::assertChecklistPrerequisite().

SET NAMES utf8mb4;

INSERT IGNORE INTO payroll_employment_checklist_items (
  supplier_id,
  employment_id,
  phase,
  item_key,
  status,
  due_date
)
SELECT employment.supplier_id,
       employment.id,
       'onboarding',
       'legacy_start_date',
       'pending',
       NULL
  FROM payroll_employments employment
 WHERE employment.is_legacy_projection = 1
   AND employment.start_date IS NULL
   AND employment.actual_start_date IS NULL
   AND employment.status NOT IN ('ended', 'archived', 'no_show');
