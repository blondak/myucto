-- MyÚčto.cz — MZ-22-W01e-b: effective-dated evidence jádra vykonávané pozice JMHZ.

SET NAMES utf8mb4;

ALTER TABLE payroll_employment_terms
  ADD COLUMN IF NOT EXISTS jmhz_workplace_municipality_code
    CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER regular_workplace,
  ADD COLUMN IF NOT EXISTS jmhz_workplace_country_code
    CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER jmhz_workplace_municipality_code,
  ADD COLUMN IF NOT EXISTS jmhz_apz_contribution_status
    ENUM('unverified','no','yes') NOT NULL DEFAULT 'unverified'
    AFTER jmhz_workplace_country_code,
  ADD COLUMN IF NOT EXISTS jmhz_apz_instrument_code
    VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER jmhz_apz_contribution_status,
  ADD COLUMN IF NOT EXISTS jmhz_functional_benefits_status
    ENUM('unverified','no','yes') NOT NULL DEFAULT 'unverified'
    AFTER jmhz_apz_instrument_code,
  ADD COLUMN IF NOT EXISTS jmhz_temporary_assignment_status
    ENUM('unverified','no','yes') NOT NULL DEFAULT 'unverified'
    AFTER jmhz_functional_benefits_status;

ALTER TABLE payroll_employment_terms
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_jmhz_workplace,
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_jmhz_apz;

ALTER TABLE payroll_employment_terms
  ADD CONSTRAINT chk_payroll_employment_jmhz_workplace CHECK (
    (
      jmhz_workplace_municipality_code IS NULL
      AND jmhz_workplace_country_code IS NULL
    ) OR (
      work_place IS NOT NULL
      AND jmhz_workplace_municipality_code IS NOT NULL
      AND jmhz_workplace_country_code IS NOT NULL
      AND
      CHAR_LENGTH(TRIM(work_place)) BETWEEN 1 AND 255
      AND jmhz_workplace_municipality_code REGEXP '^[0-9]{6}$'
      AND jmhz_workplace_country_code REGEXP '^[A-Z]{2}$'
    )
  ),
  ADD CONSTRAINT chk_payroll_employment_jmhz_apz CHECK (
    (
      jmhz_apz_contribution_status = 'yes'
      AND jmhz_apz_instrument_code IS NOT NULL
      AND jmhz_apz_instrument_code IN ('1','2','3','4')
    ) OR (
      jmhz_apz_contribution_status IN ('unverified','no')
      AND jmhz_apz_instrument_code IS NULL
    )
  );
