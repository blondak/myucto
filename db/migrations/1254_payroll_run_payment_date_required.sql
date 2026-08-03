-- MyÚčto.cz — datum výplaty je povinný invariant každého mzdového běhu.

SET NAMES utf8mb4;

UPDATE payroll_runs
   SET payment_date = LAST_DAY(period_start)
 WHERE payment_date IS NULL OR payment_date < '1000-01-01';

ALTER TABLE payroll_runs
  MODIFY COLUMN payment_date DATE NOT NULL AFTER period_start,
  DROP CONSTRAINT IF EXISTS chk_payroll_run_payment_date,
  ADD CONSTRAINT chk_payroll_run_payment_date
    CHECK (payment_date >= '1000-01-01');
