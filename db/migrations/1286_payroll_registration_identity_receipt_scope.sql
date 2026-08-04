-- MyÚčto.cz — MZ-21: prostředí protokolu musí odpovídat registračnímu záznamu.

SET NAMES utf8mb4;

ALTER TABLE payroll_submission_receipts
  ADD UNIQUE KEY IF NOT EXISTS
    uq_payroll_submission_receipts_environment_id
    (supplier_id, environment, id);

ALTER TABLE payroll_employment_external_ids
  ADD KEY IF NOT EXISTS idx_payroll_employment_external_id_receipt
    (supplier_id, environment, source_receipt_id);

ALTER TABLE payroll_employment_external_ids
  DROP FOREIGN KEY IF EXISTS fk_payroll_employment_external_id_receipt;

ALTER TABLE payroll_employment_external_ids
  DROP INDEX IF EXISTS fk_payroll_employment_external_id_receipt;

ALTER TABLE payroll_employment_external_ids
  ADD CONSTRAINT fk_payroll_employment_external_id_receipt
    FOREIGN KEY (supplier_id, environment, source_receipt_id)
    REFERENCES payroll_submission_receipts (
      supplier_id, environment, id
    )
    ON DELETE RESTRICT;

ALTER TABLE payroll_identity_resolution_tasks
  ADD KEY IF NOT EXISTS idx_payroll_identity_resolution_task_receipt
    (supplier_id, environment, source_receipt_id);

ALTER TABLE payroll_identity_resolution_tasks
  DROP FOREIGN KEY IF EXISTS fk_payroll_identity_resolution_task_receipt;

ALTER TABLE payroll_identity_resolution_tasks
  DROP INDEX IF EXISTS fk_payroll_identity_resolution_task_receipt;

ALTER TABLE payroll_identity_resolution_tasks
  ADD CONSTRAINT fk_payroll_identity_resolution_task_receipt
    FOREIGN KEY (supplier_id, environment, source_receipt_id)
    REFERENCES payroll_submission_receipts (
      supplier_id, environment, id
    )
    ON DELETE RESTRICT;
