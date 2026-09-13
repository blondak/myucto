-- MyÚčto.cz — pracovní souhrn JMHZ ze souhrnu importu docházky (verze v6).
--
-- Měsíc, který bere odpracovanou dobu ze souhrnu importu
-- (`payroll_time_months.work_source = 'import_summary'`, migrace 1837), nemá
-- časové záznamy, ze kterých by šly spočítat odpracované dny (10267).
-- Podklady docházky dny nenesou a dopočítat je dělením hodin by bylo
-- vymyšlené číslo. Verze `jmhz-work-month.v6` proto smí mít `worked_days`
-- NULL = neuvedeno; v4 a v5 ho mají dál povinně vyplněný.
--
-- Hodinové bloky nese v6 stejné jako v5 (hodiny bez atributu hlášení
-- i náhradní volno), takže se přidává do všech výčtů verzí.
--
-- MariaDB neumí ADD CONSTRAINT IF NOT EXISTS u CHECK, takže se každé omezení
-- zvlášť nejdřív zahodí a založí znovu — jen tak je migrace opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_conditional_confirmation CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND conditional_blocks_confirmed IS NULL
      AND unworked_hours_occurred IS NULL
      AND work_obstacles_occurred IS NULL
      AND unworked_total_millihours IS NULL
      AND unworked_paid_millihours IS NULL
      AND dpn_without_employer_compensation_millihours IS NULL
      AND dpn_with_employer_compensation_millihours IS NULL
      AND vacation_millihours IS NULL
      AND care_millihours IS NULL
      AND employee_obstacle_paid_millihours IS NULL
      AND employer_obstacle_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6'
      )
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6'
      )
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6'
      )
      AND (
        unworked_hours_occurred = 1
        OR (
          maternity_millihours IS NULL
          AND paternity_millihours IS NULL
          AND parental_millihours IS NULL
          AND unpaid_leave_millihours IS NULL
          AND unexcused_millihours IS NULL
        )
      )
      AND (maternity_millihours IS NULL OR maternity_millihours <= 99999999)
      AND (paternity_millihours IS NULL OR paternity_millihours <= 99999999)
      AND (parental_millihours IS NULL OR parental_millihours <= 99999999)
      AND (unpaid_leave_millihours IS NULL OR unpaid_leave_millihours <= 99999999)
      AND (unexcused_millihours IS NULL OR unexcused_millihours <= 99999999)
    )
  );

-- v4 a v5: dny povinně. v6: dny smí zůstat neuvedené, ale uvedené nesmí
-- přerůst měsíc; přesčas je u všech tří částí odpracovaných hodin.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v6'
      )
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v4', 'jmhz-work-month.v5')
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    ) OR (
      derivation_version = 'jmhz-work-month.v6'
      AND (worked_days IS NULL OR worked_days <= 31)
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_compensatory_time_off;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_compensatory_time_off CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v5', 'jmhz-work-month.v6')
      AND compensatory_time_off_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v5', 'jmhz-work-month.v6')
      AND (unworked_hours_occurred = 1 OR compensatory_time_off_millihours IS NULL)
      AND (
        compensatory_time_off_millihours IS NULL
        OR compensatory_time_off_millihours <= 99999999
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
