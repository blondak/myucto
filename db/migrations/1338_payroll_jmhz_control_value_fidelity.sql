-- MyÚčto.cz — MZ-22-W1b: bezeztrátová auditní hodnota parametrických konstant.
-- Idempotentní hardening pro vývojové databáze s dřívější podobou migrace 1336.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_control_parameter_values
  ADD COLUMN IF NOT EXISTS source_cell VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin
    NOT NULL DEFAULT '' AFTER parameter_id,
  ADD COLUMN IF NOT EXISTS raw_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin
    NOT NULL DEFAULT 's' AFTER effective_from,
  ADD COLUMN IF NOT EXISTS normalized_value VARCHAR(255) NOT NULL DEFAULT '' AFTER raw_value;

ALTER TABLE payroll_jmhz_control_parameter_values
  ADD CONSTRAINT IF NOT EXISTS chk_jmhz_parameter_value_type
    CHECK (raw_type IN ('n', 's'));
