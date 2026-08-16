-- 1401 — zdravotní pojištění do druhů zákonné kumulace
--
-- `payroll_statutory_results` (migrace 1255) zná čtyři druhy výpočtu včetně
-- zdravotního pojištění, kumulace z migrace 1258 ale jen dva. Sjednocuje se to,
-- aby druh výpočtu neznamenal v každé tabulce něco jiného.
--
-- POZOR: hodnota je zatím bez zapisovatele. Akumulační cesta pro ZP neexistuje
-- (chybí sada polí ve `VALUE_FIELDS`, větev ve snapshot builderu i
-- `approveHealthInsurance()`), takže rozšíření ENUM samo o sobě žádné počáteční
-- stavy ZP nezavede — jen odemyká místo pro ně.
--
-- `calculation_kind` je součástí samo-referencujícího cizího klíče, který drží
-- řetěz oprav v jednom scope. MariaDB kvůli němu MODIFY COLUMN odmítne
-- (chyba 1833), takže se klíč zahodí a po změně typu obnoví ve stejné podobě.
--
-- Idempotence: DROP je podmíněný existencí v information_schema, MODIFY nastaví
-- tentýž typ i při opakování a ADD se provede jen tehdy, když klíč chybí.
-- Idempotence je tu povinná — testy pouštějí migrace znovu.

DELIMITER //

DROP PROCEDURE IF EXISTS myucto_1401_accumulator_health //

CREATE PROCEDURE myucto_1401_accumulator_health()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payroll_statutory_accumulator_openings'
       AND CONSTRAINT_NAME = 'fk_payroll_statutory_opening_previous'
  ) THEN
    ALTER TABLE payroll_statutory_accumulator_openings
      DROP FOREIGN KEY fk_payroll_statutory_opening_previous;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payroll_statutory_accumulator_entries'
       AND CONSTRAINT_NAME = 'fk_payroll_statutory_entry_previous'
  ) THEN
    ALTER TABLE payroll_statutory_accumulator_entries
      DROP FOREIGN KEY fk_payroll_statutory_entry_previous;
  END IF;

  ALTER TABLE payroll_statutory_accumulator_openings
    MODIFY COLUMN calculation_kind
      ENUM('social_insurance', 'health_insurance', 'income_tax') NOT NULL;

  ALTER TABLE payroll_statutory_accumulator_entries
    MODIFY COLUMN calculation_kind
      ENUM('social_insurance', 'health_insurance', 'income_tax') NOT NULL;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payroll_statutory_accumulator_openings'
       AND CONSTRAINT_NAME = 'fk_payroll_statutory_opening_previous'
  ) THEN
    ALTER TABLE payroll_statutory_accumulator_openings
      ADD CONSTRAINT fk_payroll_statutory_opening_previous
      FOREIGN KEY (
        supplier_id, employee_id, tax_year, calculation_kind, replaces_opening_id
      )
      REFERENCES payroll_statutory_accumulator_openings (
        supplier_id, employee_id, tax_year, calculation_kind, id
      ) ON DELETE RESTRICT;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'payroll_statutory_accumulator_entries'
       AND CONSTRAINT_NAME = 'fk_payroll_statutory_entry_previous'
  ) THEN
    ALTER TABLE payroll_statutory_accumulator_entries
      ADD CONSTRAINT fk_payroll_statutory_entry_previous
      FOREIGN KEY (
        supplier_id, employee_id, tax_year, period_start, calculation_kind,
        replaces_entry_id
      )
      REFERENCES payroll_statutory_accumulator_entries (
        supplier_id, employee_id, tax_year, period_start, calculation_kind, id
      ) ON DELETE RESTRICT;
  END IF;
END //

DELIMITER ;

CALL myucto_1401_accumulator_health();
DROP PROCEDURE myucto_1401_accumulator_health;
