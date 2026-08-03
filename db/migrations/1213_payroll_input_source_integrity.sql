-- MyÚčto.cz — MZ-08: integrita systémově vytvořených mzdových vstupů.

SET NAMES utf8mb4;

ALTER TABLE payroll_inputs
  DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot,
  DROP CONSTRAINT IF EXISTS chk_payroll_input_import_source;

ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
    (
      recurring_component_id IS NULL
      AND source_kind <> 'recurring'
      AND source_snapshot_json IS NULL
      AND source_snapshot_hash IS NULL
    )
    OR
    (
      recurring_component_id IS NOT NULL
      AND source_kind = 'recurring'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
    )
  ),
  ADD CONSTRAINT chk_payroll_input_import_source CHECK (
    (
      source_kind = 'import'
      AND import_id IS NOT NULL
    )
    OR
    (
      source_kind <> 'import'
      AND import_id IS NULL
    )
  );
