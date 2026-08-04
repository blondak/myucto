-- MyÚčto.cz — MZ-03-W03: oprava kaskády tenant → instituce → historie účtů.
--
-- Fresh instalace mají CASCADE už v migraci 1190. Tato navazující migrace
-- opravuje databáze, na kterých byla původní varianta 1190 spuštěna s RESTRICT.

SET NAMES utf8mb4;

ALTER TABLE payroll_institution_accounts
  DROP FOREIGN KEY IF EXISTS fk_payroll_institution_account_institution;

ALTER TABLE payroll_institution_accounts
  ADD CONSTRAINT fk_payroll_institution_account_institution
    FOREIGN KEY (supplier_id, institution_id)
    REFERENCES payroll_institutions (supplier_id, id) ON DELETE CASCADE;
