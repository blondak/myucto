SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_other_item_bank_update;
DROP TRIGGER IF EXISTS trg_other_item_invoice_payment_insert;
DROP TRIGGER IF EXISTS trg_other_item_invoice_payment_update;
DROP TRIGGER IF EXISTS trg_other_item_purchase_match_insert;
DROP TRIGGER IF EXISTS trg_other_item_purchase_match_update;
DROP TRIGGER IF EXISTS trg_other_item_payroll_match_insert;
DROP TRIGGER IF EXISTS trg_other_item_tax_advance_insert;
DROP TRIGGER IF EXISTS trg_other_item_tax_advance_update;
DROP TRIGGER IF EXISTS trg_other_item_cash_update;
DROP TRIGGER IF EXISTS trg_other_item_allocation_insert;

DELIMITER //

CREATE TRIGGER trg_other_item_bank_update
BEFORE UPDATE ON bank_transactions
FOR EACH ROW
BEGIN
  IF (NEW.matched_invoice_id IS NOT NULL OR NEW.match_status <> 'unmatched')
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = OLD.id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_invoice_payment_insert
BEFORE INSERT ON invoice_payments
FOR EACH ROW
BEGIN
  IF NEW.bank_transaction_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.bank_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_invoice_payment_update
BEFORE UPDATE ON invoice_payments
FOR EACH ROW
BEGIN
  IF NOT (NEW.bank_transaction_id <=> OLD.bank_transaction_id)
     AND NEW.bank_transaction_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.bank_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_purchase_match_insert
BEFORE INSERT ON payment_matches
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.bank_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_purchase_match_update
BEFORE UPDATE ON payment_matches
FOR EACH ROW
BEGIN
  IF NOT (NEW.bank_transaction_id <=> OLD.bank_transaction_id)
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.bank_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_payroll_match_insert
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  IF NEW.bank_transaction_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.bank_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
  IF NEW.cash_document_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE cash_document_id = NEW.cash_document_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item cash evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_tax_advance_insert
BEFORE INSERT ON tax_advance_schedules
FOR EACH ROW
BEGIN
  IF NEW.matched_transaction_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.matched_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_tax_advance_update
BEFORE UPDATE ON tax_advance_schedules
FOR EACH ROW
BEGIN
  IF NOT (NEW.matched_transaction_id <=> OLD.matched_transaction_id)
     AND NEW.matched_transaction_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM other_item_allocations WHERE bank_transaction_id = NEW.matched_transaction_id)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_cash_update
BEFORE UPDATE ON cash_documents
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM other_item_allocations WHERE cash_document_id = OLD.id)
     AND (NEW.status <> 'posted' OR NEW.purpose <> 'other'
          OR NEW.invoice_id IS NOT NULL OR NEW.purchase_invoice_id IS NOT NULL
          OR NEW.invoice_payment_id IS NOT NULL)
  THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Other item cash evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_other_item_allocation_insert
BEFORE INSERT ON other_item_allocations
FOR EACH ROW
BEGIN
  IF NEW.bank_transaction_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM bank_transactions bt
    LEFT JOIN invoice_payments ip ON ip.bank_transaction_id = bt.id
    LEFT JOIN payment_matches pm ON pm.bank_transaction_id = bt.id
    LEFT JOIN payroll_payment_matches ppm ON ppm.bank_transaction_id = bt.id
    LEFT JOIN tax_advance_schedules tas ON tas.matched_transaction_id = bt.id
    WHERE bt.id = NEW.bank_transaction_id
      AND (bt.match_status <> 'unmatched' OR bt.matched_invoice_id IS NOT NULL
           OR ip.id IS NOT NULL OR pm.id IS NOT NULL OR ppm.id IS NOT NULL OR tas.id IS NOT NULL)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bank evidence is already owned';
  END IF;
  IF NEW.cash_document_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM cash_documents cd
    LEFT JOIN payroll_payment_matches ppm ON ppm.cash_document_id = cd.id
    WHERE cd.id = NEW.cash_document_id
      AND (cd.status <> 'posted' OR cd.purpose <> 'other'
           OR cd.invoice_id IS NOT NULL OR cd.purchase_invoice_id IS NOT NULL
           OR cd.invoice_payment_id IS NOT NULL OR ppm.id IS NOT NULL)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cash evidence is already owned';
  END IF;
END//

DELIMITER ;
