-- MyUcto.cz - MZ-22-W02b: frozen scenario_1 selector evidence.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_relationship_detail_code
    CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER activity_code;

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_jmhz_relationship_detail;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_jmhz_relationship_detail CHECK (
    jmhz_relationship_detail_code IS NULL
    OR (
      activity_code IN ('1','2','3','4','5','6','7','8','9')
      AND jmhz_relationship_detail_code IN ('1','2','3')
    )
  );

ALTER TABLE payroll_jmhz_preparation_snapshots
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder;

ALTER TABLE payroll_jmhz_preparation_snapshots
  ADD CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
    builder_version IN (
      'jmhz-preparation-source.v1',
      'jmhz-preparation-source.v2'
    )
  );
