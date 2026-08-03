-- MyÚčto.cz — MZ-08/MZ-09: klasifikované vstupy a neměnné revize mzdového běhu.

SET NAMES utf8mb4;

ALTER TABLE payroll_employments
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_employment_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_component_definitions (
  id                           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                  INT UNSIGNED NOT NULL,
  code                         VARCHAR(64) NOT NULL,
  name                         VARCHAR(190) NOT NULL,
  component_kind               ENUM(
    'base_wage','hourly_wage','task_wage','bonus','premium','commission',
    'allowance','compensation','severance','competitive_clause','backpay',
    'non_cash','benefit_meal',
    'benefit_vehicle','benefit_pension','benefit_care','benefit_education',
    'benefit_recreation','benefit_health','risky_savings',
    'travel_reimbursement','other'
  ) NOT NULL,
  value_kind                   ENUM('monetary','non_monetary') NOT NULL,
  frequency_kind               ENUM('regular','one_off') NOT NULL,
  tax_treatment                ENUM('included','exempt','withholding_candidate','manual_review') NOT NULL,
  social_treatment             ENUM('included','excluded','manual_review') NOT NULL,
  health_treatment             ENUM('included','excluded','manual_review') NOT NULL,
  average_earning_treatment    ENUM('included','excluded','manual_review') NOT NULL,
  enforcement_treatment        ENUM('included','excluded','manual_review') NOT NULL,
  jmhz_treatment               ENUM('included','excluded','manual_review') NOT NULL,
  statistics_treatment         ENUM('included','excluded','manual_review') NOT NULL,
  accounting_debit_code        VARCHAR(16) NULL,
  accounting_credit_code       VARCHAR(16) NULL,
  annual_limit_minor           BIGINT UNSIGNED NULL,
  valid_from                   DATE NOT NULL DEFAULT '2026-01-01',
  valid_to                     DATE NULL,
  is_active                    TINYINT(1) NOT NULL DEFAULT 1,
  row_version                  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_component_version (supplier_id, code, valid_from),
  KEY idx_payroll_component_effective (supplier_id, code, valid_from, valid_to),
  UNIQUE KEY uq_payroll_component_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_component_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_component_code
    CHECK (code REGEXP '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
  CONSTRAINT chk_payroll_component_active CHECK (is_active IN (0, 1)),
  CONSTRAINT chk_payroll_component_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_component_limit
    CHECK (annual_limit_minor IS NULL OR annual_limit_minor > 0),
  CONSTRAINT chk_payroll_component_accounts CHECK (
    (accounting_debit_code IS NULL OR accounting_debit_code REGEXP '^[0-9]{3,16}$')
    AND
    (accounting_credit_code IS NULL OR accounting_credit_code REGEXP '^[0-9]{3,16}$')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_recurring_components (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  employment_id          BIGINT UNSIGNED NOT NULL,
  component_id           BIGINT UNSIGNED NOT NULL,
  amount_minor           BIGINT NOT NULL,
  valid_from             DATE NOT NULL,
  valid_to               DATE NULL,
  allocation_rule        ENUM('full_month','calendar_days','working_days','hours','manual_review')
                         NOT NULL DEFAULT 'full_month',
  maximum_amount_minor   BIGINT UNSIGNED NULL,
  row_version            INT UNSIGNED NOT NULL DEFAULT 1,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_recurring_supplier_id (supplier_id, id),
  KEY idx_payroll_recurring_effective
    (supplier_id, employment_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_recurring_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_recurring_component
    FOREIGN KEY (supplier_id, component_id)
    REFERENCES payroll_component_definitions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_recurring_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_recurring_maximum
    CHECK (maximum_amount_minor IS NULL OR maximum_amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_input_imports (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  period_start         DATE NOT NULL,
  source_kind          ENUM('csv','api') NOT NULL,
  source_name          VARCHAR(190) NOT NULL,
  content_hash         BINARY(32) NOT NULL,
  status               ENUM('preview','accepted','rejected') NOT NULL DEFAULT 'preview',
  row_count            INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_count       INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count       INT UNSIGNED NOT NULL DEFAULT 0,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_input_import_hash (supplier_id, content_hash),
  UNIQUE KEY uq_payroll_input_import_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_input_import_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_input_import_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_input_import_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_input_import_counts CHECK (
    accepted_count + rejected_count <= row_count
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_input_import_rows (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  import_id            BIGINT UNSIGNED NOT NULL,
  source_row_number    INT UNSIGNED NOT NULL,
  external_id          VARCHAR(190) NULL,
  status               ENUM('valid','error','accepted') NOT NULL,
  normalized_payload   LONGTEXT NOT NULL CHECK (JSON_VALID(normalized_payload)),
  errors_json          LONGTEXT NOT NULL CHECK (JSON_VALID(errors_json)),
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_input_import_row (supplier_id, import_id, source_row_number),
  CONSTRAINT fk_payroll_input_import_row_parent
    FOREIGN KEY (supplier_id, import_id)
    REFERENCES payroll_input_imports (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_inputs (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  employee_id                BIGINT UNSIGNED NOT NULL,
  employment_id              BIGINT UNSIGNED NOT NULL,
  component_id               BIGINT UNSIGNED NOT NULL,
  period_start               DATE NOT NULL,
  source_period_start        DATE NULL,
  amount_minor               BIGINT NOT NULL,
  quantity_milliunits        BIGINT NULL,
  source_kind                ENUM('manual','recurring','time','absence','import','correction') NOT NULL,
  external_id                VARCHAR(190) NULL,
  import_id                  BIGINT UNSIGNED NULL,
  status                     ENUM('draft','approved','locked','cancelled') NOT NULL DEFAULT 'draft',
  component_snapshot_json    LONGTEXT NULL CHECK (
    component_snapshot_json IS NULL OR JSON_VALID(component_snapshot_json)
  ),
  component_snapshot_hash    BINARY(32) NULL,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                 BIGINT UNSIGNED NULL,
  approved_by                BIGINT UNSIGNED NULL,
  approved_at                DATETIME NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  external_dedupe_key        VARCHAR(190)
    GENERATED ALWAYS AS (
      CASE
        WHEN external_id IS NULL OR status = 'cancelled' THEN NULL
        ELSE CONCAT(source_kind, ':', external_id)
      END
    ) STORED,

  UNIQUE KEY uq_payroll_input_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_input_external
    (supplier_id, employment_id, period_start, external_dedupe_key),
  KEY idx_payroll_input_period_status (supplier_id, period_start, status),
  KEY idx_payroll_input_employee (supplier_id, employee_id, period_start),
  CONSTRAINT fk_payroll_input_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_input_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_input_component
    FOREIGN KEY (supplier_id, component_id)
    REFERENCES payroll_component_definitions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_input_import
    FOREIGN KEY (supplier_id, import_id)
    REFERENCES payroll_input_imports (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_input_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_input_approved_by
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_input_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_input_source_period CHECK (
    source_period_start IS NULL OR DAY(source_period_start) = 1
  ),
  CONSTRAINT chk_payroll_input_snapshot CHECK (
    (status IN ('draft','cancelled'))
    OR (component_snapshot_json IS NOT NULL AND component_snapshot_hash IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_benefit_accumulators (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  employee_id            BIGINT UNSIGNED NOT NULL,
  component_id           BIGINT UNSIGNED NOT NULL,
  input_id               BIGINT UNSIGNED NOT NULL,
  tax_year               SMALLINT UNSIGNED NOT NULL,
  amount_minor           BIGINT NOT NULL,
  status                 ENUM('active','reversed') NOT NULL DEFAULT 'active',
  reversed_entry_id      BIGINT UNSIGNED NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_benefit_input (supplier_id, input_id),
  UNIQUE KEY uq_payroll_benefit_supplier_id (supplier_id, id),
  KEY idx_payroll_benefit_year
    (supplier_id, employee_id, component_id, tax_year, status),
  CONSTRAINT fk_payroll_benefit_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_benefit_component
    FOREIGN KEY (supplier_id, component_id)
    REFERENCES payroll_component_definitions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_benefit_input
    FOREIGN KEY (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_benefit_reversal
    FOREIGN KEY (supplier_id, reversed_entry_id)
    REFERENCES payroll_benefit_accumulators (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_benefit_year CHECK (tax_year BETWEEN 2000 AND 2200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_risky_savings_contributions (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  employment_id          BIGINT UNSIGNED NOT NULL,
  period_start           DATE NOT NULL,
  qualifying_shifts      INT UNSIGNED NOT NULL,
  assessment_base_minor  BIGINT UNSIGNED NOT NULL,
  contribution_minor     BIGINT UNSIGNED NOT NULL,
  status                 ENUM('manual_review','approved','paid') NOT NULL
                         DEFAULT 'manual_review',
  product_reference      VARCHAR(190) NULL,
  row_version            INT UNSIGNED NOT NULL DEFAULT 1,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_risky_savings_period
    (supplier_id, employment_id, period_start),
  UNIQUE KEY uq_payroll_risky_savings_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_risky_savings_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_risky_savings_period CHECK (DAY(period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_travel_compensation_links (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  input_id               BIGINT UNSIGNED NOT NULL,
  source_system          VARCHAR(64) NOT NULL,
  source_reference       VARCHAR(190) NOT NULL,
  classification_status  ENUM('classified','manual_review') NOT NULL
                         DEFAULT 'manual_review',
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_travel_source
    (supplier_id, source_system, source_reference),
  UNIQUE KEY uq_payroll_travel_input (supplier_id, input_id),
  CONSTRAINT fk_payroll_travel_input
    FOREIGN KEY (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  office_id             BIGINT UNSIGNED NULL,
  office_scope_id       BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(office_id, 0)) STORED,
  period_start          DATE NOT NULL,
  status                ENUM(
    'draft','inputs_locked','calculated','reviewed','approved','posted',
    'payment_ready','paid','closed','correction_pending','reopened','cancelled'
  ) NOT NULL DEFAULT 'draft',
  current_revision_no   INT UNSIGNED NOT NULL DEFAULT 0,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  updated_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_run_period (supplier_id, period_start, office_scope_id),
  UNIQUE KEY uq_payroll_run_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_run_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_run_office
    FOREIGN KEY (supplier_id, office_id)
    REFERENCES payroll_offices (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_run_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_run_period CHECK (DAY(period_start) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_revisions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  revision_no           INT UNSIGNED NOT NULL,
  previous_revision_id  BIGINT UNSIGNED NULL,
  revision_kind         ENUM('regular','correction') NOT NULL DEFAULT 'regular',
  status                ENUM('snapshot','calculated','reviewed','approved','superseded') NOT NULL,
  schema_version        VARCHAR(32) NOT NULL,
  ruleset_manifest_hash CHAR(64) NOT NULL,
  input_snapshot_json   LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash   CHAR(64) NOT NULL,
  result_snapshot_json  LONGTEXT NULL CHECK (
    result_snapshot_json IS NULL OR JSON_VALID(result_snapshot_json)
  ),
  result_snapshot_hash  CHAR(64) NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  calculated_by         BIGINT UNSIGNED NULL,
  reviewed_by           BIGINT UNSIGNED NULL,
  approved_by           BIGINT UNSIGNED NULL,
  calculated_at         DATETIME NULL,
  reviewed_at           DATETIME NULL,
  approved_at           DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_run_revision (supplier_id, run_id, revision_no),
  UNIQUE KEY uq_payroll_run_revision_idempotency (supplier_id, idempotency_key_hash),
  UNIQUE KEY uq_payroll_run_revision_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_run_revision_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_revision_previous
    FOREIGN KEY (supplier_id, previous_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_revision_calculated_by
    FOREIGN KEY (calculated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_run_revision_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_run_revision_approved_by
    FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_run_revision_number CHECK (revision_no > 0),
  CONSTRAINT chk_payroll_run_revision_hashes CHECK (
    ruleset_manifest_hash REGEXP '^[0-9a-f]{64}$'
    AND input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    AND (
      result_snapshot_hash IS NULL
      OR result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_persons (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  result_json           LONGTEXT NULL CHECK (result_json IS NULL OR JSON_VALID(result_json)),
  result_hash           CHAR(64) NULL,
  status                ENUM('pending','calculated','blocked') NOT NULL DEFAULT 'pending',

  UNIQUE KEY uq_payroll_run_person (supplier_id, revision_id, employee_id),
  CONSTRAINT fk_payroll_run_person_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_person_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_employments (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  employment_id         BIGINT UNSIGNED NOT NULL,
  input_json            LONGTEXT NOT NULL CHECK (JSON_VALID(input_json)),
  input_hash            CHAR(64) NOT NULL,
  result_json           LONGTEXT NULL CHECK (result_json IS NULL OR JSON_VALID(result_json)),
  result_hash           CHAR(64) NULL,
  status                ENUM('pending','calculated','blocked') NOT NULL DEFAULT 'pending',

  UNIQUE KEY uq_payroll_run_employment (supplier_id, revision_id, employment_id),
  CONSTRAINT fk_payroll_run_employment_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_employment_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_employment_relation
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_run_employment_input_hash
    CHECK (input_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_run_employment_result_hash
    CHECK (result_hash IS NULL OR result_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_validations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  severity              ENUM('blocker','warning','info') NOT NULL,
  code                  VARCHAR(96) NOT NULL,
  entity_type           VARCHAR(64) NOT NULL,
  entity_id             BIGINT UNSIGNED NULL,
  message               VARCHAR(500) NOT NULL,
  remediation_path      VARCHAR(500) NULL,
  requires_override     TINYINT(1) NOT NULL DEFAULT 0,
  override_reason       VARCHAR(500) NULL,
  overridden_by         BIGINT UNSIGNED NULL,
  overridden_at         DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_payroll_run_validation_revision (supplier_id, revision_id, severity),
  CONSTRAINT fk_payroll_run_validation_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_validation_override_user
    FOREIGN KEY (overridden_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_run_validation_override CHECK (
    requires_override IN (0, 1)
    AND (
      (overridden_at IS NULL AND override_reason IS NULL)
      OR
      (overridden_at IS NOT NULL AND override_reason IS NOT NULL)
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_events (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NULL,
  event_type            VARCHAR(96) NOT NULL,
  from_status           VARCHAR(32) NULL,
  to_status             VARCHAR(32) NULL,
  actor_user_id         BIGINT UNSIGNED NULL,
  reason                VARCHAR(500) NULL,
  metadata_json         LONGTEXT NOT NULL CHECK (JSON_VALID(metadata_json)),
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_payroll_run_event_timeline (supplier_id, run_id, id),
  CONSTRAINT fk_payroll_run_event_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_event_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_event_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
