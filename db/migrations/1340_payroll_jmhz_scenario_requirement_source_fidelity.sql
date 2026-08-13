-- MyÚčto.cz — MZ-22-W1c: souřadnice lexikální vazby interakce.
-- Idempotentní hardening pro vývojové databáze s dřívější podobou migrace 1339.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_interaction_attribute_refs
  ADD COLUMN IF NOT EXISTS source_cell VARCHAR(32) NOT NULL DEFAULT '' AFTER ordinal;
