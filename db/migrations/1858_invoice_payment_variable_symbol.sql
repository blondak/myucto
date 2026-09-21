-- MyÚčto.cz — samostatný platební variabilní symbol u vydaných faktur.
--
-- PROČ
--
-- `invoices.varsymbol` je zároveň unikátní číslo dokladu, hodnota číselné řady
-- a zdroj platebního VS. Doklad s číslem 20260399 tak nemohl nést platební VS 16
-- (typicky import z iDokladu, kde se DocumentNumber a VariableSymbol liší) ani
-- opakovaný VS u pravidelné fakturace, kdy zákazník platí trvalým příkazem.
-- Přijaté faktury tohle rozlišení už mají (`purchase_invoices.payment_variable_symbol`).
--
-- CO SE ZAVÁDÍ
--
-- `invoices.payment_variable_symbol` — nullable, jen číslice, max 10 znaků.
-- NULL = dnešní chování: platební VS se odvozuje z čísla dokladu
-- (VariableSymbolNormalizer::forPayment). Záměrně BEZ UNIQUE indexu — stejný
-- platební VS smí mít víc faktur. Index slouží párování příchozích plateb.
-- Stejný sloupec dostává šablona pravidelné fakturace, aby ho předávala fakturám.
--
-- Aditivní, idempotentní (ADD COLUMN / INDEX IF NOT EXISTS).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS payment_variable_symbol VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Platební VS odlišný od čísla dokladu; NULL = odvodit z varsymbol'
    AFTER varsymbol;

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_inv_supplier_payment_vs (supplier_id, payment_variable_symbol);

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS payment_variable_symbol VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Platební VS předávaný vygenerovaným fakturám; NULL = odvodit z čísla dokladu'
    AFTER note_below_items;
