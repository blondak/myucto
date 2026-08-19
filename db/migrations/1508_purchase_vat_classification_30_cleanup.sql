-- MyÚčto.cz — issue #30: úklid chybně auto-přiřazeného kódu '30' na přijatých dokladech
--
-- VatClassificationDefaulter u přijatého dokladu s nulovou sazbou sahal do DB lookupu.
-- Jediný purchase kód se sazbou 0,00 a bez reverse charge je '30' (pořízení zboží
-- prostřední osobou při třístranném obchodu, § 17) → KAŽDÝ nulový doklad (osvobozené
-- plnění, nákup od neplátce, poplatek úřadu) dostal '30' a mířil na ř. 30 přiznání.
-- Oprava logiky je ve VatClassificationDefaulter::defaultForPurchase()
-- + PurchaseInvoiceRepository::defaultClassificationCode(); tady se čistí data.
--
-- KOHO ČISTIT: třístranný obchod má vždy DVĚ nohy — prostřední osoba zboží pořídí
-- (ř. 30, kód '30') a týmž zbožím dodá dál (ř. 31, kód '31' + souhrnné hlášení s kódem
-- plnění 2). Pořízení bez jediného dodání v celé firmě proto NEMŮŽE být třístranný
-- obchod — je to auto-default. Firmy, které '31' někdy použily, se nechávají být:
-- tam může jít o skutečný § 17 a data se nesmí přepsat naslepo.
--
-- Idempotence: po prvním běhu už žádný takový řádek nezůstane; opakování je no-op.

SET NAMES utf8mb4;

-- Hlavičky přijatých dokladů
UPDATE purchase_invoices pi
   SET pi.vat_classification_code = NULL
 WHERE pi.vat_classification_code = '30'
   AND NOT EXISTS (
        SELECT 1 FROM invoices i
         WHERE i.supplier_id = pi.supplier_id
           AND i.vat_classification_code = '31'
   )
   AND NOT EXISTS (
        SELECT 1 FROM invoice_items ii
          JOIN invoices i2 ON i2.id = ii.invoice_id
         WHERE i2.supplier_id = pi.supplier_id
           AND ii.vat_classification_code = '31'
   );

-- Řádky přijatých dokladů
UPDATE purchase_invoice_items pii
  JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
   SET pii.vat_classification_code = NULL
 WHERE pii.vat_classification_code = '30'
   AND NOT EXISTS (
        SELECT 1 FROM invoices i
         WHERE i.supplier_id = pi.supplier_id
           AND i.vat_classification_code = '31'
   )
   AND NOT EXISTS (
        SELECT 1 FROM invoice_items ii
          JOIN invoices i2 ON i2.id = ii.invoice_id
         WHERE i2.supplier_id = pi.supplier_id
           AND ii.vat_classification_code = '31'
   );
