-- MyÚčto.cz — MZ-22-W01e-e: DB guard shody prostředí zdrojového protokolu OIČ.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_external_ids
  ADD KEY IF NOT EXISTS idx_payroll_person_external_id_receipt
    (supplier_id, environment, source_receipt_id);

ALTER TABLE payroll_person_external_ids
  DROP FOREIGN KEY IF EXISTS fk_payroll_person_external_id_receipt;

ALTER TABLE payroll_person_external_ids
  DROP INDEX IF EXISTS fk_payroll_person_external_id_receipt;

ALTER TABLE payroll_person_external_ids
  ADD CONSTRAINT fk_payroll_person_external_id_receipt
    FOREIGN KEY (supplier_id, environment, source_receipt_id)
    REFERENCES payroll_submission_receipts (supplier_id, environment, id)
    ON DELETE RESTRICT;
