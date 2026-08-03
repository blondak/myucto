-- MyÚčto.cz — MZ-06: měsíční schválení, uzamčení a auditované znovuotevření.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_time_months (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employment_id      BIGINT UNSIGNED NOT NULL,
  period_start       DATE NOT NULL,
  status             ENUM('open','approved') NOT NULL DEFAULT 'open',
  revision_no        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  last_changed_by    BIGINT UNSIGNED NULL,
  approved_by        BIGINT UNSIGNED NULL,
  approved_at        DATETIME NULL,
  reopened_by        BIGINT UNSIGNED NULL,
  reopened_at        DATETIME NULL,
  reopen_reason      VARCHAR(500) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_month_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_time_month_period (supplier_id, employment_id, period_start),
  KEY idx_payroll_time_month_status (supplier_id, period_start, status),
  CONSTRAINT fk_payroll_time_month_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_month_changer
    FOREIGN KEY (last_changed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_time_month_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_time_month_reopener
    FOREIGN KEY (reopened_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_time_month_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_time_month_approval
    CHECK (
      (status = 'open' AND approved_at IS NULL)
      OR (status = 'approved' AND approved_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_time_month_events (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  time_month_id      BIGINT UNSIGNED NOT NULL,
  revision_no        SMALLINT UNSIGNED NOT NULL,
  action             ENUM('created','changed','approved','reopened') NOT NULL,
  reason             VARCHAR(500) NULL,
  snapshot_hash      BINARY(32) NOT NULL,
  actor_id           BIGINT UNSIGNED NULL,
  occurred_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_time_event_supplier_id (supplier_id, id),
  KEY idx_payroll_time_event_month (supplier_id, time_month_id, id),
  CONSTRAINT fk_payroll_time_event_month
    FOREIGN KEY (supplier_id, time_month_id)
    REFERENCES payroll_time_months (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_time_event_actor
    FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
