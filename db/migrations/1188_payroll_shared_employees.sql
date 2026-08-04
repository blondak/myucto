-- MyÚčto.cz — sdílená identita zaměstnance pro nový mzdový modul.
--
-- `payroll_employees` zůstává jediným místem s identitou člověka a legacy údaji.
-- Nový profil je jeho 1:1 rozšíření bez osobních údajů a pracovní vztahy jsou 1:N.

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
    ADD UNIQUE KEY IF NOT EXISTS uq_pe_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_employee_profiles (
  supplier_id       INT UNSIGNED NOT NULL,
  employee_id       BIGINT UNSIGNED NOT NULL,
  profile_status    ENUM('legacy','setup','ready') NOT NULL DEFAULT 'setup',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, employee_id),
  CONSTRAINT fk_payroll_profile_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employments (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  code                       VARCHAR(64) NOT NULL,
  relation_type              ENUM('employment','dpp','dpc','partner_dependent','statutory_body') NOT NULL,
  status                     ENUM('draft','active','ended','cancelled') NOT NULL DEFAULT 'draft',
  start_date                 DATE NULL,
  end_date                   DATE NULL,
  monthly_gross_minor        BIGINT UNSIGNED NULL COMMENT 'pravidelná hrubá odměna v haléřích; NULL = nesjednaná',
  is_legacy_projection       TINYINT(1) NOT NULL DEFAULT 0,
  legacy_projection_key      BIGINT UNSIGNED
      GENERATED ALWAYS AS (
        CASE WHEN is_legacy_projection = 1 THEN employee_id ELSE NULL END
      ) STORED,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_code (supplier_id, employee_id, code),
  UNIQUE KEY uq_payroll_employment_legacy (supplier_id, legacy_projection_key),
  KEY idx_payroll_employment_employee (supplier_id, employee_id, status),
  CONSTRAINT fk_payroll_employment_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT chk_payroll_employment_interval
    CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date),
  CONSTRAINT chk_payroll_employment_legacy
    CHECK (is_legacy_projection IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
SELECT supplier_id, id, 'legacy'
  FROM payroll_employees;

INSERT IGNORE INTO payroll_employments (
    supplier_id,
    employee_id,
    code,
    relation_type,
    status,
    start_date,
    end_date,
    monthly_gross_minor,
    is_legacy_projection,
    row_version
)
SELECT
    pe.supplier_id,
    pe.id,
    'legacy',
    CASE
      WHEN pe.taxpayer_type = 'managing_partner' THEN 'partner_dependent'
      WHEN pe.employment_type = 'dpp' THEN 'dpp'
      WHEN pe.employment_type = 'dpc' THEN 'dpc'
      ELSE 'employment'
    END,
    CASE WHEN pe.is_active = 1 THEN 'active' ELSE 'ended' END,
    NULL,
    NULL,
    CASE
      WHEN pe.monthly_gross IS NULL THEN NULL
      ELSE CAST(pe.monthly_gross AS UNSIGNED) * 100
    END,
    1,
    1
FROM payroll_employees pe
WHERE NOT EXISTS (
    SELECT 1
      FROM payroll_employments employment
     WHERE employment.supplier_id = pe.supplier_id
       AND employment.employee_id = pe.id
       AND employment.is_legacy_projection = 1
);
