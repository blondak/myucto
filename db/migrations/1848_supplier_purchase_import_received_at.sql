-- MyÚčto.cz — datum přijetí u importovaných přijatých dokladů (zákaznický požadavek).
--
-- Import přijatých dokladů plnil `received_at` DNEM IMPORTU: AI extrakce z PDF
-- (AiPdfExtractor), iDoklad i Fakturoid psaly natvrdo `date('Y-m-d')`, přestože datum
-- vystavení měly v téže payloadě po ruce. Po migraci historie tak měla celá firma ve
-- sloupci „Datum přijetí" dnešek. Nově se datum přijetí přebírá Z DOKLADU (datum
-- vystavení, při jeho nečitelnosti DUZP) na VŠECH importních cestách — ISDOC, ISDOCX,
-- PDF/A-3 a Pohoda XML to tak přes IsdocToPurchaseInvoiceMapper dělaly už dřív, teď je
-- pravidlo sdílené v {@see \MyInvoice\Service\Import\ImportedReceivedDatePolicy}.
--
-- POZOR — tahle migrace ZÁMĚRNĚ PORUŠUJE domácí zvyk „DEFAULT = dnešní chování"
-- (viz hlavička 1568_supplier_ai_tuning.sql). Výchozí 'issue_date' je NOVÉ chování,
-- protože právě o jeho změnu zákazník požádal; 'import_date' je opt-out pro firmy,
-- které chtějí dosavadní otisk dne importu zachovat.
--
-- Na období nároku na odpočet DPH to NEMÁ vliv: u importu zůstává
-- `received_at_source='import'` (migrace 1037) a VatLedgerService::purchaseClaimDateExpr()
-- i PostingService berou `received_at` v potaz jen u 'manual'. Na uzavřená účetní období
-- taky ne: zámek se řídí `effective_cost_date` (migrace 1010) =
-- GREATEST(COALESCE(tax_date, issue_date), issue_date), do kterého `received_at` nevstupuje.
--
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS purchase_import_received_at
    ENUM('issue_date','import_date') NOT NULL DEFAULT 'issue_date'
    COMMENT 'Datum přijetí u importovaných přijatých dokladů: issue_date = z dokladu (datum vystavení, jinak DUZP) — výchozí; import_date = den importu (chování do migrace 1848)';
