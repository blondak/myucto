-- MyÚčto.cz — Epic SKLAD „na cestě", fáze 2: cross-modulové vazby objednávky.
--
-- Izolováno od 1330 stejným způsobem jako 1023 od 1022: 1330 zakládá výhradně
-- fork-owned tabulky, tenhle soubor sahá na tabulky sdílené s upstreamem
-- (stock_documents, stock_document_lines, purchase_invoice_items, supplier).
--
-- Klíčový sloupec celého epicu je stock_document_lines.purchase_order_line_id:
-- z NĚJ (ne z řádku faktury) se počítá „už přijato", protože příjemka je po
-- zaúčtování neměnná, kdežto purchase_invoice_items se při editaci faktury
-- přepisuje celý (SetPurchaseInvoiceItemsAction je replace-all → vazba by osiřela).
-- Storno příjemky vytvoří protidoklad doc_type='issue' se STEJNÝM
-- purchase_order_line_id a odvozovací dotaz ho odečte → zboží se vrátí „na cestu".
-- Vyžaduje, aby StockDocumentService::reverse() ten sloupec kopíroval (InTransitTest).
--
-- Idempotence: IF NOT EXISTS / DROP FOREIGN KEY IF EXISTS (MariaDB 10.5+).

SET NAMES utf8mb4;

-- ── Příjemka ↔ řádek objednávky (autoritativní zdroj „přijato") ──────────────
ALTER TABLE stock_document_lines
  ADD COLUMN IF NOT EXISTS purchase_order_line_id BIGINT UNSIGNED NULL AFTER purchase_invoice_item_id;
ALTER TABLE stock_document_lines DROP FOREIGN KEY IF EXISTS fk_sdl_pol;
ALTER TABLE stock_document_lines ADD CONSTRAINT fk_sdl_pol
  FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_sdl_pol ON stock_document_lines (purchase_order_line_id);

-- ── Hlavička příjemky ↔ objednávka (origin='purchase_order') ────────────────
ALTER TABLE stock_documents
  ADD COLUMN IF NOT EXISTS purchase_order_id BIGINT UNSIGNED NULL AFTER purchase_invoice_id;
ALTER TABLE stock_documents DROP FOREIGN KEY IF EXISTS fk_sd_po;
ALTER TABLE stock_documents ADD CONSTRAINT fk_sd_po
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_sd_po ON stock_documents (purchase_order_id);

-- ── Řádek faktury ↔ řádek objednávky (jen zobrazení odchylek) ───────────────
-- Vazba se při replace-all editaci faktury ztratí; to je vědomé, autoritou je
-- příjemka výš. Slouží k porovnání ceny/množství faktura vs. objednávka.
ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS purchase_order_line_id BIGINT UNSIGNED NULL AFTER stock_item_id;
ALTER TABLE purchase_invoice_items DROP FOREIGN KEY IF EXISTS fk_pii_pol;
ALTER TABLE purchase_invoice_items ADD CONSTRAINT fk_pii_pol
  FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL;

-- ── Od kterého stavu objednávky se počítá „na cestě" ────────────────────────
-- Default 'sent' (rozhodnutí #2): odeslaná objednávka už je pro plánování nákupu
-- závazek. Firma, která věří až potvrzení dodavatelem, přepne na 'confirmed'.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS stock_in_transit_from ENUM('sent','confirmed') NOT NULL DEFAULT 'sent';

-- ── Tolerance cenové odchylky faktura vs. objednávka (rozhodnutí #8) ────────
-- Jen varuje, nikdy neblokuje; práh si určí uživatel.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS stock_order_price_tolerance_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00;
