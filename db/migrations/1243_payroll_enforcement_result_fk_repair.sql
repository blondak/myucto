-- MyÚčto.cz — MZ-14: po rebuild tabulky výsledků obnovit explicitní FK potomků.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_allocations
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_allocation_result;

ALTER TABLE payroll_enforcement_allocations
  ADD CONSTRAINT fk_payroll_enforcement_allocation_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id)
    ON DELETE RESTRICT;

ALTER TABLE payroll_enforcement_ledger
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_ledger_result;

ALTER TABLE payroll_enforcement_ledger
  ADD CONSTRAINT fk_payroll_enforcement_ledger_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id)
    ON DELETE RESTRICT;
