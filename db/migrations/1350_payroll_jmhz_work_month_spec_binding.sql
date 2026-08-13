-- MyÚčto.cz — MZ-22-W01e-d-a: vazba pracovního souhrnu na připnutou specifikaci.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS spec_package_id BIGINT UNSIGNED NULL
    AFTER period_start,
  ADD COLUMN IF NOT EXISTS spec_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER spec_package_id,
  ADD COLUMN IF NOT EXISTS scenario_catalog_key
    VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER spec_manifest_sha256,
  ADD COLUMN IF NOT EXISTS scenario_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER scenario_catalog_key;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP FOREIGN KEY IF EXISTS fk_payroll_jmhz_work_month_spec_package;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT fk_payroll_jmhz_work_month_spec_package
    FOREIGN KEY (spec_package_id)
    REFERENCES payroll_jmhz_spec_packages (id)
    ON DELETE RESTRICT;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_spec_hash,
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_scenario_hash;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_spec_hash
    CHECK (spec_manifest_sha256 REGEXP '^[0-9a-f]{64}$'),
  ADD CONSTRAINT chk_payroll_jmhz_work_month_scenario_hash
    CHECK (scenario_manifest_sha256 REGEXP '^[0-9a-f]{64}$');

ALTER TABLE payroll_jmhz_work_month_revisions
  MODIFY COLUMN spec_package_id BIGINT UNSIGNED NOT NULL,
  MODIFY COLUMN spec_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN scenario_catalog_key
    VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY COLUMN scenario_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
