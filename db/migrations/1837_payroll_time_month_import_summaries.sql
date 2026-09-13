-- MyÚčto.cz — Mzdy → Docházka: souhrn pracovního měsíce z importu podkladů.
--
-- Docházkový systém dodává měsíční SOUČTY hodin, ne intervaly. Souhrn se proto
-- nerozkládá na `payroll_time_entries` (vymyšlené směny a dny by byly horší než
-- přiznaný souhrn) a ukládá se jako neměnná revize k měsíci vztahu:
-- `payroll_time_months.work_source` říká, odkud měsíc bere odpracovanou dobu.
-- Dny v podkladech nejsou, `worked_days` proto zůstává NULL = neuvedeno.
--
-- CHECK omezení jsou jen uvnitř CREATE TABLE IF NOT EXISTS: MariaDB neumí
-- ADD CONSTRAINT IF NOT EXISTS u CHECK, takže opakované spuštění je no-op.

SET NAMES utf8mb4;

ALTER TABLE payroll_time_months
  ADD COLUMN IF NOT EXISTS work_source ENUM('entries','import_summary') NOT NULL DEFAULT 'entries'
    AFTER status;

CREATE TABLE IF NOT EXISTS payroll_time_month_import_summaries (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  time_month_id           BIGINT UNSIGNED NOT NULL,
  time_month_revision_no  SMALLINT UNSIGNED NOT NULL,
  employment_id           BIGINT UNSIGNED NOT NULL,
  period_start            DATE NOT NULL,
  attendance_import_id    BIGINT UNSIGNED NOT NULL,
  values_json             LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  worked_days             TINYINT UNSIGNED NULL,
  sources_json            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  content_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by              BIGINT UNSIGNED NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_import_summary_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_time_import_summary_revision
    (supplier_id, time_month_id, time_month_revision_no),
  KEY idx_payroll_time_import_summary_employment (supplier_id, employment_id, period_start),
  KEY idx_payroll_time_import_summary_import (supplier_id, attendance_import_id),
  CONSTRAINT fk_payroll_time_import_summary_month
    FOREIGN KEY (supplier_id, time_month_id)
    REFERENCES payroll_time_months (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_import_summary_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_import_summary_import
    FOREIGN KEY (supplier_id, attendance_import_id)
    REFERENCES payroll_attendance_imports (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_import_summary_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_time_import_summary_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_time_import_summary_values
    CHECK (JSON_VALID(values_json)),
  CONSTRAINT chk_payroll_time_import_summary_sources
    CHECK (JSON_VALID(sources_json)),
  CONSTRAINT chk_payroll_time_import_summary_hash
    CHECK (content_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_time_import_summary_days
    CHECK (worked_days IS NULL OR worked_days <= DAY(LAST_DAY(period_start)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_time_import_summary_no_update
BEFORE UPDATE ON payroll_time_month_import_summaries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll time month import summaries are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_time_import_summary_no_delete
BEFORE DELETE ON payroll_time_month_import_summaries
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll time month import summaries are immutable';
END//

DELIMITER ;
