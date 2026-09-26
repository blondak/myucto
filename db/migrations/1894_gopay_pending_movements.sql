-- MyÚčto.cz - GoPay úhrada se účtuje dnem platby, ne až importem vyúčtování.
--
-- Úhrada faktury platebním tlačítkem (invoice_payments.bank_reference 'GOPAY:<id>')
-- hned založí čekající pohyb bez vyúčtování (clearing_id NULL, origin 'payment')
-- a zaúčtuje ho MD GoPay účet / D 311 k datu platby. Import vyúčtování pak tento
-- pohyb převezme (doplní clearing_id a externí ID), místo aby účtoval podruhé.

SET NAMES utf8mb4;

ALTER TABLE gopay_movements
  MODIFY clearing_id BIGINT UNSIGNED NULL,
  MODIFY external_id VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS origin ENUM('clearing','payment') NOT NULL DEFAULT 'clearing' AFTER clearing_id,
  ADD COLUMN IF NOT EXISTS currency CHAR(3) NULL AFTER amount,
  ADD KEY IF NOT EXISTS idx_gopay_movement_pending (supplier_id, clearing_id, origin);
