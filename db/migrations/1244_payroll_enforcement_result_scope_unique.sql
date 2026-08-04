-- MyÚčto.cz — MZ-14: opakovatelná obnova scope unikátnosti výsledku.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_month_results
  DROP INDEX IF EXISTS uq_payroll_enforcement_result_revision,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_enforcement_result_revision
    (supplier_id, revision_scope_id, period_start, employee_id);
