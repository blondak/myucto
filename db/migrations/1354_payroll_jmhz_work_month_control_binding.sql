-- MyÚčto.cz — MZ-22-W01e-d-b: immutable vazba pracovního souhrnu na katalog kontrol.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD COLUMN IF NOT EXISTS control_catalog_key
    VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER scenario_manifest_sha256,
  ADD COLUMN IF NOT EXISTS control_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER control_catalog_key;

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version = 'jmhz-work-month.v2'
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
