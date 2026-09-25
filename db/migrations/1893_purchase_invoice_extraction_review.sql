-- MyÚčto.cz — strukturované podklady ke kontrole AI vytěženého dokladu.
--
-- Návrhy druhu nákladu z AI extrakce se dosud dostaly k uživateli jen jako věta
-- v `extraction_warning` („• řádek 3 … Služba (AI, jistota 40 %)"). Kontrolní okno
-- po importu ale potřebuje vědět, KTERÝ řádek návrh nese a co navrhuje, aby řádek
-- zvýraznilo a návrh šel převzít jedním klikem. Text by se musel zpětně parsovat.
--
-- JSON: {"expense_kinds":[{"order_index":0,"kind":"service","confidence":0.4,"reason":"…"}]}
-- Klíčem je `order_index` řádku. Maže se spolu s hlášením (Beru na vědomí).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS extraction_review JSON NULL
    COMMENT 'strukturované návrhy AI extrakce ke kontrole (druh nákladu po řádcích)'
    AFTER extraction_warning;
