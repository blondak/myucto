-- MZ-07 — absence a jejich schvalovací životní cyklus bez diagnóz.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_absences (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employment_id              BIGINT UNSIGNED NOT NULL,
  absence_type               ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle','other'
  ) NOT NULL,
  date_from                  DATE NOT NULL,
  date_to                    DATE NOT NULL,
  timezone_name              VARCHAR(64) NOT NULL DEFAULT 'Europe/Prague',
  partial_first_minutes      INT UNSIGNED NULL,
  partial_last_minutes       INT UNSIGNED NULL,
  note                       VARCHAR(1000) NULL,
  compensation_policy       ENUM(
    'none','average_100','average_custom','dpn','statutory_manual_review'
  ) NOT NULL DEFAULT 'statutory_manual_review',
  compensation_rate_basis_points SMALLINT UNSIGNED NULL,
  average_snapshot_id        BIGINT UNSIGNED NULL,
  support_status             ENUM('supported','manual_review','not_supported')
    NOT NULL DEFAULT 'manual_review',
  status                     ENUM('requested','approved','rejected','cancelled')
    NOT NULL DEFAULT 'requested',
  correction_pending         TINYINT(1) NOT NULL DEFAULT 0,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  requested_by               INT UNSIGNED NULL,
  decided_by                 INT UNSIGNED NULL,
  decided_at                 DATETIME NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_absence_tenant_id (supplier_id, id),
  KEY idx_payroll_absence_period (supplier_id, employment_id, date_from, date_to, status),
  KEY idx_payroll_absence_average (supplier_id, average_snapshot_id),
  CONSTRAINT fk_payroll_absence_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_absence_average
    FOREIGN KEY (supplier_id, average_snapshot_id)
    REFERENCES payroll_average_earning_snapshots (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_absence_interval CHECK (date_to >= date_from),
  CONSTRAINT chk_payroll_absence_partial_first CHECK (
    partial_first_minutes IS NULL OR partial_first_minutes > 0
  ),
  CONSTRAINT chk_payroll_absence_partial_last CHECK (
    partial_last_minutes IS NULL OR partial_last_minutes > 0
  ),
  CONSTRAINT chk_payroll_absence_rate CHECK (
    compensation_rate_basis_points IS NULL
    OR compensation_rate_basis_points BETWEEN 1 AND 10000
  ),
  CONSTRAINT chk_payroll_absence_correction CHECK (correction_pending IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
