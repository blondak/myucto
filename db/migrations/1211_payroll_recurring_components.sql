-- MyÚčto.cz — MZ-08: bezpečné předpisy opakovaných mzdových složek.

SET NAMES utf8mb4;

ALTER TABLE payroll_recurring_components
  MODIFY COLUMN amount_minor BIGINT NULL,
  ADD COLUMN IF NOT EXISTS calculation_kind
    ENUM('fixed_amount','employment_gross_basis_points','manual_review')
    NOT NULL DEFAULT 'fixed_amount' AFTER component_id,
  ADD COLUMN IF NOT EXISTS rate_basis_points INT UNSIGNED NULL AFTER amount_minor,
  ADD COLUMN IF NOT EXISTS note VARCHAR(500) NULL AFTER maximum_amount_minor,
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER note,
  ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER row_version,
  ADD COLUMN IF NOT EXISTS updated_by BIGINT UNSIGNED NULL AFTER created_by,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_recurring_version
    (supplier_id, employment_id, component_id, valid_from),
  ADD CONSTRAINT fk_payroll_recurring_created_by
    FOREIGN KEY IF NOT EXISTS (created_by) REFERENCES users (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_payroll_recurring_updated_by
    FOREIGN KEY IF NOT EXISTS (updated_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE payroll_recurring_components
  DROP CONSTRAINT IF EXISTS chk_payroll_recurring_calculation,
  DROP CONSTRAINT IF EXISTS chk_payroll_recurring_active;

ALTER TABLE payroll_recurring_components
  ADD CONSTRAINT chk_payroll_recurring_calculation CHECK (
    (
      calculation_kind = 'fixed_amount'
      AND amount_minor IS NOT NULL
      AND rate_basis_points IS NULL
    )
    OR
    (
      calculation_kind = 'employment_gross_basis_points'
      AND amount_minor IS NULL
      AND rate_basis_points BETWEEN 1 AND 10000
    )
    OR
    (
      calculation_kind = 'manual_review'
      AND amount_minor IS NULL
      AND rate_basis_points IS NULL
    )
  ),
  ADD CONSTRAINT chk_payroll_recurring_active CHECK (is_active IN (0, 1));

ALTER TABLE payroll_inputs
  ADD COLUMN IF NOT EXISTS recurring_component_id BIGINT UNSIGNED NULL AFTER import_id,
  ADD COLUMN IF NOT EXISTS source_snapshot_json LONGTEXT NULL AFTER recurring_component_id,
  ADD COLUMN IF NOT EXISTS source_snapshot_hash BINARY(32) NULL AFTER source_snapshot_json,
  ADD KEY IF NOT EXISTS idx_payroll_input_recurring
    (supplier_id, recurring_component_id, period_start),
  ADD CONSTRAINT fk_payroll_input_recurring
    FOREIGN KEY IF NOT EXISTS (supplier_id, recurring_component_id)
    REFERENCES payroll_recurring_components (supplier_id, id) ON DELETE RESTRICT;

ALTER TABLE payroll_inputs
  DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;

ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
    (
      recurring_component_id IS NULL
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
  );
