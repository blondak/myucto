-- MyÚčto.cz — souhrnná karta majetku z účtu bez karet
--
-- Majetek vedený jen v deníku (typicky portfolio pozemků na 031 bez inventárních karet)
-- dostane jednu souhrnnou kartu: vstupní cena je počáteční stav účtu a pohyby deníku
-- jsou její zvýšení a snížení ceny. Karta tak sedí na zůstatek účtu a objeví se
-- v evidenci i inventarizaci. Sloupec nese účet, ze kterého karta vznikla; podle něj
-- se karta při dalších pohybech účtu znovu srovná s deníkem.
--
-- Idempotence: ADD COLUMN / KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE assets
  ADD COLUMN IF NOT EXISTS summary_account_code VARCHAR(20) NULL
      COMMENT 'souhrnná karta účtu bez karet: majetkový účet, jehož pohyby z deníku karta nese; NULL = běžná karta'
      AFTER sale_invoice_id,
  ADD KEY IF NOT EXISTS idx_assets_summary_account (supplier_id, summary_account_code);
