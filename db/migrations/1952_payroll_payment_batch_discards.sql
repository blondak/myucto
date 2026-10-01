-- MyÚčto.cz - zahození mzdové platební dávky.
--
-- PROČ
--
-- Dávka mzdových plateb (`payroll_payment_batches`, položky, alokace) je
-- záměrně neměnná: triggery zakazují UPDATE i DELETE, protože je to doklad
-- o tom, co se mělo zaplatit a co odešlo do banky. Jenže alokace dávky drží
-- závazky jako „zařazené" navždy. Dávku s chybným datem, dávku, kterou banka
-- odmítla, nebo dávku, kterou účetní v bankovnictví zrušila, tak nešlo nahradit
-- novou: závazky už nebyly k dispozici a nová dávka by je přealokovala.
--
-- U příkazů přijatých faktur se nepředaný příkaz maže a předaný archivuje
-- (`payment_orders.archived_at`). Mzdová dávka se fyzicky smazat nesmí, proto
-- se zahození vede vedle - stejně jako skrytí revize exportu
-- (`payroll_payment_export_hidden`, migrace 1707). Dávka, položky, alokace
-- i všechny revize exportu zůstávají auditně dohledatelné; zahozená dávka jen
-- přestane držet závazky a nedá se už exportovat, stáhnout, předat bance ani
-- spárovat s úhradou.
--
-- Zahodit nejde dávku, ke které je doložená úhrada (spárovaná platba). To
-- hlídá aplikace i trigger níže.
--
-- IDEMPOTENCE
--
-- `CREATE TABLE IF NOT EXISTS`; triggery `DROP TRIGGER IF EXISTS` + `CREATE`.
-- Trigger alokace je převzatý z 1738 (včetně COLLATE u proměnných) a liší se
-- jen tím, že do obsazenosti závazku nepočítá alokace zahozených dávek.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_payment_batch_discards (
  supplier_id                 INT UNSIGNED NOT NULL,
  batch_id                    BIGINT UNSIGNED NOT NULL,
  handover_state              ENUM('none','downloaded','submitted') NOT NULL,
  bank_cancellation_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  discarded_by                BIGINT UNSIGNED NULL,
  discarded_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, batch_id),
  CONSTRAINT fk_payroll_payment_batch_discard_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_payment_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_batch_discard_user
    FOREIGN KEY (discarded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_payment_batch_discard_confirmation CHECK (
    handover_state = 'none' OR bank_cancellation_confirmed = 1
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_payment_batch_discard_validate_insert//
CREATE TRIGGER trg_payroll_payment_batch_discard_validate_insert
BEFORE INSERT ON payroll_payment_batch_discards
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_payment_items item
      JOIN payroll_payment_allocations allocation
        ON allocation.supplier_id = item.supplier_id
       AND allocation.item_id = item.id
      JOIN payroll_payment_matches payment_match
        ON payment_match.supplier_id = allocation.supplier_id
       AND payment_match.allocation_id = allocation.id
     WHERE item.supplier_id = NEW.supplier_id
       AND item.batch_id = NEW.batch_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment batch with recorded settlement cannot be discarded';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_batch_discard_immutable_update//
CREATE TRIGGER trg_payroll_payment_batch_discard_immutable_update
BEFORE UPDATE ON payroll_payment_batch_discards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment batch discards are immutable';
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_batch_discard_immutable_delete//
CREATE TRIGGER trg_payroll_payment_batch_discard_immutable_delete
BEFORE DELETE ON payroll_payment_batch_discards
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment batch discards are append-only';
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_allocation_validate_insert//
CREATE TRIGGER trg_payroll_payment_allocation_validate_insert
BEFORE INSERT ON payroll_payment_allocations
FOR EACH ROW
BEGIN
  DECLARE item_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE item_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE item_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE item_batch_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE allocated_to_item BIGINT UNSIGNED DEFAULT 0;
  DECLARE allocated_to_liability BIGINT UNSIGNED DEFAULT 0;

  SELECT item.amount_minor, batch.direction, batch.currency_code, batch.id
    INTO item_amount, item_direction, item_currency, item_batch_id
    FROM payroll_payment_items item
    JOIN payroll_payment_batches batch
      ON batch.supplier_id = item.supplier_id
     AND batch.id = item.batch_id
   WHERE item.supplier_id = NEW.supplier_id
     AND item.id = NEW.item_id
   FOR UPDATE;

  SELECT liability.amount_minor, liability.direction, liability.currency_code
    INTO liability_amount, liability_direction, liability_currency
    FROM payroll_payment_liabilities liability
   WHERE liability.supplier_id = NEW.supplier_id
     AND liability.id = NEW.liability_id
   FOR UPDATE;

  IF item_amount IS NULL OR liability_amount IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment allocation target is missing';
  END IF;
  IF item_direction <> liability_direction OR item_currency <> liability_currency THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment allocation direction or currency differs';
  END IF;
  IF EXISTS (
    SELECT 1 FROM payroll_payment_batch_discards discard
     WHERE discard.supplier_id = NEW.supplier_id
       AND discard.batch_id = item_batch_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment batch is discarded';
  END IF;

  SELECT COALESCE(SUM(allocation.amount_minor), 0)
    INTO allocated_to_item
    FROM payroll_payment_allocations allocation
   WHERE allocation.supplier_id = NEW.supplier_id
     AND allocation.item_id = NEW.item_id;
  SELECT COALESCE(SUM(allocation.amount_minor), 0)
    INTO allocated_to_liability
    FROM payroll_payment_allocations allocation
    JOIN payroll_payment_items allocation_item
      ON allocation_item.supplier_id = allocation.supplier_id
     AND allocation_item.id = allocation.item_id
   WHERE allocation.supplier_id = NEW.supplier_id
     AND allocation.liability_id = NEW.liability_id
     AND NOT EXISTS (
       SELECT 1 FROM payroll_payment_batch_discards discard
        WHERE discard.supplier_id = allocation_item.supplier_id
          AND discard.batch_id = allocation_item.batch_id
     );

  IF allocated_to_item + NEW.amount_minor > item_amount THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment item is overallocated';
  END IF;
  IF allocated_to_liability + NEW.amount_minor > liability_amount THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability is overallocated';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_match_discard_guard//
CREATE TRIGGER trg_payroll_payment_match_discard_guard
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  IF NEW.allocation_id IS NOT NULL AND EXISTS (
    SELECT 1
      FROM payroll_payment_allocations allocation
      JOIN payroll_payment_items item
        ON item.supplier_id = allocation.supplier_id
       AND item.id = allocation.item_id
      JOIN payroll_payment_batch_discards discard
        ON discard.supplier_id = item.supplier_id
       AND discard.batch_id = item.batch_id
     WHERE allocation.supplier_id = NEW.supplier_id
       AND allocation.id = NEW.allocation_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment batch is discarded';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_payment_export_discard_guard//
CREATE TRIGGER trg_payroll_payment_export_discard_guard
BEFORE INSERT ON payroll_payment_exports
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM payroll_payment_batch_discards discard
     WHERE discard.supplier_id = NEW.supplier_id
       AND discard.batch_id = NEW.batch_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment batch is discarded';
  END IF;
END//

DELIMITER ;
