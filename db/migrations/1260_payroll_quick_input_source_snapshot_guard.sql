-- MZ-08 — zachování povinného protokolu pravidelných mzdových vstupů.

SET NAMES utf8mb4;

ALTER TABLE payroll_inputs
  DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;

ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
    (
      recurring_component_id IS NOT NULL
      AND source_kind = 'recurring'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
      AND OCTET_LENGTH(source_snapshot_hash) = 32
    )
    OR
    (
      recurring_component_id IS NULL
      AND source_kind <> 'recurring'
      AND (
        (
          source_snapshot_json IS NULL
          AND source_snapshot_hash IS NULL
        )
        OR
        (
          source_kind = 'manual'
          AND external_id LIKE 'quick-monthly:%'
          AND source_snapshot_json IS NOT NULL
          AND JSON_VALID(source_snapshot_json)
          AND source_snapshot_hash IS NOT NULL
          AND OCTET_LENGTH(source_snapshot_hash) = 32
        )
      )
    )
  );
