-- MyÚčto.cz — MZ-16: právně odlišné druhy potvrzení o zdanitelných příjmech.

SET NAMES utf8mb4;

ALTER TABLE payroll_generated_documents
  DROP CONSTRAINT IF EXISTS chk_payroll_document_kind;

ALTER TABLE payroll_generated_documents
  ADD CONSTRAINT chk_payroll_document_kind CHECK (
    document_kind IN (
      'payslip',
      'payroll_sheet',
      'taxable_income_advance_certificate',
      'taxable_income_withholding_certificate',
      'employment_certificate',
      'average_earnings_certificate',
      'monthly_bundle'
    )
  );
