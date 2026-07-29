-- MyÚčto.cz — Epic F6: role client + jednotný zámek zaúčtovaných dokladů
--
-- users.role += 'client' (append na konec ENUM = metadata-only ALTER).
--
-- ⚠️ VĚDOMÁ BEHAVIORÁLNÍ ZMĚNA (deklarováno dle review M3): DEFAULT users.role se mění
-- z 'admin' na 'readonly'. Původní default 'admin' je latentní eskalace — každý INSERT
-- bez explicitní role vytvářel admina. UserAdminAction roli posílá vždy explicitně
-- (ověřit při implementaci), takže jediný dopad je bezpečnostně směrem dolů.
--
-- user_suppliers.role se NEROZŠIŘUJE — client je globální role, override se ignoruje.
-- invoices dostávají explicitní příznak zaúčtování (booked_at/booked_by) — jediný
-- mechanismus zámku funkční i pro tax_evidence firmy (bez journalu a období).
-- purchase_invoices.booked_at existuje z 0026 — doplňujeme jen booked_by.
-- booked_by je BIGINT UNSIGNED = typ users.id (review L3, konzistence s
-- journal_entries.posted_by z 1005 kvůli budoucím FK).
--
-- Idempotence: MODIFY je idempotentní, ADD COLUMN IF NOT EXISTS, backfill jen
-- WHERE booked_at IS NULL.

SET NAMES utf8mb4;

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','accountant','readonly','client')
    NOT NULL DEFAULT 'readonly';

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS booked_at TIMESTAMP NULL
    COMMENT 'Zaúčtováno (hook post-invoice nebo ručně účetní) — zámek pro roli client' AFTER status,
  ADD COLUMN IF NOT EXISTS booked_by BIGINT UNSIGNED NULL AFTER booked_at;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS booked_by BIGINT UNSIGNED NULL AFTER booked_at;

-- Backfill: doklady s existujícím aktivním posted zápisem označit jako zaúčtované,
-- aby zámek platil retroaktivně (idempotentní — jen WHERE booked_at IS NULL).
UPDATE invoices i
  JOIN journal_entries je
    ON je.supplier_id = i.supplier_id AND je.source_type = 'invoice'
   AND je.source_id = i.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
   SET i.booked_at = je.posted_at, i.booked_by = je.posted_by
 WHERE i.booked_at IS NULL;

UPDATE purchase_invoices p
  JOIN journal_entries je
    ON je.supplier_id = p.supplier_id AND je.source_type = 'purchase_invoice'
   AND je.source_id = p.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
   SET p.booked_at = je.posted_at, p.booked_by = je.posted_by
 WHERE p.booked_at IS NULL;
