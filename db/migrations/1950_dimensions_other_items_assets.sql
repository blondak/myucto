-- MyÚčto.cz — Dimenze ostatních pohledávek a závazků a karty majetku (Účtování podle dimenzí, F4)
--
-- `document_dimensions` a `document_dimension_splits` dostávají dva další typy dokladu:
--
--   • `other_item`: hlavička ostatní pohledávky nebo závazku (`other_items`). Účtování
--     (zdroj 'other_item') ji razítkuje na všechny řádky zápisu, chybějící typ doplní
--     výchozí dimenze protistrany. Rozvrh opakování kopíruje dimenze zdrojového dokladu.
--   • `asset`: karta dlouhodobého majetku (`assets`). Nese jedinou hodnotu typu nebo
--     rozpad (60/40). Zápisy majetku (zdroje 'asset' = zařazení, 'depreciation' = účetní
--     odpis, 'asset_disposal' = vyřazení) dostanou dimenze karty; technické zhodnocení
--     se promítá přes odpisy a vyřazení.
--
-- Proč karta majetku v `document_dimensions`, ne v `dimension_defaults`: výchozí hodnota
-- je nastavení číselníku, které jen doplní doklad. Dimenze karty ale nesou přímo zápisy
-- odpisů a vyřazení (karta je jejich jediný doklad), potřebují rozpad a jejich změna se
-- promítá do už zaúčtovaných řádků. To vše umí `document_dimensions` +
-- `document_dimension_splits`; `dimension_defaults` rozpad nemá.
--
-- Bez dimenzí na dokladu a kartě se účtuje bajtově stejně jako dřív.
--
-- Idempotentní: MODIFY ENUM jen přidává hodnoty na konec.

SET NAMES utf8mb4;

ALTER TABLE document_dimensions
  MODIFY COLUMN doc_type ENUM('purchase_invoice','invoice','cash_document','bank_transaction','journal_template','recurring_template','other_item','asset') NOT NULL;

ALTER TABLE document_dimension_splits
  MODIFY COLUMN doc_type ENUM('purchase_invoice','invoice','cash_document','bank_transaction','journal_template','recurring_template','other_item','asset') NOT NULL;
