-- MZ-07 — DPN a reprodukovatelná stopa plánovaných směn prvních 14 dnů.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sickness_events (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                     INT UNSIGNED NOT NULL,
  absence_id                      BIGINT UNSIGNED NOT NULL,
  first_day_fully_worked          TINYINT(1) NOT NULL DEFAULT 0,
  insurance_eligibility_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  conflicting_benefit_excluded    TINYINT(1) NOT NULL DEFAULT 0,
  average_snapshot_id             BIGINT UNSIGNED NOT NULL,
  compensation_window_from        DATE NOT NULL,
  compensation_window_to          DATE NOT NULL,
  reduced_hourly_minor            BIGINT UNSIGNED NOT NULL,
  compensation_minor              BIGINT UNSIGNED NOT NULL,
  support_status                  ENUM('manual_review','supported') NOT NULL DEFAULT 'manual_review',
  ruleset_id                      VARCHAR(128) NOT NULL,
  ruleset_hash                    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  calculation_trace              JSON NOT NULL,
  row_version                     INT UNSIGNED NOT NULL DEFAULT 1,
  calculated_by                  INT UNSIGNED NULL,
  created_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_sickness_tenant_id (supplier_id, id),
  UNIQUE KEY uq_payroll_sickness_absence (supplier_id, absence_id),
  KEY idx_payroll_sickness_average (supplier_id, average_snapshot_id),
  CONSTRAINT fk_payroll_sickness_absence
    FOREIGN KEY (supplier_id, absence_id)
    REFERENCES payroll_absences (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_sickness_average
    FOREIGN KEY (supplier_id, average_snapshot_id)
    REFERENCES payroll_average_earning_snapshots (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_sickness_window CHECK (
    compensation_window_to >= compensation_window_from
    AND DATEDIFF(compensation_window_to, compensation_window_from) <= 13
  ),
  CONSTRAINT chk_payroll_sickness_flags CHECK (
    first_day_fully_worked IN (0, 1)
    AND insurance_eligibility_confirmed IN (0, 1)
    AND conflicting_benefit_excluded IN (0, 1)
  ),
  CONSTRAINT chk_payroll_sickness_trace CHECK (JSON_VALID(calculation_trace))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_sickness_compensation_segments (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  sickness_event_id        BIGINT UNSIGNED NOT NULL,
  shift_id                 BIGINT UNSIGNED NULL,
  local_date               DATE NOT NULL,
  planned_minutes          INT UNSIGNED NOT NULL,
  eligible_minutes         INT UNSIGNED NOT NULL,
  hourly_average_minor     BIGINT UNSIGNED NOT NULL,
  reduced_hourly_minor     BIGINT UNSIGNED NOT NULL,
  compensation_minor       BIGINT UNSIGNED NOT NULL,
  trace                    JSON NOT NULL,

  UNIQUE KEY uq_payroll_sickness_segment_tenant_id (supplier_id, id),
  UNIQUE KEY uq_payroll_sickness_segment_shift
    (supplier_id, sickness_event_id, shift_id),
  KEY idx_payroll_sickness_segment_event (supplier_id, sickness_event_id, local_date),
  CONSTRAINT fk_payroll_sickness_segment_event
    FOREIGN KEY (supplier_id, sickness_event_id)
    REFERENCES payroll_sickness_events (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_sickness_segment_minutes CHECK (
    planned_minutes > 0 AND eligible_minutes > 0 AND eligible_minutes <= planned_minutes
  ),
  CONSTRAINT chk_payroll_sickness_segment_trace CHECK (JSON_VALID(trace))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
