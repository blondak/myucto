-- MyÚčto.cz — MZ-03: zákaz odstranění politiky spolu s auditní stopou.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_policy_audit
  DROP FOREIGN KEY IF EXISTS fk_payroll_employer_policy_audit_policy;

ALTER TABLE payroll_employer_policy_audit
  ADD CONSTRAINT fk_payroll_employer_policy_audit_policy
    FOREIGN KEY (supplier_id, policy_id)
    REFERENCES payroll_employer_policies (supplier_id, id)
    ON DELETE RESTRICT;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_delete//

CREATE TRIGGER trg_payroll_employer_policy_delete
BEFORE DELETE ON payroll_employer_policies
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll employer policies are retained for audit';
END//

DELIMITER ;
