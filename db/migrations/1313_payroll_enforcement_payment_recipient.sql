-- MyÚčto.cz — MZ-14-W08: napojení exekučních srážek na platební dávky MZ-17.
--
-- Případ dostane odkaz na příjemce z katalogu platebních účtů institucí
-- (MZ-03-W03, `payroll_institutions` typu `other_recipient`). Volný text ani
-- spisová značka se do platby nikdy nedostanou — materializace čte pouze tenhle
-- odkaz a účinný ověřený účet instituce.
--
-- Idempotence: ADD COLUMN/KEY IF NOT EXISTS + DROP FOREIGN KEY IF EXISTS před ADD
-- (MariaDB nemá ADD CONSTRAINT IF NOT EXISTS pro cizí klíče).

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_cases
  ADD COLUMN IF NOT EXISTS recipient_institution_id BIGINT UNSIGNED NULL
    AFTER recipient_verified;

ALTER TABLE payroll_enforcement_cases
  ADD KEY IF NOT EXISTS idx_payroll_enforcement_case_recipient
    (supplier_id, recipient_institution_id);

ALTER TABLE payroll_enforcement_cases
  DROP FOREIGN KEY IF EXISTS fk_payroll_enforcement_case_recipient;

ALTER TABLE payroll_enforcement_cases
  ADD CONSTRAINT fk_payroll_enforcement_case_recipient
    FOREIGN KEY (supplier_id, recipient_institution_id)
    REFERENCES payroll_institutions (supplier_id, id) ON DELETE RESTRICT;
