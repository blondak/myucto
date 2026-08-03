-- MyÚčto.cz — MZ-08: CSV/XLSX import jednorázových mzdových vstupů.

SET NAMES utf8mb4;

ALTER TABLE payroll_input_imports
  MODIFY COLUMN source_kind ENUM('csv','xlsx','api') NOT NULL,
  MODIFY COLUMN status ENUM('preview','accepted','partial','rejected') NOT NULL DEFAULT 'preview',
  ADD COLUMN IF NOT EXISTS duplicate_count INT UNSIGNED NOT NULL DEFAULT 0
    AFTER rejected_count,
  ADD COLUMN IF NOT EXISTS row_version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER duplicate_count,
  ADD COLUMN IF NOT EXISTS accepted_at DATETIME NULL AFTER created_at;

ALTER TABLE payroll_input_imports
  DROP INDEX IF EXISTS uq_payroll_input_import_hash,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_input_import_period_hash
    (supplier_id, period_start, content_hash);

ALTER TABLE payroll_input_imports
  DROP CONSTRAINT IF EXISTS chk_payroll_input_import_counts;

ALTER TABLE payroll_input_imports
  ADD CONSTRAINT chk_payroll_input_import_counts CHECK (
    accepted_count + rejected_count + duplicate_count <= row_count
  );

ALTER TABLE payroll_input_import_rows
  MODIFY COLUMN status ENUM('valid','error','accepted','duplicate') NOT NULL,
  ADD COLUMN IF NOT EXISTS input_id BIGINT UNSIGNED NULL AFTER import_id,
  ADD KEY IF NOT EXISTS idx_payroll_input_import_row_input (supplier_id, input_id),
  ADD CONSTRAINT fk_payroll_input_import_row_input
    FOREIGN KEY IF NOT EXISTS (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT;
