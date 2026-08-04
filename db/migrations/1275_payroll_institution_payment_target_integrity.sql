-- MyÚčto.cz — MZ-17: fail-closed integrita institucionálních platebních cílů.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_institution_account_payment_insert;
DROP TRIGGER IF EXISTS trg_payroll_institution_account_payment_update;

DELIMITER //

CREATE TRIGGER trg_payroll_institution_account_payment_insert
BEFORE INSERT ON payroll_institution_accounts
FOR EACH ROW
BEGIN
  IF NEW.row_version < 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target row version is invalid';
  END IF;
  IF NEW.verified_by IS NULL OR NEW.verified_on IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target verification is incomplete';
  END IF;
END//

CREATE TRIGGER trg_payroll_institution_account_payment_update
BEFORE UPDATE ON payroll_institution_accounts
FOR EACH ROW
BEGIN
  IF NOT (
    NEW.bank_account_ciphertext <=> OLD.bank_account_ciphertext
    AND NEW.bank_account_hash <=> OLD.bank_account_hash
    AND NEW.currency_code <=> OLD.currency_code
    AND NEW.variable_symbol <=> OLD.variable_symbol
    AND NEW.specific_symbol <=> OLD.specific_symbol
    AND NEW.constant_symbol <=> OLD.constant_symbol
    AND NEW.valid_from <=> OLD.valid_from
    AND NEW.valid_to <=> OLD.valid_to
    AND NEW.source_kind <=> OLD.source_kind
    AND NEW.source_reference <=> OLD.source_reference
    AND NEW.verified_on <=> OLD.verified_on
    AND NEW.verified_by <=> OLD.verified_by
  ) AND OLD.bank_account_ciphertext <> 'pending:v1'
    AND NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target change requires a new row version';
  END IF;
  IF NEW.verified_by IS NULL OR NEW.verified_on IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll institution payment target verification is incomplete';
  END IF;
END//

DELIMITER ;
