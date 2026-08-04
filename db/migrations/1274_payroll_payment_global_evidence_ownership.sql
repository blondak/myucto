-- MyÚčto.cz — MZ-17: globální vlastnictví bankovních a pokladních důkazů.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_match_global_evidence_insert;
DROP TRIGGER IF EXISTS trg_invoice_payment_payroll_evidence_insert;
DROP TRIGGER IF EXISTS trg_invoice_payment_payroll_evidence_update;
DROP TRIGGER IF EXISTS trg_payment_match_payroll_evidence_insert;
DROP TRIGGER IF EXISTS trg_payment_match_payroll_evidence_update;
DROP TRIGGER IF EXISTS trg_bank_transaction_payroll_evidence_update;
DROP TRIGGER IF EXISTS trg_cash_document_payroll_evidence_update;

DELIMITER //

CREATE TRIGGER trg_payroll_match_global_evidence_insert
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  IF NEW.bank_transaction_id IS NOT NULL THEN
    IF EXISTS (
      SELECT 1
        FROM invoice_payments invoice_payment
       WHERE invoice_payment.bank_transaction_id = NEW.bank_transaction_id
    ) OR EXISTS (
      SELECT 1
        FROM payment_matches payment_match
       WHERE payment_match.bank_transaction_id = NEW.bank_transaction_id
    ) OR EXISTS (
      SELECT 1
        FROM bank_transactions bank_transaction
       WHERE bank_transaction.id = NEW.bank_transaction_id
         AND (
           bank_transaction.matched_invoice_id IS NOT NULL
           OR bank_transaction.match_status <> 'unmatched'
         )
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
    END IF;
  ELSEIF EXISTS (
    SELECT 1
      FROM cash_documents cash_document
     WHERE cash_document.id = NEW.cash_document_id
       AND (
         cash_document.purpose <> 'other'
         OR cash_document.invoice_id IS NOT NULL
         OR cash_document.purchase_invoice_id IS NOT NULL
         OR cash_document.invoice_payment_id IS NOT NULL
       )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll cash evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_invoice_payment_payroll_evidence_insert
BEFORE INSERT ON invoice_payments
FOR EACH ROW
BEGIN
  IF NEW.bank_transaction_id IS NOT NULL AND EXISTS (
    SELECT 1
      FROM payroll_payment_matches payroll_match
     WHERE payroll_match.bank_transaction_id = NEW.bank_transaction_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_invoice_payment_payroll_evidence_update
BEFORE UPDATE ON invoice_payments
FOR EACH ROW
BEGIN
  IF NOT (NEW.bank_transaction_id <=> OLD.bank_transaction_id)
     AND NEW.bank_transaction_id IS NOT NULL
     AND EXISTS (
       SELECT 1
         FROM payroll_payment_matches payroll_match
        WHERE payroll_match.bank_transaction_id = NEW.bank_transaction_id
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_payment_match_payroll_evidence_insert
BEFORE INSERT ON payment_matches
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_payment_matches payroll_match
     WHERE payroll_match.bank_transaction_id = NEW.bank_transaction_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_payment_match_payroll_evidence_update
BEFORE UPDATE ON payment_matches
FOR EACH ROW
BEGIN
  IF NOT (NEW.bank_transaction_id <=> OLD.bank_transaction_id)
     AND EXISTS (
       SELECT 1
         FROM payroll_payment_matches payroll_match
        WHERE payroll_match.bank_transaction_id = NEW.bank_transaction_id
     )
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_bank_transaction_payroll_evidence_update
BEFORE UPDATE ON bank_transactions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_payment_matches payroll_match
     WHERE payroll_match.bank_transaction_id = OLD.id
  ) AND (
    NEW.matched_invoice_id IS NOT NULL
    OR NEW.match_status <> 'unmatched'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll bank evidence is already owned';
  END IF;
END//

CREATE TRIGGER trg_cash_document_payroll_evidence_update
BEFORE UPDATE ON cash_documents
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_payment_matches payroll_match
     WHERE payroll_match.supplier_id = OLD.supplier_id
       AND payroll_match.cash_document_id = OLD.id
  ) AND (
    NEW.purpose <> 'other'
    OR NEW.invoice_id IS NOT NULL
    OR NEW.purchase_invoice_id IS NOT NULL
    OR NEW.invoice_payment_id IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll cash evidence is already owned';
  END IF;
END//

DELIMITER ;
