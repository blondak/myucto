-- MyÚčto.cz — MZ-22-W01e-d-a: rozsah týdenní pracovní doby dle JMHZ.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  MODIFY COLUMN weekly_work_centihours INT UNSIGNED NOT NULL;
