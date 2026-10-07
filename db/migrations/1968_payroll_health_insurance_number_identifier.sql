-- MyÚčto.cz — číslo pojištěnce zdravotní pojišťovny jako samostatný identifikátor osoby.
--
-- Hromadné oznámení zaměstnavatele (HOZ) nese v `cisloPojistence` číslo
-- pojištěnce z průkazu pojištěnce nebo z oznámení zdravotní pojišťovny.
-- U cizince po prvním přihlášení je to číslo, které přidělila pojišťovna;
-- evidenční číslo ČSSZ (EČP) to není, a dokud jiný typ nebyl, posílalo se
-- do věty právě EČP. Nový typ `health_insurance_number` drží číslo
-- přidělené pojišťovnou.
--
-- Idempotentní: MODIFY COLUMN na stejný výčet je opakovatelný.

SET NAMES utf8mb4;

ALTER TABLE payroll_person_identifiers
  MODIFY COLUMN identifier_type
    ENUM(
      'birth_number',
      'ecp',
      'vcp',
      'foreign_tax_identifier',
      'health_insurance_number'
    ) NOT NULL;
