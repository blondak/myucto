-- MyÚčto.cz — MZ-16: samostatné potvrzení o průměrném výdělku jako vlastní
-- druh výstupního dokumentu. Potvrzení pro Úřad práce (§ 313 odst. 2 zákoníku
-- práce) uvádí průměrný měsíční ČISTÝ výdělek, kdežto samostatné potvrzení
-- o průměrném výdělku uvádí hrubý hodinový a hrubý měsíční průměr podle
-- § 356 odst. 1 a 2. Jsou to dva různé doklady se dvěma různými snapshoty,
-- takže i dva různé účely neměnné revize.

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
      'average_earnings_statement',
      'annual_settlement_result',
      'monthly_bundle'
    )
  );

ALTER TABLE payroll_employment_exit_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_exit_purpose;

ALTER TABLE payroll_employment_exit_revisions
  ADD CONSTRAINT chk_payroll_employment_exit_purpose CHECK (
    purpose IN (
      'employment_certificate',
      'average_earnings_certificate',
      'average_earnings_statement'
    )
  );
