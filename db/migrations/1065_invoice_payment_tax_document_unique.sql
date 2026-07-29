-- EP-2: jeden daňový doklad k přijaté platbě smí být navázán nejvýše na jednu platbu.
-- Vlastní race ochranu doplňuje atomický podmíněný UPDATE v PaymentTaxDocumentCreator.

SET NAMES utf8mb4;

ALTER TABLE invoice_payments
  ADD UNIQUE KEY IF NOT EXISTS uq_invoice_payments_tax_document (tax_document_invoice_id);
