-- MyÚčto.cz — MZ-22-W1c: agregované fingerprinty zdrojových vzorců a cached hodnot.
-- Idempotentní hardening pro vývojové databáze s dřívější podobou migrace 1339.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_matrix_evidence_axes
  ADD COLUMN IF NOT EXISTS source_sheet VARCHAR(128) NOT NULL DEFAULT '' AFTER source_column,
  ADD COLUMN IF NOT EXISTS nonempty_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER explicit_cell_count,
  ADD COLUMN IF NOT EXISTS dictionary_formula_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER raw_vector_sha256,
  ADD COLUMN IF NOT EXISTS dictionary_formula_vector_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER dictionary_formula_count,
  ADD COLUMN IF NOT EXISTS dictionary_cached_vector_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER dictionary_formula_vector_sha256,
  ADD COLUMN IF NOT EXISTS master_match_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER dictionary_cached_vector_sha256,
  ADD COLUMN IF NOT EXISTS master_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER master_match_count;

ALTER TABLE payroll_jmhz_field_requirements
  MODIFY COLUMN source_cell VARCHAR(128) NOT NULL;

ALTER TABLE payroll_jmhz_matrix_evidence_axes
  DROP CONSTRAINT IF EXISTS chk_jmhz_matrix_evidence_source_fidelity;

ALTER TABLE payroll_jmhz_matrix_evidence_axes
  ADD CONSTRAINT chk_jmhz_matrix_evidence_source_fidelity CHECK (
    (axis_kind = 'reconciliation' AND source_sheet = 'SLOVNÍK'
      AND dictionary_formula_vector_sha256 IS NOT NULL
      AND dictionary_cached_vector_sha256 IS NOT NULL
      AND master_match_count + master_mismatch_count = dimension_count)
    OR (axis_kind = 'derived_binary' AND source_sheet = 'MASTER'
      AND dictionary_formula_count = 0
      AND dictionary_formula_vector_sha256 IS NULL
      AND dictionary_cached_vector_sha256 IS NULL
      AND master_match_count = 0 AND master_mismatch_count = 0)
  );
