-- MyÚčto.cz — Epic SKLAD „u dodavatele" (fáze 3): rozšíření nabídek dodavatelů.
--
-- Mapa produkt↔dodavatel (`stock_item_vendors`, migrace 1028 §10) už nese nákupní
-- cenu, měnu, kód u dodavatele, dodací lhůtu a hlášenou skladovost. Chybí jí to,
-- co z ní dělá použitelnou NABÍDKU: od kdy je hlášené množství, jestli je zboží
-- vůbec dostupné, kolik se musí objednat najednou a odkud data přišla.
--
-- ZÁMĚRNĚ SE NEZAKLÁDÁ NOVÁ TABULKA. Nabídka dodavatele není jiná entita než
-- vazba produkt↔dodavatel — je to tatáž řádka viděná z druhé strany. Druhá
-- tabulka by znamenala dva zdroje pravdy o nákupní ceně a `PurchaseCostResolver`
-- by musel řešit, který vyhrává.
--
-- ── Ke sloupcům ──────────────────────────────────────────────────────────────
-- `availability_state`   … co dodavatel hlásí. 'unknown' je výchozí, protože
--                          existující řádky z 1028 o dostupnosti nic neříkají
--                          a tvářit se, že jsou skladem, by bylo horší než mlčet.
-- `stock_qty_updated_at` … kdy naposled někdo množství potvrdil. Je INFORMATIVNÍ:
--                          hlášená skladovost platí, dokud ji dodavatel nezmění —
--                          žádný práh stárnutí, žádné automatické znehodnocení
--                          (rozhodnutí #7 plánu). Slouží k dohledání a exportu.
-- `min_order_qty`        … minimální objednávka; `package_qty` … balení, na které
--                          se objednávané množství zaokrouhluje nahoru.
-- `price_valid_to`       … do kdy platí ceníková cena (NULL = bez omezení).
-- `data_source`          … 'manual' ruční zápis, 'import' ceník z XLSX/CSV,
--                          'feed' automatický kanál. Rozlišení je potřeba, aby
--                          import nepřepisoval to, co člověk zadal vědomě.
-- `is_active`            … vyřazená nabídka zůstává kvůli historii, ale nenabízí
--                          se; mazání by rozbilo dohledatelnost, proč se kdysi
--                          nakupovalo za tuhle cenu.
--
-- Cenová historie se v1 nezavádí — na otázku „za kolik jsme to kdy kupovali"
-- odpovídá `stock_document_lines.unit_cost` u zaúčtovaných příjemek, což je
-- průkaznější než ceníkový záznam, který nikdo nemusel použít.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS (vzor 1023).

SET NAMES utf8mb4;

ALTER TABLE stock_item_vendors
  ADD COLUMN IF NOT EXISTS availability_state ENUM('in_stock','on_order','unavailable','unknown')
      NOT NULL DEFAULT 'unknown' AFTER stock_qty,
  ADD COLUMN IF NOT EXISTS stock_qty_updated_at DATETIME NULL AFTER availability_state,
  ADD COLUMN IF NOT EXISTS min_order_qty  DECIMAL(14,3) NULL,
  ADD COLUMN IF NOT EXISTS package_qty    DECIMAL(14,3) NULL,
  ADD COLUMN IF NOT EXISTS price_valid_to DATE NULL,
  ADD COLUMN IF NOT EXISTS data_source    ENUM('manual','import','feed') NOT NULL DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS is_active      TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_siv_offer     ON stock_item_vendors (supplier_id, is_active, availability_state);
CREATE INDEX IF NOT EXISTS idx_siv_staleness ON stock_item_vendors (supplier_id, stock_qty_updated_at);
