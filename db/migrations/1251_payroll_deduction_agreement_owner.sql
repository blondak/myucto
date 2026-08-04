-- MyÚčto.cz — dohoda o srážce musí patřit zaměstnanci uvedenému v ledgeru.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
  ADD UNIQUE INDEX IF NOT EXISTS uq_payroll_deduction_agreement_owner
    (supplier_id, id, employee_id);

ALTER TABLE payroll_deduction_ledger
  DROP FOREIGN KEY IF EXISTS fk_payroll_deduction_ledger_agreement;

ALTER TABLE payroll_deduction_ledger
  ADD CONSTRAINT fk_payroll_deduction_ledger_agreement
    FOREIGN KEY (supplier_id, agreement_id, employee_id)
    REFERENCES payroll_deduction_agreements
      (supplier_id, id, employee_id) ON DELETE RESTRICT;
