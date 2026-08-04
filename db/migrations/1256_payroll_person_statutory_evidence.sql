-- MyÚčto.cz — MZ-04-W03 až W06: auditovatelná osobní evidence pro MZ-10 až MZ-12.
--
-- Tabulky ukládají nezávisle účinné právní skutečnosti. Překryvy intervalů
-- chrání aplikační validátor, protože MariaDB neumí exclusion constraints.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_person_health_coverage_history (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                     INT UNSIGNED NOT NULL,
  employee_id                     BIGINT UNSIGNED NOT NULL,
  jurisdiction                    ENUM(
                                    'czech_regime_verified',
                                    'foreign_regime_verified',
                                    'unverified'
                                  ) NOT NULL,
  foreign_country_code            CHAR(2) NULL,
  jurisdiction_evidence_reference VARCHAR(500) NULL,
  insurer_status                  ENUM('verified','unverified','not_applicable') NOT NULL,
  insurer_code                    CHAR(3) NULL,
  insurer_evidence_reference      VARCHAR(500) NULL,
  effective_from                  DATE NOT NULL,
  effective_to                    DATE NULL,
  evidence_note                   VARCHAR(500) NULL,
  created_by                      BIGINT UNSIGNED NULL,
  updated_by                      BIGINT UNSIGNED NULL,
  row_version                     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_health_coverage_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_health_coverage_start (supplier_id, employee_id, effective_from),
  KEY idx_pp_health_coverage_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_health_coverage_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_health_coverage_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_health_coverage_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_health_coverage_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_health_coverage_country
    CHECK (
      (jurisdiction = 'foreign_regime_verified'
       AND foreign_country_code REGEXP '^[A-Z]{2}$'
       AND jurisdiction_evidence_reference IS NOT NULL)
      OR
      (jurisdiction <> 'foreign_regime_verified'
       AND foreign_country_code IS NULL
       AND jurisdiction_evidence_reference IS NULL)
    ),
  CONSTRAINT chk_pp_health_coverage_insurer
    CHECK (
      (insurer_status = 'verified'
       AND insurer_code REGEXP '^[0-9]{3}$'
       AND insurer_evidence_reference IS NOT NULL)
      OR
      (insurer_status = 'unverified'
       AND (insurer_code IS NULL OR insurer_code REGEXP '^[0-9]{3}$')
       AND insurer_evidence_reference IS NULL)
      OR
      (insurer_status = 'not_applicable'
       AND insurer_code IS NULL
       AND insurer_evidence_reference IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_health_minimum_reductions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  reason             ENUM(
                       'state_insured',
                       'ztp_or_ztp_p',
                       'pension_age_without_pension',
                       'sickness_care_or_quarantine',
                       'osvc_minimum_advance',
                       'foster_reward_only',
                       'unverified'
                     ) NOT NULL,
  evidence_reference VARCHAR(500) NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_health_reduction_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_health_reduction_start
    (supplier_id, employee_id, reason, effective_from),
  KEY idx_pp_health_reduction_effective
    (supplier_id, employee_id, reason, effective_from, effective_to),
  CONSTRAINT fk_pp_health_reduction_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_health_reduction_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_health_reduction_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_health_reduction_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_health_reduction_evidence
    CHECK (
      (reason = 'unverified' AND evidence_reference IS NULL)
      OR
      (reason <> 'unverified' AND evidence_reference IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_health_month_evidence (
  id                                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                              INT UNSIGNED NOT NULL,
  employee_id                              BIGINT UNSIGNED NOT NULL,
  period_start                             DATE NOT NULL,
  top_up_responsibility                    ENUM(
                                               'employee',
                                               'employer_obstacle_verified',
                                               'unverified'
                                             ) NOT NULL DEFAULT 'employee',
  top_up_responsibility_evidence_reference VARCHAR(500) NULL,
  selected_top_up_employer_reference       VARCHAR(128) NULL,
  selected_top_up_employer_evidence_reference VARCHAR(500) NULL,
  evidence_note                            VARCHAR(500) NULL,
  created_by                               BIGINT UNSIGNED NULL,
  updated_by                               BIGINT UNSIGNED NULL,
  row_version                              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_health_month_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_health_month_period (supplier_id, employee_id, period_start),
  CONSTRAINT fk_pp_health_month_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_health_month_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_health_month_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_health_month_start
    CHECK (DAYOFMONTH(period_start) = 1),
  CONSTRAINT chk_pp_health_month_responsibility
    CHECK (
      (top_up_responsibility = 'employer_obstacle_verified'
       AND top_up_responsibility_evidence_reference IS NOT NULL)
      OR
      (top_up_responsibility <> 'employer_obstacle_verified'
       AND top_up_responsibility_evidence_reference IS NULL)
    ),
  CONSTRAINT chk_pp_health_month_selected_employer
    CHECK (
      (selected_top_up_employer_reference IS NULL
       AND selected_top_up_employer_evidence_reference IS NULL)
      OR
      (selected_top_up_employer_reference IS NOT NULL
       AND selected_top_up_employer_evidence_reference IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_health_other_employer_bases (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  period_start                DATE NOT NULL,
  employer_reference          VARCHAR(128) NOT NULL,
  assessment_base_minor_units BIGINT UNSIGNED NOT NULL,
  employment_from             DATE NOT NULL,
  employment_to               DATE NULL,
  evidence_reference          VARCHAR(500) NOT NULL,
  evidence_note               VARCHAR(500) NULL,
  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_health_other_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_health_other_period
    (supplier_id, employee_id, period_start, employer_reference),
  KEY idx_pp_health_other_effective (supplier_id, employee_id, period_start),
  CONSTRAINT fk_pp_health_other_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_health_other_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_health_other_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_health_other_period
    CHECK (DAYOFMONTH(period_start) = 1),
  CONSTRAINT chk_pp_health_other_interval
    CHECK (employment_to IS NULL OR employment_to >= employment_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_tax_declarations (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  status             ENUM('signed','not-signed','unverified') NOT NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_reference VARCHAR(500) NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_tax_declaration_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_tax_declaration_start (supplier_id, employee_id, effective_from),
  KEY idx_pp_tax_declaration_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_tax_declaration_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_tax_declaration_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_tax_declaration_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_tax_declaration_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_tax_declaration_evidence
    CHECK (
      (status = 'unverified' AND evidence_reference IS NULL)
      OR
      (status <> 'unverified' AND evidence_reference IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_tax_residences (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  residence          ENUM('czech-resident','non-resident','unverified') NOT NULL,
  country_code       CHAR(2) NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_reference VARCHAR(500) NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_tax_residence_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_tax_residence_start (supplier_id, employee_id, effective_from),
  KEY idx_pp_tax_residence_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_tax_residence_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_tax_residence_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_tax_residence_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_tax_residence_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_tax_residence_evidence
    CHECK (
      (residence = 'czech-resident'
       AND country_code = 'CZ'
       AND evidence_reference IS NOT NULL)
      OR
      (residence = 'non-resident'
       AND country_code REGEXP '^[A-Z]{2}$'
       AND country_code <> 'CZ'
       AND evidence_reference IS NOT NULL)
      OR
      (residence = 'unverified'
       AND country_code IS NULL
       AND evidence_reference IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_tax_credit_claims (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  credit_kind        ENUM(
                       'taxpayer',
                       'disability-basic',
                       'disability-extended',
                       'ztp-p'
                     ) NOT NULL,
  evidence_status    ENUM('verified','unverified') NOT NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_reference VARCHAR(500) NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_tax_credit_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_tax_credit_start
    (supplier_id, employee_id, credit_kind, effective_from),
  KEY idx_pp_tax_credit_effective
    (supplier_id, employee_id, credit_kind, effective_from, effective_to),
  CONSTRAINT fk_pp_tax_credit_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_tax_credit_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_tax_credit_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_tax_credit_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_tax_credit_evidence
    CHECK (
      (evidence_status = 'verified' AND evidence_reference IS NOT NULL)
      OR
      (evidence_status = 'unverified' AND evidence_reference IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_tax_child_claims (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  child_reference            VARCHAR(128) NOT NULL
                               COMMENT 'Neosobní kanonický klíč dítěte v rámci zaměstnance',
  child_order                SMALLINT UNSIGNED NOT NULL,
  ztp_p                      TINYINT(1) NOT NULL DEFAULT 0,
  evidence_status            ENUM('verified','unverified') NOT NULL,
  shared_household_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  other_claimant_excluded    TINYINT(1) NOT NULL DEFAULT 0,
  effective_from             DATE NOT NULL,
  effective_to               DATE NULL,
  evidence_reference         VARCHAR(500) NULL,
  evidence_note              VARCHAR(500) NULL,
  created_by                 BIGINT UNSIGNED NULL,
  updated_by                 BIGINT UNSIGNED NULL,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_tax_child_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_tax_child_start
    (supplier_id, employee_id, child_reference, effective_from),
  KEY idx_pp_tax_child_effective
    (supplier_id, employee_id, child_reference, effective_from, effective_to),
  CONSTRAINT fk_pp_tax_child_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_tax_child_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_tax_child_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_tax_child_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_tax_child_order CHECK (child_order >= 1),
  CONSTRAINT chk_pp_tax_child_flags CHECK (
    ztp_p IN (0, 1)
    AND shared_household_confirmed IN (0, 1)
    AND other_claimant_excluded IN (0, 1)
  ),
  CONSTRAINT chk_pp_tax_child_evidence
    CHECK (
      (evidence_status = 'verified' AND evidence_reference IS NOT NULL)
      OR
      (evidence_status = 'unverified' AND evidence_reference IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_social_jurisdictions (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                     INT UNSIGNED NOT NULL,
  employee_id                     BIGINT UNSIGNED NOT NULL,
  jurisdiction                    ENUM(
                                    'czech_regime_verified',
                                    'foreign_regime_verified',
                                    'unverified'
                                  ) NOT NULL,
  foreign_country_code            CHAR(2) NULL,
  jurisdiction_evidence_reference VARCHAR(500) NULL,
  a1_status                       ENUM('verified','unverified','not_applicable') NOT NULL,
  a1_certificate_reference        VARCHAR(500) NULL,
  a1_valid_until                  DATE NULL,
  effective_from                  DATE NOT NULL,
  effective_to                    DATE NULL,
  evidence_note                   VARCHAR(500) NULL,
  created_by                      BIGINT UNSIGNED NULL,
  updated_by                      BIGINT UNSIGNED NULL,
  row_version                     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_social_jurisdiction_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_social_jurisdiction_start (supplier_id, employee_id, effective_from),
  KEY idx_pp_social_jurisdiction_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_social_jurisdiction_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_social_jurisdiction_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_social_jurisdiction_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_social_jurisdiction_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_social_jurisdiction_country
    CHECK (
      (jurisdiction = 'foreign_regime_verified'
       AND foreign_country_code REGEXP '^[A-Z]{2}$'
       AND jurisdiction_evidence_reference IS NOT NULL)
      OR
      (jurisdiction <> 'foreign_regime_verified'
       AND foreign_country_code IS NULL
       AND jurisdiction_evidence_reference IS NULL)
    ),
  CONSTRAINT chk_pp_social_jurisdiction_a1
    CHECK (
      (a1_status = 'verified'
       AND a1_certificate_reference IS NOT NULL
       AND a1_valid_until IS NOT NULL)
      OR
      (a1_status = 'unverified'
       AND a1_certificate_reference IS NULL
       AND a1_valid_until IS NULL)
      OR
      (a1_status = 'not_applicable'
       AND a1_certificate_reference IS NULL
       AND a1_valid_until IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_person_social_discount_claims (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  employee_id        BIGINT UNSIGNED NOT NULL,
  status             ENUM('not_claimed','verified','unverified') NOT NULL,
  effective_from     DATE NOT NULL,
  effective_to       DATE NULL,
  evidence_reference VARCHAR(500) NULL,
  evidence_note      VARCHAR(500) NULL,
  created_by         BIGINT UNSIGNED NULL,
  updated_by         BIGINT UNSIGNED NULL,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_pp_social_discount_supplier_id (supplier_id, id),
  UNIQUE KEY uq_pp_social_discount_start (supplier_id, employee_id, effective_from),
  KEY idx_pp_social_discount_effective
    (supplier_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_pp_social_discount_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_social_discount_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_social_discount_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_pp_social_discount_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_pp_social_discount_evidence
    CHECK (
      (status = 'verified' AND evidence_reference IS NOT NULL)
      OR
      (status <> 'verified' AND evidence_reference IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
