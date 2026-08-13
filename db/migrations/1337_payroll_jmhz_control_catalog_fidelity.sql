-- MyÚčto.cz — MZ-22-W1b: auditní stopa interpretace parametrických vazeb.
-- Idempotentní hardening pro vývojové databáze s dřívější podobou migrace 1336.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_control_parameters
  ADD COLUMN IF NOT EXISTS control_refs_raw VARCHAR(255) NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN IF NOT EXISTS control_refs_formatted VARCHAR(255) NOT NULL DEFAULT '' AFTER control_refs_raw,
  ADD COLUMN IF NOT EXISTS control_refs_anomaly VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER control_refs_formatted;

ALTER TABLE payroll_jmhz_control_parameter_refs
  ADD UNIQUE KEY IF NOT EXISTS uq_jmhz_parameter_ref_control (parameter_id, control_id);
