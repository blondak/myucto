-- MyÚčto.cz — vazba dobropisu na původní přijatou fakturu.
--
-- PROČ: u přijatých dokladů dnes NEEXISTUJE způsob, jak zjistit, kterou fakturu dobropis
-- opravuje. Vyplavalo to na evidenci drobného majetku (§DM): vrácený monitor se má projevit
-- VYŘAZENÍM původní karty, ne kartou se zápornou cenou — jenže „původní karta" se bez vazby
-- musí hádat.
--
-- Hádat podle názvu NELZE. Reálný stav v datech: tři karty „Monitor 40" Dell Ultrasharp
-- U4025QW" (PF 105 z 2. 10. za 34 819,26 · PF 89 z 3. 10. za 33 490,06 · dobropis PF 104
-- z 4. 10. za −34 819,26). Shoda na název trefí nejstarší kartu, což je jen náhodou správně,
-- a u dvou kusů téhož zboží je to čirá loterie.
--
-- NÁZEV SLOUPCE se drží konvence VYDANÝCH faktur, kde tatáž vazba existuje už dnes:
-- `invoices.parent_invoice_id` je generický rodič (proforma → finální, storno → původní,
-- dobropis → původní; viz CancelInvoiceAction). Uvnitř `purchase_invoices` je zavedený infix
-- `_purchase_` (`advance_purchase_invoice_id` = zálohová → finální), takže dobropisová vazba
-- je `parent_purchase_invoice_id`. Dvě různá pojmenování téhož vztahu = past.
--
-- Vodítko pro předvyplnění: dobropis PF 104 nese TÝŽ `vendor_invoice_number` (2962891954)
-- jako faktura PF 105, kterou opravuje. Je to ale jen NÁVRH, ne pravda — dodavatel může
-- dobropis očíslovat vlastní řadou (PF 51 v našich datech takový je a zůstane nenavázaný).
-- Proto se vazba za běhu NEODVOZUJE, jen jednorázově předvyplní tam, kde je kandidát
-- JEDINÝ, a uživatel ji může kdykoli přepsat v editoru dokladu.
--
-- ON DELETE SET NULL: smazání faktury nesmí shodit dobropis, jen ho odpojí. RESTRICT by
-- zablokoval mazání dokladů, CASCADE by tiše smazal doklad, který je sám o sobě platný.
-- (Pozn.: `invoices.parent_invoice_id` má CASCADE, protože tam je rodičem proforma, bez níž
-- finální doklad nedává smysl. Tady je rodičem běžná faktura a dobropis přežívá i sám.)

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN parent_purchase_invoice_id BIGINT UNSIGNED NULL
      COMMENT 'dobropis opravuje tuto přijatou fakturu; NULL = nenavázáno'
      AFTER advance_paid_amount,
  ADD CONSTRAINT fk_pi_parent FOREIGN KEY (parent_purchase_invoice_id)
      REFERENCES purchase_invoices (id) ON DELETE SET NULL,
  ADD KEY idx_pi_parent (parent_purchase_invoice_id);

-- Předvyplnění dle shodného čísla dokladu u téhož dodavatele. Jen tam, kde je kandidát
-- JEDINÝ — při víc shodách by to byl dohad a ten do dat nepatří.
UPDATE purchase_invoices cn
   SET cn.parent_purchase_invoice_id = (
       SELECT orig.id FROM (SELECT * FROM purchase_invoices) orig
        WHERE orig.supplier_id = cn.supplier_id
          AND orig.vendor_id = cn.vendor_id
          AND orig.document_kind = 'invoice'
          AND orig.vendor_invoice_number = cn.vendor_invoice_number
          AND orig.id <> cn.id
        LIMIT 1
   )
 WHERE cn.document_kind = 'credit_note'
   AND cn.parent_purchase_invoice_id IS NULL
   AND cn.vendor_invoice_number IS NOT NULL
   AND (
       SELECT COUNT(*) FROM (SELECT * FROM purchase_invoices) o2
        WHERE o2.supplier_id = cn.supplier_id
          AND o2.vendor_id = cn.vendor_id
          AND o2.document_kind = 'invoice'
          AND o2.vendor_invoice_number = cn.vendor_invoice_number
          AND o2.id <> cn.id
   ) = 1;
