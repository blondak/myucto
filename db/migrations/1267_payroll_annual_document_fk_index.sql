-- MyÚčto.cz — MZ-16: samostatný FK index ročního dokumentového anchoru.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  ADD KEY IF NOT EXISTS idx_payroll_document_annual_revision (
    supplier_id,
    annual_revision_id
  );
