-- MZ-07 — neměnné čtvrtletní snapshoty průměrného výdělku.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_average_earning_snapshots (
  id                           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                  INT UNSIGNED NOT NULL,
  employment_id                BIGINT UNSIGNED NOT NULL,
  applicable_year              SMALLINT UNSIGNED NOT NULL,
  applicable_quarter           TINYINT UNSIGNED NOT NULL,
  revision_no                  INT UNSIGNED NOT NULL DEFAULT 1,
  source_kind                  ENUM('actual','probable') NOT NULL,
  decisive_from                DATE NOT NULL,
  decisive_to                  DATE NOT NULL,
  gross_earnings_minor         BIGINT UNSIGNED NOT NULL,
  longer_period_allocated_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  worked_minutes               INT UNSIGNED NOT NULL,
  worked_days                  SMALLINT UNSIGNED NOT NULL,
  average_hourly_minor         BIGINT UNSIGNED NOT NULL,
  rationale                    VARCHAR(1000) NULL,
  support_status               ENUM('supported','manual_review') NOT NULL DEFAULT 'manual_review',
  status                       ENUM('draft','manual_review','approved','superseded') NOT NULL DEFAULT 'manual_review',
  ruleset_id                   VARCHAR(128) NOT NULL,
  ruleset_hash                 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  input_hash                   BINARY(32) NOT NULL,
  input_trace                  JSON NOT NULL,
  row_version                  INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                   INT UNSIGNED NULL,
  approved_by                  INT UNSIGNED NULL,
  approved_at                  DATETIME NULL,
  created_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_average_tenant_id (supplier_id, id),
  UNIQUE KEY uq_payroll_average_revision
    (supplier_id, employment_id, applicable_year, applicable_quarter, revision_no),
  KEY idx_payroll_average_current
    (supplier_id, employment_id, applicable_year, applicable_quarter, status),
  CONSTRAINT fk_payroll_average_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_average_quarter CHECK (applicable_quarter BETWEEN 1 AND 4),
  CONSTRAINT chk_payroll_average_interval CHECK (decisive_to >= decisive_from),
  CONSTRAINT chk_payroll_average_actual_input CHECK (
    source_kind <> 'actual' OR (worked_minutes > 0 AND worked_days >= 21)
  ),
  CONSTRAINT chk_payroll_average_probable_rationale CHECK (
    source_kind <> 'probable' OR (rationale IS NOT NULL AND CHAR_LENGTH(TRIM(rationale)) > 0)
  ),
  CONSTRAINT chk_payroll_average_trace CHECK (JSON_VALID(input_trace))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
