-- MyÚčto.cz — MZ-04-W05: rodinné (vyživované) osoby a jejich napojení na
-- daňové zvýhodnění podle § 35c zákona o daních z příjmů.
--
-- Proč NEVZNIKÁ druhý model nároku: uplatnění zvýhodnění už eviduje tabulka
-- `payroll_person_tax_child_claims` (migrace 1256) a jde jedinou cestou do
-- výpočtu (PayrollPersonStatutoryEvidenceRepository → snapshot revize →
-- PayrollRunStatutoryInputAssembler::taxChildren → TaxChildClaim →
-- MonthlyEmploymentIncomeTaxCalculator::resolveChildren). Chyběla jen evidence
-- samotné OSOBY, na kterou se nárok uplatňuje — ta se doplňuje zde a nárok se
-- na ni jen navazuje sloupcem `dependant_id`.
--
-- payroll_dependants = dítě / manžel / partner konkrétního poplatníka
--   (supplier_id, employee_id). Rodné číslo je šifrované trojicí
--   ciphertext + keyed hash + maska, stejně jako `payroll_person_identifiers`
--   (migrace 1191). Keyed hash je tenantový (nezávislý na employee_id), takže
--   umožní detekovat totéž dítě uplatněné u dvou poplatníků jednoho
--   zaměstnavatele, aniž by se kdekoli objevil otevřený text.
--
-- Rozšíření payroll_person_tax_child_claims:
--   dependant_id     — vazba nároku na evidovanou osobu (nová evidence),
--   claim_reason     — důvod uplatnění (vlastní dítě, střídavá péče, …),
--   superseded_by_id — append-only náhrada: změna nároku nad zmrazeným
--                      (schváleným) obdobím starý řádek neprepíše, jen ho
--                      ukončí a odkáže na novou účinnou verzi.
--
-- Sazby zvýhodnění zůstávají v rulesetu (CzechPayrollRulesets2026), zde nejsou.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN/KEY IF NOT EXISTS,
-- FK přes DROP FOREIGN KEY IF EXISTS + ADD (MariaDB neumí ADD CONSTRAINT
-- IF NOT EXISTS pro cizí klíč).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_dependants (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  employee_id             BIGINT UNSIGNED NOT NULL,
  relation                ENUM(
                            'child_own',
                            'child_adopted',
                            'child_in_care',
                            'child_of_spouse',
                            'grandchild',
                            'spouse',
                            'partner'
                          ) NOT NULL,
  full_name               VARCHAR(191) NOT NULL,
  birth_date              DATE NOT NULL,
  birth_number_ciphertext VARCHAR(512) NULL,
  birth_number_hash       BINARY(32) NULL,
  birth_number_masked     VARCHAR(191) NULL,
  ztp_p                   TINYINT(1) NOT NULL DEFAULT 0,
  student                 TINYINT(1) NOT NULL DEFAULT 0,
  existence_from          DATE NOT NULL
                            COMMENT 'Od kdy je osoba vyživovaná (typicky narození)',
  existence_to            DATE NULL
                            COMMENT 'Do kdy (konec studia, úmrtí, 26 let, …)',
  note                    VARCHAR(500) NULL,
  created_by              BIGINT UNSIGNED NULL,
  updated_by              BIGINT UNSIGNED NULL,
  row_version             INT UNSIGNED NOT NULL DEFAULT 1,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_dependant_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_dependant_birth_number
    (supplier_id, employee_id, birth_number_hash),
  KEY idx_payroll_dependant_employee (supplier_id, employee_id, relation),
  KEY idx_payroll_dependant_tenant_hash (supplier_id, birth_number_hash),
  CONSTRAINT fk_payroll_dependant_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_dependant_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_dependant_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_dependant_interval
    CHECK (existence_to IS NULL OR existence_to >= existence_from),
  CONSTRAINT chk_payroll_dependant_birth
    CHECK (existence_from >= birth_date),
  CONSTRAINT chk_payroll_dependant_flags
    CHECK (ztp_p IN (0, 1) AND student IN (0, 1)),
  CONSTRAINT chk_payroll_dependant_secret CHECK (
    (birth_number_ciphertext IS NULL
     AND birth_number_hash IS NULL
     AND birth_number_masked IS NULL)
    OR
    (birth_number_ciphertext IS NOT NULL
     AND birth_number_hash IS NOT NULL
     AND birth_number_masked IS NOT NULL)
  ),
  CONSTRAINT chk_payroll_dependant_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_person_tax_child_claims
  ADD COLUMN IF NOT EXISTS dependant_id BIGINT UNSIGNED NULL AFTER employee_id,
  ADD COLUMN IF NOT EXISTS claim_reason VARCHAR(500) NULL AFTER child_order,
  ADD COLUMN IF NOT EXISTS superseded_by_id BIGINT UNSIGNED NULL
    AFTER claim_reason;

ALTER TABLE payroll_person_tax_child_claims
  ADD KEY IF NOT EXISTS idx_pp_tax_child_dependant
    (supplier_id, dependant_id, effective_from),
  ADD KEY IF NOT EXISTS idx_pp_tax_child_order
    (supplier_id, employee_id, child_order, effective_from, effective_to),
  ADD KEY IF NOT EXISTS idx_pp_tax_child_superseded
    (supplier_id, superseded_by_id);

ALTER TABLE payroll_person_tax_child_claims
  DROP FOREIGN KEY IF EXISTS fk_pp_tax_child_dependant;

ALTER TABLE payroll_person_tax_child_claims
  ADD CONSTRAINT fk_pp_tax_child_dependant
    FOREIGN KEY (supplier_id, dependant_id)
    REFERENCES payroll_dependants (supplier_id, id) ON DELETE CASCADE;
