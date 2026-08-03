-- MyÚčto.cz — MZ-16: jednoznačná revize firemních dokumentů i pro NULL employee_id.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  ADD COLUMN IF NOT EXISTS employee_scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(employee_id, 0)) STORED,
  ADD KEY IF NOT EXISTS idx_payroll_document_revision_fk (
    supplier_id,
    revision_id
  );

ALTER TABLE payroll_generated_documents
  DROP INDEX IF EXISTS uq_payroll_document_revision;

ALTER TABLE payroll_generated_documents
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_document_revision (
    supplier_id,
    revision_id,
    document_kind,
    employee_scope_id,
    document_revision_no
  );
