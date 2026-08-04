-- MyÚčto.cz — MZ-04: hardening osobní karty před prvním vydáním.
--
-- 1191 nebyla vydaná. Přesto migrace fail-closed zachová databázi, ve které by
-- někdo mezitím zapsal plaintext kontakt: automaticky jej nemaže ani nepředstírá,
-- že umí bez aplikačního klíče provést bezpečný převod.

SET NAMES utf8mb4;

ALTER TABLE payroll_employee_profiles
  ADD COLUMN IF NOT EXISTS payout_effective_on DATE NULL
    AFTER cash_allocation_basis_points;

ALTER TABLE payroll_person_identifiers
  MODIFY COLUMN identifier_type
    ENUM(
      'birth_number',
      'ecp',
      'vcp',
      'foreign_tax_identifier'
    ) NOT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_identifier_tenant_hash
    (supplier_id, identifier_type, value_hash);

ALTER TABLE payroll_person_contacts
  ADD COLUMN IF NOT EXISTS contact_value_ciphertext VARCHAR(512) NULL
    AFTER contact_type,
  ADD COLUMN IF NOT EXISTS contact_value_hash BINARY(32) NULL
    AFTER contact_value_ciphertext,
  ADD COLUMN IF NOT EXISTS contact_value_masked VARCHAR(191) NULL
    AFTER contact_value_hash;

DELIMITER //

DROP PROCEDURE IF EXISTS migrate_payroll_contacts_1193//

CREATE PROCEDURE migrate_payroll_contacts_1193()
BEGIN
  DECLARE plaintext_column_count INT DEFAULT 0;
  DECLARE plaintext_row_count BIGINT DEFAULT 0;

  SELECT COUNT(*)
    INTO plaintext_column_count
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'payroll_person_contacts'
     AND COLUMN_NAME = 'contact_value';

  IF plaintext_column_count > 0 THEN
    SELECT COUNT(*)
      INTO plaintext_row_count
      FROM payroll_person_contacts
     WHERE contact_value IS NOT NULL
       AND contact_value <> '';

    IF plaintext_row_count > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          '1193: plaintext kontakty vyžadují ruční aplikační šifrovanou migraci';
    END IF;

    ALTER TABLE payroll_person_contacts
      DROP INDEX IF EXISTS uq_payroll_contact_value,
      DROP COLUMN IF EXISTS contact_value;
  END IF;
END//

CALL migrate_payroll_contacts_1193()//
DROP PROCEDURE IF EXISTS migrate_payroll_contacts_1193//

DELIMITER ;

ALTER TABLE payroll_person_contacts
  MODIFY COLUMN contact_value_ciphertext VARCHAR(512) NOT NULL,
  MODIFY COLUMN contact_value_hash BINARY(32) NOT NULL,
  MODIFY COLUMN contact_value_masked VARCHAR(191) NOT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_contact_value
    (supplier_id, employee_id, contact_type, contact_value_hash);
