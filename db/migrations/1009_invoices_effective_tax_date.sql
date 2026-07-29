-- MyÚčto.cz — Epic SQL fáze 2 (R1): sargovatelné efektivní datum tržby na invoices
--
-- COALESCE(tax_date, issue_date) (= DUZP s fallbackem na vystavení) je dnes funkcí
-- obalený v desítkách horkých per-tenant dotazů (SummaryAction, CrmAggregationService,
-- VatLedgerService::fetchSales, MonthlyExportService, InvoiceRepository::listGroupedByMonth,
-- IncomeTaxBuilder). Funkce v WHERE/ORDER = non-sargable → index se nepoužije.
--
-- MariaDB nemá funkcionální/expression indexy (na rozdíl od MySQL 8), takže jediná
-- cesta k sargovatelnosti je PERSISTENT generated column + běžný B-tree index na ní.
-- Precedent: invoices.amount_to_pay je už STORED gen-col.
--
-- issue_date je NOT NULL → gen-col nikdy NULL → sémantika identická s COALESCE(...).
--
-- POZOR: ADD COLUMN ... PERSISTENT = ALGORITHM=COPY (rebuild tabulky, ne INSTANT).
-- Na produkci maintenance window / pt-online-schema-change.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS effective_tax_date DATE
  GENERATED ALWAYS AS (COALESCE(tax_date, issue_date)) PERSISTENT;

-- Reportovací/VAT agregace: tenant + status + typ (+ efektivní datum tail).
-- Sargovatelný protějšek idx_inv_supplier_status_type_tax (fáze 1) pro efektivní datum.
CREATE INDEX IF NOT EXISTS idx_inv_supplier_status_type_efftax
  ON invoices (supplier_id, status, invoice_type, effective_tax_date);

-- Range/ORDER na efektivním datu bez status/typ filtru (listGroupedByMonth, MonthlyExport).
CREATE INDEX IF NOT EXISTS idx_inv_supplier_efftax
  ON invoices (supplier_id, effective_tax_date);
