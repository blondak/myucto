-- MyÚčto.cz — MZ-22-W01e-d-b: version-aware kontrakt podmíněných bloků.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  MODIFY COLUMN conditional_blocks_confirmed TINYINT UNSIGNED NULL;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_unworked_block,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_obstacle_block,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_ranges;

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
      derivation_version = 'jmhz-work-month.v2'
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_unworked_block CHECK (
    derivation_version = 'jmhz-work-month-core.v1' OR (
      (
        unworked_hours_occurred = 0
        AND unworked_total_millihours IS NULL
        AND unworked_paid_millihours IS NULL
        AND dpn_without_employer_compensation_millihours IS NULL
        AND dpn_with_employer_compensation_millihours IS NULL
        AND vacation_millihours IS NULL
        AND care_millihours IS NULL
      ) OR (
        unworked_hours_occurred = 1
        AND unworked_total_millihours IS NOT NULL
        AND unworked_total_millihours > 0
      )
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_obstacle_block CHECK (
    derivation_version = 'jmhz-work-month-core.v1' OR (
      (
        work_obstacles_occurred = 0
        AND employee_obstacle_paid_millihours IS NULL
        AND employer_obstacle_millihours IS NULL
      ) OR (
        work_obstacles_occurred = 1
        AND unworked_hours_occurred = 1
        AND (
          employee_obstacle_paid_millihours IS NOT NULL
          OR employer_obstacle_millihours IS NOT NULL
        )
      )
    )
  ),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_conditional_ranges CHECK (
    (unworked_total_millihours IS NULL OR unworked_total_millihours <= 99999999)
    AND (unworked_paid_millihours IS NULL OR unworked_paid_millihours <= 99999999)
    AND (
      dpn_without_employer_compensation_millihours IS NULL
      OR dpn_without_employer_compensation_millihours <= 99999999
    )
    AND (
      dpn_with_employer_compensation_millihours IS NULL
      OR dpn_with_employer_compensation_millihours <= 99999999
    )
    AND (vacation_millihours IS NULL OR vacation_millihours <= 99999999)
    AND (care_millihours IS NULL OR care_millihours <= 99999999)
    AND (
      unworked_paid_millihours IS NULL
      OR vacation_millihours IS NULL
      OR unworked_paid_millihours >= vacation_millihours
    )
    AND (
      employee_obstacle_paid_millihours IS NULL
      OR employee_obstacle_paid_millihours <= agreed_fund_millihours
    )
    AND (
      employer_obstacle_millihours IS NULL
      OR employer_obstacle_millihours <= agreed_fund_millihours
    )
  );
