-- MyÚčto.cz — `invoices.pdf_path` na relativní cestu (audit 2026-07, N-016)
--
-- Sloupec držel ABSOLUTNÍ cesty, a to ze dvou různých produkčních kořenů současně
-- (22× `ucto.example.cz`, 21× `invoice.example.cz` — instance byla mezitím
-- přejmenována). Žádná z těch 43 cest už netrefila existující soubor.
--
-- Není to katastrofa jen shodou okolností: sloupec je CACHE renderovaného PDF
-- (`InvoicePdfRenderer` si soubor ověřuje přes `is_file()` + mtime a při nesouladu
-- PDF přegeneruje). Netrefená cesta tedy znamená zbytečné přegenerování, ne ztracený
-- doklad. Rozbije se ale pokaždé, když se instance přesune, přejmenuje nebo obnoví
-- ze zálohy do jiného prostředí — tedy přesně tam, kde se to nejhůř hledá.
--
-- Přijatá větev (`purchase_invoices.pdf_path`) ukládá relativní cesty odjakživa;
-- tohle je dorovnání obou větví na stejný tvar. Nově zapisuje relativní cestu
-- `InvoicePdfRenderer::toRelativePdfPath()`, čtení jde přes `resolvePdfPath()`,
-- která legacy absolutní hodnoty snese (řádky ze záloh a jiných prostředí).
--
-- Idempotence: obě UPDATE jsou opakovatelně spustitelné — po prvním běhu už žádný
-- řádek nesplní podmínku (relativní cesta neobsahuje ani `/storage/invoices/`
-- s prefixem, ani dvojtečku, ani úvodní lomítko).

SET NAMES utf8mb4;

-- 1) Cesty, ve kterých je poznat kořen → uřízni všechno až po `storage/invoices/`.
--    Backslashe se normalizují na lomítka, aby to platilo i pro Windows zápis.
UPDATE invoices
   SET pdf_path = SUBSTRING(
           REPLACE(pdf_path, '\\', '/'),
           LOCATE('/storage/invoices/', REPLACE(pdf_path, '\\', '/')) + LENGTH('/storage/invoices/')
       )
 WHERE pdf_path IS NOT NULL
   AND pdf_path <> ''
   AND LOCATE('/storage/invoices/', REPLACE(pdf_path, '\\', '/')) > 0;

-- 2) Zbytek, který je pořád absolutní (jiný layout, disk, UNC) → vynuluj.
--    Cache se prostě přegeneruje při prvním otevření faktury; `pdf_generated_at`
--    musí jít s ní, jinak by orphan-recovery větev v rendereru sáhla po starém
--    souboru na deterministické cestě a vrátila stale PDF.
UPDATE invoices
   SET pdf_path = NULL,
       pdf_generated_at = NULL
 WHERE pdf_path IS NOT NULL
   AND pdf_path <> ''
   AND (
        LOCATE(':', pdf_path) > 0
     OR LEFT(REPLACE(pdf_path, '\\', '/'), 1) = '/'
   );
