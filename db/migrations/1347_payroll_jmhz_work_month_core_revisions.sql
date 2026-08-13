-- MyÚčto.cz — MZ-22-W01e-d-a: immutable měsíční pracovní jádro JMHZ.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_work_month_revisions (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employment_id              BIGINT UNSIGNED NOT NULL,
  time_month_id              BIGINT UNSIGNED NOT NULL,
  time_month_revision_no     SMALLINT UNSIGNED NOT NULL,
  period_start               DATE NOT NULL,
  spec_package_id            BIGINT UNSIGNED NOT NULL,
  spec_manifest_sha256       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scenario_catalog_key       VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scenario_manifest_sha256   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  derivation_version         VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_snapshot_json       LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  source_snapshot_sha256     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  standard_fund_millihours   INT UNSIGNED NOT NULL,
  agreed_fund_millihours     INT UNSIGNED NOT NULL,
  weekly_work_centihours     INT UNSIGNED NOT NULL,
  evidence_days              TINYINT UNSIGNED NOT NULL,
  worked_millihours          INT UNSIGNED NOT NULL,
  confirmation_note          VARCHAR(500) NOT NULL,
  provenance_json            LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  summary_sha256             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  approved_by                BIGINT UNSIGNED NULL,
  approved_at                DATETIME NOT NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_work_month_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_jmhz_work_month_revision
    (supplier_id, time_month_id, time_month_revision_no),
  KEY idx_payroll_jmhz_work_month_employment
    (supplier_id, employment_id, period_start),
  CONSTRAINT fk_payroll_jmhz_work_month_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_work_month_time_month
    FOREIGN KEY (supplier_id, time_month_id)
    REFERENCES payroll_time_months (supplier_id, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_work_month_spec_package
    FOREIGN KEY (spec_package_id)
    REFERENCES payroll_jmhz_spec_packages (id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_work_month_approver
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_jmhz_work_month_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_jmhz_work_month_source_json
    CHECK (JSON_VALID(source_snapshot_json)),
  CONSTRAINT chk_payroll_jmhz_work_month_provenance_json
    CHECK (JSON_VALID(provenance_json)),
  CONSTRAINT chk_payroll_jmhz_work_month_source_hash
    CHECK (source_snapshot_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_work_month_spec_hash
    CHECK (spec_manifest_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_work_month_scenario_hash
    CHECK (scenario_manifest_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_work_month_summary_hash
    CHECK (summary_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_jmhz_work_month_standard_fund
    CHECK (standard_fund_millihours <= 9999999),
  CONSTRAINT chk_payroll_jmhz_work_month_agreed_fund
    CHECK (agreed_fund_millihours <= 9999999),
  CONSTRAINT chk_payroll_jmhz_work_month_weekly_work
    CHECK (weekly_work_centihours <= 9999999),
  CONSTRAINT chk_payroll_jmhz_work_month_worked
    CHECK (worked_millihours <= 99999999),
  CONSTRAINT chk_payroll_jmhz_work_month_evidence_days
    CHECK (evidence_days <= DAY(LAST_DAY(period_start))),
  CONSTRAINT chk_payroll_jmhz_work_month_confirmation_note
    CHECK (CHAR_LENGTH(TRIM(confirmation_note)) BETWEEN 5 AND 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_time_month_events
  ADD COLUMN IF NOT EXISTS jmhz_work_summary_revision_id BIGINT UNSIGNED NULL
    AFTER snapshot_hash,
  ADD COLUMN IF NOT EXISTS jmhz_work_summary_hash
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER jmhz_work_summary_revision_id;

ALTER TABLE payroll_time_month_events
  DROP FOREIGN KEY IF EXISTS fk_payroll_time_event_jmhz_work_summary;

ALTER TABLE payroll_time_month_events
  ADD CONSTRAINT fk_payroll_time_event_jmhz_work_summary
    FOREIGN KEY (supplier_id, jmhz_work_summary_revision_id)
    REFERENCES payroll_jmhz_work_month_revisions (supplier_id, id)
    ON DELETE RESTRICT;

ALTER TABLE payroll_time_month_events
  DROP CONSTRAINT IF EXISTS chk_payroll_time_event_jmhz_work_summary;

ALTER TABLE payroll_time_month_events
  ADD CONSTRAINT chk_payroll_time_event_jmhz_work_summary CHECK (
    (
      jmhz_work_summary_revision_id IS NULL
      AND jmhz_work_summary_hash IS NULL
    ) OR (
      action = 'approved'
      AND jmhz_work_summary_revision_id IS NOT NULL
      AND jmhz_work_summary_hash REGEXP '^[0-9a-f]{64}$'
    )
  );

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_work_month_no_update
BEFORE UPDATE ON payroll_jmhz_work_month_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ work month revisions are immutable';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_jmhz_work_month_no_delete
BEFORE DELETE ON payroll_jmhz_work_month_revisions
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ work month revisions are immutable';
END//

DELIMITER ;
