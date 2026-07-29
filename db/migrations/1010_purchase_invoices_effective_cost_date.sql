-- MyÚčto.cz — Epic SQL fáze 2 (R2): sargovatelné datum nákladu na purchase_invoices
--
-- Datum uznání nákladu (cost/dashboard rodina) = pozdější z (DUZP, vystavení) =
-- GREATEST(COALESCE(tax_date, issue_date), issue_date). Dnes funkcí obalené v horkých
-- dotazech PurchaseSummaryAction (~10 sites), CrmAggregationService COST_DATE a
-- IncomeTaxBuilder costs → non-sargable.
--
-- POZOR — dvě různé definice efektivního data u přijatých faktur:
--   (1) cost/dashboard  = GREATEST(COALESCE(tax_date, issue_date), issue_date)  ← TATO gen-col
--   (2) VAT ledger/export = CASE s reverse-charge dle země dodavatele (JOIN countries)
--       → NELZE gen-col (závisí na JOINu). Proto VatLedgerService::fetchPurchases a
--       MonthlyExportService::findPurchaseInvoices ZŮSTÁVAJÍ na CASE, beze změny.
--
-- issue_date je NOT NULL → GREATEST nikdy NULL → gen-col nikdy NULL, sémantika identická.
--
-- POZOR: ADD COLUMN ... PERSISTENT = ALGORITHM=COPY (rebuild tabulky, ne INSTANT).
-- Na produkci maintenance window / pt-online-schema-change.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS effective_cost_date DATE
  GENERATED ALWAYS AS (GREATEST(COALESCE(tax_date, issue_date), issue_date)) PERSISTENT;

-- Dashboard/náklady agregace: tenant + status + efektivní datum nákladu.
CREATE INDEX IF NOT EXISTS idx_pi_supplier_status_effcost
  ON purchase_invoices (supplier_id, status, effective_cost_date);
