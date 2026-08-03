-- MZ-07 — append-only ledger dovolené; opravy jsou nové reverzní položky.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_leave_ledger (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  leave_year            SMALLINT UNSIGNED NOT NULL,
  effective_date        DATE NOT NULL,
  entry_type            ENUM(
    'entitlement','carryover','taken','adjustment','shortening','overdrawn','payout','reversal'
  ) NOT NULL,
  minutes_delta         INT NOT NULL,
  source_absence_id     BIGINT UNSIGNED NULL,
  reversal_of_id        BIGINT UNSIGNED NULL,
  reason                VARCHAR(1000) NOT NULL,
  support_status        ENUM('supported','manual_review') NOT NULL DEFAULT 'manual_review',
  source_hash           BINARY(32) NOT NULL,
  created_by            INT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_leave_ledger_tenant_id (supplier_id, id),
  UNIQUE KEY uq_payroll_leave_absence_type
    (supplier_id, source_absence_id, entry_type),
  UNIQUE KEY uq_payroll_leave_reversal
    (supplier_id, reversal_of_id),
  KEY idx_payroll_leave_balance
    (supplier_id, employment_id, leave_year, effective_date, id),
  CONSTRAINT fk_payroll_leave_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_leave_absence
    FOREIGN KEY (supplier_id, source_absence_id)
    REFERENCES payroll_absences (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_leave_reversal
    FOREIGN KEY (supplier_id, reversal_of_id)
    REFERENCES payroll_leave_ledger (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_leave_delta CHECK (minutes_delta <> 0),
  CONSTRAINT chk_payroll_leave_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
  CONSTRAINT chk_payroll_leave_reversal_shape CHECK (
    (entry_type = 'reversal' AND reversal_of_id IS NOT NULL)
    OR (entry_type <> 'reversal' AND reversal_of_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
