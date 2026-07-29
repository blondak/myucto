-- MyÚčto.cz — oprava vazby doklad ↔ aktivní zápis v účetním deníku.
-- Idempotentní backfill nekonzistencí vzniklých po původní migraci 1021.

SET NAMES utf8mb4;

UPDATE invoices i
  JOIN journal_entries je
    ON je.supplier_id = i.supplier_id AND je.source_type = 'invoice'
   AND je.source_id = i.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
   SET i.booked_at = je.posted_at,
       i.booked_by = COALESCE(i.booked_by, je.posted_by)
 WHERE i.booked_at IS NULL;

UPDATE purchase_invoices p
  JOIN journal_entries je
    ON je.supplier_id = p.supplier_id AND je.source_type = 'purchase_invoice'
   AND je.source_id = p.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
   SET p.booked_at = je.posted_at,
       p.booked_by = COALESCE(p.booked_by, je.posted_by)
 WHERE p.booked_at IS NULL;
