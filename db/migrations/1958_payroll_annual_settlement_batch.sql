-- MyÚčto.cz — hromadné roční zúčtování záloh (§ 38ch ZDP) přes frontu ročních dokumentů.
--
-- Roční zúčtování šlo dosud provést jen po jednom člověku. Firma se stovkami
-- žadatelů ho teď zařadí do téže serverové fronty, kterou jedou mzdové listy
-- a potvrzení o zdanitelných příjmech (1652): jedna položka na žadatele,
-- pronájem, pokusy a přehled výsledků.
--
-- Druh dávky nese hodnotu `annual_settlement_result` — tu, pod kterou se
-- archivuje PDF dokladu o zúčtování (`payroll_generated_documents.document_kind`).
-- Úspěšná položka tak ukazuje přímo na ten doklad.
--
-- MODIFY COLUMN vypisuje všechny dosavadní hodnoty, takže opakované spuštění
-- nic nemění.

SET NAMES utf8mb4;

ALTER TABLE payroll_annual_document_batches
  MODIFY COLUMN document_kind ENUM(
    'payroll_sheet',
    'taxable_income_advance_certificate',
    'taxable_income_withholding_certificate',
    'annual_settlement_result'
  ) NOT NULL;
