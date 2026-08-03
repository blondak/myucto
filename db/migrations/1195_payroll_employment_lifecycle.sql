-- MyÚčto.cz — MZ-05: historické pracovní vztahy a jejich životní cyklus.
--
-- `payroll_employments` zůstává 1:N rozšířením společné identity
-- `payroll_employees`. Aktuální stav je projekce pro rychlé filtrování; smluvní,
-- pojistné a daňové vstupy se zapisují do neměnných časových intervalů.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  MODIFY COLUMN relation_type
    ENUM(
      'employment',
      'small_scale_employment',
      'dpp',
      'dpc',
      'partner_dependent',
      'statutory_body'
    ) NOT NULL,
  MODIFY COLUMN status
    ENUM(
      'draft',
      'cancelled',
      'planned',
      'preregistered',
      'active',
      'suspended',
      'ended',
      'archived',
      'no_show'
    ) NOT NULL DEFAULT 'planned',
  ADD COLUMN IF NOT EXISTS office_id BIGINT UNSIGNED NULL
    AFTER employee_id,
  ADD COLUMN IF NOT EXISTS is_primary TINYINT(1) NOT NULL DEFAULT 0
    AFTER status,
  ADD COLUMN IF NOT EXISTS actual_start_date DATE NULL
    AFTER start_date,
  ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL
    AFTER end_date;

UPDATE payroll_employments
   SET status = 'planned'
 WHERE status = 'draft';

UPDATE payroll_employments
   SET status = 'no_show'
 WHERE status = 'cancelled';

ALTER TABLE payroll_employments
  DROP CONSTRAINT IF EXISTS chk_payroll_employment_primary;

ALTER TABLE payroll_employments
  MODIFY COLUMN status
    ENUM(
      'planned',
      'preregistered',
      'active',
      'suspended',
      'ended',
      'archived',
      'no_show'
    ) NOT NULL DEFAULT 'planned',
  ADD COLUMN IF NOT EXISTS primary_employee_key BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN is_primary = 1
         AND status IN ('planned', 'preregistered', 'active', 'suspended')
        THEN employee_id
        ELSE NULL
      END
    ) STORED,
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_primary
    (supplier_id, primary_employee_key),
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_supplier_id
    (supplier_id, id),
  ADD KEY IF NOT EXISTS idx_payroll_employment_office
    (supplier_id, office_id),
  ADD CONSTRAINT fk_payroll_employment_office
    FOREIGN KEY IF NOT EXISTS (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id),
  ADD CONSTRAINT chk_payroll_employment_primary
    CHECK (is_primary IN (0, 1));

