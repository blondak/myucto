-- MyÚčto.cz — MZ-14: tenantově oddělené exekuční případy, důkazní fakta,
-- neměnné měsíční výsledky a oddělený ledger sraženo/deponováno/odesláno.
--
-- Citlivé spisy, účty a dokumenty do těchto tabulek nepatří. `case_key`,
-- `claim_key` a `enforcement_order_key` jsou interní neprůhledné identifikátory;
-- právní dokumenty ukládá payroll DMS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_enforcement_cases (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  employee_id          BIGINT UNSIGNED NOT NULL,
  case_key             VARCHAR(64) NOT NULL,
  case_kind            ENUM('enforcement','voluntary_agreement') NOT NULL,
  status               ENUM(
                         'received','withhold_and_hold','remit',
                         'deferred_no_withholding','deferred_hold',
                         'paid','stopped'
                       ) NOT NULL DEFAULT 'received',
  effective_from       DATE NOT NULL,
  effective_to         DATE NULL,
  evidence_complete    TINYINT(1) NOT NULL DEFAULT 0,
  recipient_verified   TINYINT(1) NOT NULL DEFAULT 0,
  row_version          INT UNSIGNED NOT NULL DEFAULT 1,
  created_by           BIGINT UNSIGNED NULL,
  updated_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_case_key (supplier_id, case_key),
  UNIQUE KEY uq_payroll_enforcement_case_tenant_id (supplier_id, id),
  KEY idx_payroll_enforcement_case_employee
    (supplier_id, employee_id, status, effective_from),
  CONSTRAINT fk_payroll_enforcement_case_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_enforcement_case_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_case_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_enforcement_case_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_case_interval
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_payroll_enforcement_case_flags
    CHECK (evidence_complete IN (0, 1) AND recipient_verified IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_claims (
  id                                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                       INT UNSIGNED NOT NULL,
  case_id                           BIGINT UNSIGNED NOT NULL,
  claim_key                         VARCHAR(64) NOT NULL,
  enforcement_order_key             VARCHAR(64) NULL,
  legal_basis                       ENUM('statutory','voluntary_agreement') NOT NULL,
  category                          ENUM(
                                      'current_maintenance','maintenance_arrears',
                                      'substitute_maintenance','other_priority',
                                      'non_priority'
                                    ) NOT NULL,
  outstanding_minor_units           BIGINT UNSIGNED NOT NULL,
  maintenance_weight_minor_units    BIGINT UNSIGNED NULL,
  priority_date                     DATE NULL,
  order_issued_on                   DATE NULL,
  legal_title_verified              TINYINT(1) NOT NULL DEFAULT 0,
  order_or_notice_delivered         TINYINT(1) NOT NULL DEFAULT 0,
  priority_classification_verified  TINYINT(1) NOT NULL DEFAULT 0,
  agreement_verified                TINYINT(1) NOT NULL DEFAULT 0,
  due_monetary_claim_verified       TINYINT(1) NOT NULL DEFAULT 0,
  is_active                         TINYINT(1) NOT NULL DEFAULT 1,
  row_version                       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_claim_key
    (supplier_id, claim_key),
  UNIQUE KEY uq_payroll_enforcement_claim_tenant_id (supplier_id, id),
  KEY idx_payroll_enforcement_claim_active
    (supplier_id, case_id, is_active, priority_date),
  CONSTRAINT fk_payroll_enforcement_claim_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_enforcement_claim_flags
    CHECK (
      legal_title_verified IN (0, 1)
      AND order_or_notice_delivered IN (0, 1)
      AND priority_classification_verified IN (0, 1)
      AND agreement_verified IN (0, 1)
      AND due_monetary_claim_verified IN (0, 1)
      AND is_active IN (0, 1)
    ),
  CONSTRAINT chk_payroll_enforcement_claim_maintenance_weight
    CHECK (
      category NOT IN (
        'current_maintenance','maintenance_arrears','substitute_maintenance'
      )
      OR maintenance_weight_minor_units > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_person_month_evidence (
  id                                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                             INT UNSIGNED NOT NULL,
  employee_id                             BIGINT UNSIGNED NOT NULL,
  period_start                            DATE NOT NULL,
  claim_register_evidence_complete        TINYINT(1) NOT NULL DEFAULT 0,
  dependants_evidence_complete            TINYINT(1) NOT NULL DEFAULT 0,
  spouse_evidence_complete                TINYINT(1) NOT NULL DEFAULT 0,
  pension_evidence                        ENUM('none','verified','unknown')
                                          NOT NULL DEFAULT 'unknown',
  has_multiple_payers                     TINYINT(1) NOT NULL DEFAULT 0,
  protected_amount_override_minor_units   BIGINT UNSIGNED NULL,
  protected_amount_override_verified      TINYINT(1) NOT NULL DEFAULT 0,
  insolvency_mode                         ENUM(
                                            'none','alert_only','approved_standard',
                                            'court_determined_amount'
                                          ) NOT NULL DEFAULT 'none',
  insolvency_decision_verified            TINYINT(1) NOT NULL DEFAULT 0,
  insolvency_recipient_verified           TINYINT(1) NOT NULL DEFAULT 0,
  court_determined_amount_minor_units     BIGINT UNSIGNED NULL,
  row_version                             INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by                              BIGINT UNSIGNED NULL,
  created_at                              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_month_evidence
    (supplier_id, employee_id, period_start),
  UNIQUE KEY uq_payroll_enforcement_month_evidence_id (supplier_id, id),
  CONSTRAINT fk_payroll_enforcement_month_evidence_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_month_evidence_user
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_month_first_day
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_enforcement_month_flags
    CHECK (
      claim_register_evidence_complete IN (0, 1)
      AND dependants_evidence_complete IN (0, 1)
      AND spouse_evidence_complete IN (0, 1)
      AND has_multiple_payers IN (0, 1)
      AND protected_amount_override_verified IN (0, 1)
      AND insolvency_decision_verified IN (0, 1)
      AND insolvency_recipient_verified IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_dependants (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  dependant_key               VARCHAR(64) NOT NULL,
  dependant_kind              ENUM('dependant','spouse_partner') NOT NULL,
  valid_from                  DATE NOT NULL,
  valid_to                    DATE NULL,
  eligibility_verified        TINYINT(1) NOT NULL DEFAULT 0,
  excluded_for_maintenance    TINYINT(1) NOT NULL DEFAULT 0,
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_dependant_version
    (supplier_id, employee_id, dependant_key, valid_from),
  UNIQUE KEY uq_payroll_enforcement_dependant_id (supplier_id, id),
  KEY idx_payroll_enforcement_dependant_effective
    (supplier_id, employee_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_enforcement_dependant_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_enforcement_dependant_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_enforcement_dependant_flags
    CHECK (
      eligibility_verified IN (0, 1)
      AND excluded_for_maintenance IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_month_results (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  revision_id                 BIGINT UNSIGNED NULL,
  revision_scope_id           BIGINT UNSIGNED
                              AS (IFNULL(revision_id, 0)) PERSISTENT,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  period_start                DATE NOT NULL,
  result_status               ENUM('supported','manual_review') NOT NULL,
  ruleset_id                  VARCHAR(128) NOT NULL,
  ruleset_hash                CHAR(64) NOT NULL,
  input_snapshot_json         LONGTEXT NOT NULL CHECK (JSON_VALID(input_snapshot_json)),
  input_snapshot_hash         CHAR(64) NOT NULL,
  result_snapshot_json        LONGTEXT NOT NULL CHECK (JSON_VALID(result_snapshot_json)),
  result_snapshot_hash        CHAR(64) NOT NULL,
  total_withheld_minor_units  BIGINT UNSIGNED NOT NULL,
  employee_payment_minor_units BIGINT UNSIGNED NOT NULL,
  employer_fee_minor_units    BIGINT UNSIGNED NOT NULL,
  idempotency_key_hash        BINARY(32) NOT NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_result_idempotency
    (supplier_id, idempotency_key_hash),
  UNIQUE KEY uq_payroll_enforcement_result_revision
    (supplier_id, revision_scope_id, period_start, employee_id),
  UNIQUE KEY uq_payroll_enforcement_result_tenant_id (supplier_id, id),
  KEY idx_payroll_enforcement_result_period
    (supplier_id, period_start, employee_id),
  CONSTRAINT fk_payroll_enforcement_result_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_result_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_enforcement_result_first_day
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_enforcement_result_hashes
    CHECK (
      ruleset_hash REGEXP '^[0-9a-f]{64}$'
      AND input_snapshot_hash REGEXP '^[0-9a-f]{64}$'
      AND result_snapshot_hash REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_allocations (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  month_result_id          BIGINT UNSIGNED NOT NULL,
  case_id                  BIGINT UNSIGNED NULL,
  claim_id                 BIGINT UNSIGNED NULL,
  allocation_key           VARCHAR(128) NOT NULL,
  first_pool_minor_units   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  second_pool_minor_units  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_minor_units        BIGINT UNSIGNED NOT NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_allocation
    (supplier_id, month_result_id, allocation_key),
  UNIQUE KEY uq_payroll_enforcement_allocation_id (supplier_id, id),
  CONSTRAINT fk_payroll_enforcement_allocation_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_allocation_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_allocation_claim
    FOREIGN KEY (supplier_id, claim_id)
    REFERENCES payroll_enforcement_claims (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_enforcement_allocation_total
    CHECK (total_minor_units = first_pool_minor_units + second_pool_minor_units)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  case_id        BIGINT UNSIGNED NOT NULL,
  command_name   VARCHAR(64) NOT NULL,
  from_status    VARCHAR(32) NULL,
  to_status      VARCHAR(32) NOT NULL,
  reason         VARCHAR(500) NULL,
  decision_evidence_hash CHAR(64) NULL,
  actor_user_id  BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_event_id (supplier_id, id),
  KEY idx_payroll_enforcement_event_timeline (supplier_id, case_id, id),
  CONSTRAINT fk_payroll_enforcement_event_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_event_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_event_evidence_hash
    CHECK (
      decision_evidence_hash IS NULL
      OR decision_evidence_hash REGEXP '^[0-9a-f]{64}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_enforcement_ledger (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  case_id               BIGINT UNSIGNED NULL,
  claim_id              BIGINT UNSIGNED NULL,
  month_result_id       BIGINT UNSIGNED NOT NULL,
  entry_kind            ENUM(
                          'withheld','held','remitted','released_to_employee',
                          'employer_fee','adjustment'
                        ) NOT NULL,
  amount_minor_units    BIGINT NOT NULL,
  calculation_entry_key VARCHAR(100)
    AS (
      IF(
        entry_kind IN ('withheld','held','employer_fee'),
        CONCAT(entry_kind, ':', IFNULL(case_id, 0), ':', IFNULL(claim_id, 0)),
        NULL
      )
    ) PERSISTENT,
  idempotency_key_hash  BINARY(32) NOT NULL,
  actor_user_id         BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_enforcement_ledger_idempotency
    (supplier_id, idempotency_key_hash),
  UNIQUE KEY uq_payroll_enforcement_ledger_calculation_entry
    (supplier_id, month_result_id, calculation_entry_key),
  UNIQUE KEY uq_payroll_enforcement_ledger_id (supplier_id, id),
  KEY idx_payroll_enforcement_ledger_case
    (supplier_id, case_id, month_result_id, id),
  CONSTRAINT fk_payroll_enforcement_ledger_case
    FOREIGN KEY (supplier_id, case_id)
    REFERENCES payroll_enforcement_cases (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_ledger_claim
    FOREIGN KEY (supplier_id, claim_id)
    REFERENCES payroll_enforcement_claims (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_ledger_result
    FOREIGN KEY (supplier_id, month_result_id)
    REFERENCES payroll_enforcement_month_results (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_enforcement_ledger_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_enforcement_ledger_amount
    CHECK (
      (entry_kind = 'adjustment' AND amount_minor_units <> 0)
      OR (entry_kind <> 'adjustment' AND amount_minor_units > 0)
    ),
  CONSTRAINT chk_payroll_enforcement_ledger_owner
    CHECK (
      (entry_kind = 'employer_fee' AND case_id IS NULL AND claim_id IS NULL)
      OR (
        entry_kind IN ('withheld','held','remitted','released_to_employee')
        AND ((case_id IS NULL AND claim_id IS NULL)
          OR (case_id IS NOT NULL AND claim_id IS NOT NULL))
      )
      OR (
        entry_kind = 'adjustment'
        AND (claim_id IS NULL OR case_id IS NOT NULL)
      )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
