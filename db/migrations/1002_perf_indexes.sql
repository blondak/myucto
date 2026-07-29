-- MyÚčto.cz — Epic SQL (GitHub #8): výkonový pass po přechodu baseline 10.6 → 11.8
--
-- ČISTĚ ADITIVNÍ MIGRACE: přidává jen chybějící kompozitní B-tree indexy pro
-- EXISTUJÍCÍ horké dotazy (výpisy faktur + reportovací/agregační cesty). Žádná
-- změna schématu sloupců, žádné DROP/RENAME, žádné přepisy dotazů → zero-risk,
-- behavior-preserving, vhodné pro noční autonomní režim.
--
-- Idempotence: CREATE INDEX IF NOT EXISTS (MariaDB 10.6+ / 11.8 native).
--
-- POZOR / caveat: lokální dev tabulky jsou téměř prázdné (invoices ~24 řádků,
-- purchase_invoices ~12), takže EXPLAIN lokálně stále volí full scan — optimizer
-- prostě u tak malých tabulek scan preferuje. Přínos se projeví až na produkci,
-- kde per-tenant počty faktur rostou do tisíců. Pořadí sloupců je voleno podle
-- vzoru WHERE-equality (supplier_id, status, invoice_type) → range/sort (date).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- PŘIDÁVANÉ INDEXY (HIGH/MEDIUM confidence z auditu):
--
-- 1) purchase_invoices (supplier_id, issue_date)  — HIGH win / HIGH confidence
--    Serves: PurchaseInvoiceRepository::listGroupedByMonth (hlavní výpis přijatých
--    faktur): WHERE pi.supplier_id = ? + ORDER BY pi.issue_date DESC, pi.id DESC
--    + rozsahy issue_date (date_from/date_to) + MONTH/YEAR filtry. Dnes existuje
--    jen single-col idx_pi_supplier(supplier_id); issue_date je v uq_pi_vendor_invoice
--    až 4. sloupec → po supplier equality NENÍ seřazeno dle issue_date → filesort.
--    EXPLAIN před: type=ref key=uq_pi_vendor_invoice + "Using filesort".
--
-- 2) invoices (supplier_id, status, invoice_type, tax_date) — HIGH conf. výběru /
--    MEDIUM magnitude. Serves sdílený filtr ~15 agregací:
--    VatLedgerService::fetchSales, CrmAggregationService::{aggregateRange,
--    monthlyHistory, yearlyHistory, currentMonthPipeline}, SummaryAction::{kpi,
--    revenueByYear/Month, rolling12mRevenue, revenueBreakdown12m, topClients…}.
--    Dnes je tenant-led jen single-col idx_inv_supplier → celý per-tenant scan
--    jako residual. EXPLAIN před: type=ALL (full scan).
--
-- 3) invoices (supplier_id, status, issue_date) — HIGH confidence.
--    Serves equality-status + sargovatelný issue_date range:
--    CrmAggregationService::{daysSalesOutstanding, paymentPunctuality},
--    SummaryAction flat-tax / kpi avg (status='paid' AND issue_date >= ?).
--    Dnes padá na idx_inv_status(status, due_date) — NENÍ tenant-scoped, rangeuje
--    přes všechny suppliery. EXPLAIN před: key=idx_inv_status (cross-tenant).
--
-- 4) invoices (supplier_id, status, due_date) — MEDIUM win / MEDIUM confidence.
--    Serves unpaid_only / overdue cesty listGroupedByMonth + dashboard pohledávky:
--    supplier_id = ? + status IN ('issued','sent','reminded') + due_date <= CURDATE().
--    Symetrický protějšek k purchase_invoices.idx_pi_status_due, který invoices
--    dosud chybí. EXPLAIN před: key=idx_inv_status (NOT tenant-scoped).
--
-- ─────────────────────────────────────────────────────────────────────────────
-- ZÁMĚRNĚ VYNECHÁNO (nízká confidence / audit doporučuje odložit):
--
-- • invoices (supplier_id, status, paid_at) — MEDIUM conf., překrývá prefix #3;
--   audit doporučuje přidat až pokud se payment-histogram/cashflow ukáže horký.
-- • purchase_invoices (supplier_id, status, tax_date) — LOW–MEDIUM; efektivní datum
--   nákladů je GREATEST(COALESCE(tax_date,issue_date), issue_date) (non-sargable),
--   idx_pi_status_due už status filtr pokrývá → marginální. Odloženo (viz R2).
-- • clients (supplier_id, archived_at, company_name) — LOW; clients je per-tenant
--   malá, stávající idx_clients_* pokrývají. Přidat až poroste objem klientů.
--
-- ODLOŽENÉ REWRITY (samostatný review PR, NE noční režim):
-- • R1: generated PERSISTENT column effective_tax_date = COALESCE(tax_date, issue_date)
--   na invoices + index → sargovatelný dominantní date range. Vyžaduje edit dotazů
--   (VatLedgerService + Summary/Crm) a běh VAT/dashboard testů.
-- • R2: obdoba pro purchase_invoices (GREATEST(...) → effective_cost_date).
-- • R3: GROUP BY cur.code → GROUP BY currency_id (odstraní temp table/filesort).
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- 1) HIGH — hlavní výpis přijatých faktur (odstraní filesort na issue_date)
CREATE INDEX IF NOT EXISTS idx_pi_supplier_issue
  ON purchase_invoices (supplier_id, issue_date);

-- 2) HIGH-reach — reportovací/VAT agregace: tenant + status + typ (+ tax_date tail)
CREATE INDEX IF NOT EXISTS idx_inv_supplier_status_type_tax
  ON invoices (supplier_id, status, invoice_type, tax_date);

-- 3) HIGH — status='paid' + issue_date range (DSO / punctuality / flat-tax)
CREATE INDEX IF NOT EXISTS idx_inv_supplier_status_issue
  ON invoices (supplier_id, status, issue_date);

-- 4) MEDIUM — tenant-scoped overdue/unpaid (protějšek idx_pi_status_due)
CREATE INDEX IF NOT EXISTS idx_inv_supplier_status_due
  ON invoices (supplier_id, status, due_date);