CREATE TABLE IF NOT EXISTS payroll_employment_terms (
  id                               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                      INT UNSIGNED NOT NULL,
  employment_id                    BIGINT UNSIGNED NOT NULL,
  office_id                        BIGINT UNSIGNED NULL,
  effective_from                   DATE NOT NULL,
  effective_to                     DATE NULL,
  contract_signed_on               DATE NULL,
  planned_start_on                 DATE NOT NULL,
  actual_start_on                  DATE NULL,
  fixed_term_end_on                DATE NULL,
  weekly_hours                     DECIMAL(5,2) NULL,
  workload_basis_points            SMALLINT UNSIGNED NOT NULL DEFAULT 10000,
  work_place                       VARCHAR(255) NULL,
  regular_workplace                VARCHAR(255) NULL,
  cz_isco_code                     VARCHAR(16) NULL,
  activity_code                    VARCHAR(32) NULL,
  social_insurance_participation   ENUM('automatic','included','excluded','foreign') NOT NULL DEFAULT 'automatic',
  health_insurance_participation   ENUM('automatic','included','excluded','foreign') NOT NULL DEFAULT 'automatic',
  tax_regime                       ENUM('advance','withholding','foreign','manual_review') NOT NULL DEFAULT 'advance',
  foreign_legislation_country_code CHAR(2) NULL,
  a1_certificate_until             DATE NULL,
  risky_work                       TINYINT(1) NOT NULL DEFAULT 0,
  tax_declaration_signed           TINYINT(1) NOT NULL DEFAULT 0,
  is_primary                       TINYINT(1) NOT NULL DEFAULT 0,
  change_reason                    VARCHAR(500) NULL,
  created_by                       BIGINT UNSIGNED NULL,
  row_version                      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_term_start
    (supplier_id, employment_id, effective_from),
  UNIQUE KEY uq_payroll_employment_term_tenant_id
    (supplier_id, id),
  KEY idx_payroll_employment_term_effective
    (supplier_id, employment_id, effective_from, effective_to),
  KEY idx_payroll_employment_term_office
    (supplier_id, office_id),
  CONSTRAINT fk_payroll_employment_term_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employment_term_office
    FOREIGN KEY (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id),
  CONSTRAINT fk_payroll_employment_term_user
    FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE SET NULL,
  CONSTRAINT chk_payroll_employment_term_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_payroll_employment_term_dates
    CHECK (
      fixed_term_end_on IS NULL
      OR fixed_term_end_on >= planned_start_on
    ),
  CONSTRAINT chk_payroll_employment_term_hours
    CHECK (weekly_hours IS NULL OR (weekly_hours > 0 AND weekly_hours <= 168)),
  CONSTRAINT chk_payroll_employment_term_workload
    CHECK (workload_basis_points BETWEEN 1 AND 10000),
  CONSTRAINT chk_payroll_employment_term_flags
    CHECK (
      risky_work IN (0, 1)
      AND tax_declaration_signed IN (0, 1)
      AND is_primary IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  employment_id  BIGINT UNSIGNED NOT NULL,
  event_type     ENUM('created','terms_changed','status_changed','checklist_changed') NOT NULL,
  from_status    VARCHAR(32) NULL,
  to_status      VARCHAR(32) NULL,
  effective_on   DATE NOT NULL,
  note           VARCHAR(500) NULL,
  diff_json      LONGTEXT NULL CHECK (diff_json IS NULL OR JSON_VALID(diff_json)),
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_event_tenant_id (supplier_id, id),
  KEY idx_payroll_employment_event_timeline
    (supplier_id, employment_id, effective_on, id),
  CONSTRAINT fk_payroll_employment_event_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employment_event_user
    FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_checklist_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  employment_id  BIGINT UNSIGNED NOT NULL,
  phase          ENUM('onboarding','change','offboarding') NOT NULL,
  item_key       VARCHAR(64) NOT NULL,
  status         ENUM('pending','completed','not_applicable') NOT NULL DEFAULT 'pending',
  due_date       DATE NULL,
  completed_at   DATETIME NULL,
  completed_by   BIGINT UNSIGNED NULL,
  note           VARCHAR(500) NULL,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_checklist_item
    (supplier_id, employment_id, phase, item_key),
  UNIQUE KEY uq_payroll_employment_checklist_tenant_id (supplier_id, id),
  KEY idx_payroll_employment_checklist_open
    (supplier_id, employment_id, status, due_date),
  CONSTRAINT fk_payroll_employment_checklist_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_employment_checklist_user
    FOREIGN KEY (completed_by) REFERENCES users (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stávající legacy vztahy dostanou výchozí historický interval, ale identita ani
-- legacy projekce se nepřepisují.
INSERT IGNORE INTO payroll_employment_terms (
  supplier_id,
  employment_id,
  office_id,
  effective_from,
  effective_to,
  planned_start_on,
  actual_start_on,
  fixed_term_end_on,
  weekly_hours,
  workload_basis_points,
  tax_declaration_signed,
  is_primary,
  change_reason
)
SELECT employment.supplier_id,
       employment.id,
       employment.office_id,
       COALESCE(employment.start_date, '1900-01-01'),
       employment.end_date,
       COALESCE(employment.start_date, '1900-01-01'),
       employment.actual_start_date,
       employment.end_date,
       NULL,
       10000,
       employee.tax_declaration_signed,
       employment.is_primary,
       'Legacy projekce'
  FROM payroll_employments employment
  JOIN payroll_employees employee
    ON employee.supplier_id = employment.supplier_id
   AND employee.id = employment.employee_id;
