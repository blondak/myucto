-- MZ-07 — vstupy a výsledek nároku dovolené, vždy s ručním právním posouzením.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_leave_entitlement_snapshots (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employment_id              BIGINT UNSIGNED NOT NULL,
  leave_year                 SMALLINT UNSIGNED NOT NULL,
  revision_no                INT UNSIGNED NOT NULL DEFAULT 1,
  relation_type              ENUM(
    'employment','small_scale_employment','dpp','dpc','partner_dependent','statutory_body'
  ) NOT NULL,
  weekly_minutes             INT UNSIGNED NOT NULL,
  entitlement_weeks         SMALLINT UNSIGNED NOT NULL,
  continuous_calendar_days   SMALLINT UNSIGNED NOT NULL,
  worked_equivalent_minutes  INT UNSIGNED NOT NULL,
  worked_week_multiples      SMALLINT UNSIGNED NOT NULL,
  entitlement_minutes        INT UNSIGNED NOT NULL,
  rationale                  VARCHAR(1000) NOT NULL,
  support_status             ENUM('manual_review') NOT NULL DEFAULT 'manual_review',
  input_hash                 BINARY(32) NOT NULL,
  calculation_trace          JSON NOT NULL,
  leave_ledger_entry_id      BIGINT UNSIGNED NULL,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                 INT UNSIGNED NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_leave_snapshot_tenant_id (supplier_id, id),
  UNIQUE KEY uq_payroll_leave_snapshot_revision
    (supplier_id, employment_id, leave_year, revision_no),
  KEY idx_payroll_leave_snapshot_ledger (supplier_id, leave_ledger_entry_id),
  CONSTRAINT fk_payroll_leave_snapshot_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_leave_snapshot_ledger
    FOREIGN KEY (supplier_id, leave_ledger_entry_id)
    REFERENCES payroll_leave_ledger (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_leave_snapshot_inputs CHECK (
    weekly_minutes > 0
    AND entitlement_weeks > 0
    AND continuous_calendar_days > 0
    AND worked_equivalent_minutes > 0
    AND worked_week_multiples > 0
    AND entitlement_minutes > 0
  ),
  CONSTRAINT chk_payroll_leave_snapshot_reason CHECK (CHAR_LENGTH(TRIM(rationale)) > 0),
  CONSTRAINT chk_payroll_leave_snapshot_trace CHECK (JSON_VALID(calculation_trace))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
