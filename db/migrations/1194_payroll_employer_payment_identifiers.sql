-- MyÚčto.cz — MZ-03: oddělení platebních identifikátorů OSVČ a zaměstnavatele.
--
-- supplier.cssz_vsdp a supplier.health_insurance_number jsou osobní identifikátory
-- OSVČ. Zaměstnavatelský VS ČSSZ patří konkrétní mzdové účtárně; číslo plátce
-- zdravotního pojištění patří konkrétní pojišťovně a účinnému platebnímu účtu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_data_migration_markers (
  migration_key VARCHAR(128) NOT NULL,
  completed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_offices
  ADD COLUMN IF NOT EXISTS social_security_variable_symbol VARCHAR(10) NULL
    AFTER name;

ALTER TABLE payroll_offices
  DROP CONSTRAINT IF EXISTS chk_payroll_office_social_vs;

ALTER TABLE payroll_offices
  ADD CONSTRAINT chk_payroll_office_social_vs CHECK (
    social_security_variable_symbol IS NULL
    OR social_security_variable_symbol REGEXP '^[0-9]{1,10}$'
  );

-- Opakované spuštění po úspěšném DROPu na konci si založí pouze prázdný
-- přechodový sloupec; při prvním upgrade ADD IF NOT EXISTS zachová stará data.
ALTER TABLE payroll_employer_settings
  ADD COLUMN IF NOT EXISTS health_insurance_payer_number VARCHAR(32) NULL
    AFTER social_security_office_code;

-- Jednorázová kompatibilní migrace starého PO nastavení. U fyzických osob se
-- supplier.cssz_vsdp nikdy nekopíruje: tam zůstává osobním VS OSVČ.
UPDATE payroll_offices office
JOIN payroll_employer_settings settings
  ON settings.supplier_id = office.supplier_id
 AND settings.default_office_id = office.id
JOIN supplier
  ON supplier.id = office.supplier_id
SET office.social_security_variable_symbol =
      REGEXP_REPLACE(supplier.cssz_vsdp, '[^0-9]', '')
WHERE supplier.taxpayer_type = 'po'
  AND office.social_security_variable_symbol IS NULL
  AND supplier.cssz_vsdp IS NOT NULL
  AND REGEXP_REPLACE(supplier.cssz_vsdp, '[^0-9]', '') REGEXP '^[0-9]{1,10}$'
  AND NOT EXISTS (
    SELECT 1
      FROM payroll_data_migration_markers marker
     WHERE marker.migration_key = '1194_employer_payment_identifiers_v1'
  );

-- Staré payroll pole se přenese pouze do právě účinné řádky výchozí zdravotní
-- pojišťovny. Další změny už žijí výhradně v historii účtů institucí.
UPDATE payroll_institution_accounts account
JOIN payroll_institutions institution
  ON institution.supplier_id = account.supplier_id
 AND institution.id = account.institution_id
JOIN payroll_employer_settings settings
  ON settings.supplier_id = account.supplier_id
SET account.variable_symbol =
      REGEXP_REPLACE(settings.health_insurance_payer_number, '[^0-9]', '')
WHERE institution.institution_type = 'health_insurer'
  AND institution.institution_code = settings.default_health_insurer_code
  AND account.variable_symbol IS NULL
  AND account.valid_from <= CURRENT_DATE
  AND (account.valid_to IS NULL OR account.valid_to >= CURRENT_DATE)
  AND settings.health_insurance_payer_number IS NOT NULL
  AND REGEXP_REPLACE(settings.health_insurance_payer_number, '[^0-9]', '')
      REGEXP '^[0-9]{1,10}$'
  AND NOT EXISTS (
    SELECT 1
      FROM payroll_data_migration_markers marker
     WHERE marker.migration_key = '1194_employer_payment_identifiers_v1'
  );

-- Poslední migrační fallback pro právnické osoby, kde byl zaměstnavatelský
-- identifikátor dříve chybně uložený v osobním supplier sloupci.
UPDATE payroll_institution_accounts account
JOIN payroll_institutions institution
  ON institution.supplier_id = account.supplier_id
 AND institution.id = account.institution_id
JOIN payroll_employer_settings settings
  ON settings.supplier_id = account.supplier_id
JOIN supplier
  ON supplier.id = account.supplier_id
SET account.variable_symbol =
      REGEXP_REPLACE(supplier.health_insurance_number, '[^0-9]', '')
WHERE supplier.taxpayer_type = 'po'
  AND institution.institution_type = 'health_insurer'
  AND institution.institution_code = settings.default_health_insurer_code
  AND account.variable_symbol IS NULL
  AND account.valid_from <= CURRENT_DATE
  AND (account.valid_to IS NULL OR account.valid_to >= CURRENT_DATE)
  AND supplier.health_insurance_number IS NOT NULL
  AND REGEXP_REPLACE(supplier.health_insurance_number, '[^0-9]', '')
      REGEXP '^[0-9]{1,10}$'
  AND NOT EXISTS (
    SELECT 1
      FROM payroll_data_migration_markers marker
     WHERE marker.migration_key = '1194_employer_payment_identifiers_v1'
  );

INSERT IGNORE INTO payroll_data_migration_markers (migration_key)
VALUES ('1194_employer_payment_identifiers_v1');

-- Po přenosu už nesmí zůstat druhý zapisovatelný zdroj pravdy. Zdravotní
-- identifikátor zaměstnavatele od této chvíle žije pouze v účinné historii
-- účtů konkrétní pojišťovny.
ALTER TABLE payroll_employer_settings
  DROP COLUMN IF EXISTS health_insurance_payer_number;
