-- MyÚčto — platební příkazy pro vratky odběratelům.
--
-- Položka příkazu míří buď na přijatou fakturu (úhrada dodavateli), nebo na
-- vystavený doklad k vyplacení (faktura nebo dobropis s amount_to_pay < 0,
-- viz RefundDocument). Právě jedno z obou ID je vyplněné.
--
-- invoices.payment_ordered_at je obdoba purchase_invoices.payment_ordered_at:
-- „předáno k vyplacení", stav dokladu se nemění, vyplaceno je až spárováním
-- výpisu nebo ručním označením.

SET NAMES utf8mb4;

ALTER TABLE payment_order_items
  MODIFY COLUMN purchase_invoice_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS invoice_id BIGINT UNSIGNED NULL AFTER purchase_invoice_id,
  ADD INDEX IF NOT EXISTS idx_poi_issued_invoice (invoice_id);

ALTER TABLE payment_order_items
  ADD CONSTRAINT fk_poi_invoice FOREIGN KEY IF NOT EXISTS (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT;

ALTER TABLE payment_order_items DROP CONSTRAINT IF EXISTS chk_poi_single_target;
ALTER TABLE payment_order_items
  ADD CONSTRAINT chk_poi_single_target CHECK ((purchase_invoice_id IS NULL) <> (invoice_id IS NULL));

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS payment_ordered_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Kdy byl doklad k vyplacení zařazen do platebního příkazu';
