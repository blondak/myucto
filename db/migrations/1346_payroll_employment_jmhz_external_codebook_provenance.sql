-- MyÚčto.cz — MZ-22-W01e-c: provenance autoritativních externích číselníků JMHZ.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_external_codebook_overlay_key
    VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER jmhz_workplace_country_code,
  ADD COLUMN IF NOT EXISTS jmhz_external_codebook_manifest_sha256
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER jmhz_external_codebook_overlay_key;

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_jmhz_external_codebook_provenance;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_jmhz_external_codebook_provenance CHECK (
    (
      jmhz_external_codebook_overlay_key IS NULL
      AND jmhz_external_codebook_manifest_sha256 IS NULL
    ) OR (
      jmhz_workplace_municipality_code IS NOT NULL
      AND jmhz_workplace_country_code IS NOT NULL
      AND jmhz_external_codebook_overlay_key IS NOT NULL
      AND jmhz_external_codebook_manifest_sha256 IS NOT NULL
      AND jmhz_external_codebook_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
