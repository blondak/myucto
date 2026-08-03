-- MyÚčto.cz — čistá mzda, standardní srážky a přesná alokace výplaty.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_deduction_agreements (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  agreement_reference   VARCHAR(96) NOT NULL,
  title                 VARCHAR(190) NOT NULL,
  deduction_kind        ENUM(
    'advance','meal','contribution','damage','other'
  ) NOT NULL,
  status                ENUM('draft','active','paused','ended','cancelled')
                        NOT NULL DEFAULT 'draft',
  priority_no           INT UNSIGNED NOT NULL DEFAULT 100,
  requested_minor       BIGINT UNSIGNED NOT NULL,
  total_limit_minor     BIGINT UNSIGNED NULL,
  withheld_total_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  valid_from            DATE NOT NULL,
  valid_to              DATE NULL,
  recipient_reference   VARCHAR(190) NULL,
  note                  VARCHAR(500) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_by            BIGINT UNSIGNED NULL,
  updated_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_deduction_agreement_reference
    (supplier_id, employee_id, agreement_reference),
  UNIQUE KEY uq_payroll_deduction_agreement_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_deduction_agreement_owner
    (supplier_id, id, employee_id),
  KEY idx_payroll_deduction_agreement_active
    (supplier_id, employee_id, status, valid_from, valid_to),
  CONSTRAINT fk_payroll_deduction_agreement_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_agreement_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_deduction_agreement_updated_by
    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_deduction_agreement_interval
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_deduction_agreement_limit
    CHECK (
      total_limit_minor IS NULL
      OR withheld_total_minor <= total_limit_minor
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_deduction_ledger (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  agreement_id          BIGINT UNSIGNED NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  event_kind            ENUM('withheld','reversed','paid','payment_reversed')
                        NOT NULL,
  amount_minor          BIGINT NOT NULL,
  event_key_hash        BINARY(32) NOT NULL,
  source_ledger_id      BIGINT UNSIGNED NULL,
  metadata_json         LONGTEXT NOT NULL CHECK (JSON_VALID(metadata_json)),
  actor_user_id         BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_deduction_ledger_event
    (supplier_id, event_key_hash),
  UNIQUE KEY uq_payroll_deduction_ledger_supplier_id (supplier_id, id),
  KEY idx_payroll_deduction_ledger_employee
    (supplier_id, employee_id, id),
  CONSTRAINT fk_payroll_deduction_ledger_agreement
    FOREIGN KEY (supplier_id, agreement_id, employee_id)
    REFERENCES payroll_deduction_agreements
      (supplier_id, id, employee_id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_ledger_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_ledger_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_ledger_source
    FOREIGN KEY (supplier_id, source_ledger_id)
    REFERENCES payroll_deduction_ledger (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_deduction_ledger_actor
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_deduction_ledger_amount CHECK (amount_minor <> 0),
  CONSTRAINT chk_payroll_deduction_ledger_reversal CHECK (
    (event_kind IN ('withheld','paid') AND amount_minor > 0 AND source_ledger_id IS NULL)
    OR
    (event_kind IN ('reversed','payment_reversed')
      AND amount_minor < 0 AND source_ledger_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_net_results (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  cash_income_minor     BIGINT UNSIGNED NOT NULL,
  non_cash_income_minor BIGINT UNSIGNED NOT NULL,
  employee_social_minor BIGINT UNSIGNED NOT NULL,
  employee_health_minor BIGINT UNSIGNED NOT NULL,
  advance_tax_minor     BIGINT UNSIGNED NOT NULL,
  withholding_tax_minor BIGINT UNSIGNED NOT NULL,
  tax_bonus_minor       BIGINT UNSIGNED NOT NULL,
  correction_minor      BIGINT NOT NULL DEFAULT 0,
  deducted_minor        BIGINT UNSIGNED NOT NULL,
  net_payable_minor     BIGINT UNSIGNED NOT NULL,
  result_json           LONGTEXT NOT NULL CHECK (JSON_VALID(result_json)),
  result_hash           CHAR(64) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_net_result_revision_employee
    (supplier_id, revision_id, employee_id),
  UNIQUE KEY uq_payroll_net_result_supplier_id (supplier_id, id),
  CONSTRAINT fk_payroll_net_result_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_net_result_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_net_result_hash
    CHECK (result_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payout_rules (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  allocation_reference  VARCHAR(96) NOT NULL,
  destination_kind      ENUM('bank','cash') NOT NULL,
  destination_reference VARCHAR(190) NULL,
  allocation_kind       ENUM('fixed','percentage','remainder') NOT NULL,
  amount_minor          BIGINT UNSIGNED NULL,
  basis_points          INT UNSIGNED NULL,
  priority_no           INT UNSIGNED NOT NULL DEFAULT 100,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payout_rule_reference
    (supplier_id, employee_id, allocation_reference),
  UNIQUE KEY uq_payroll_payout_rule_supplier_id (supplier_id, id),
  KEY idx_payroll_payout_rule_active
    (supplier_id, employee_id, is_active, priority_no),
  CONSTRAINT fk_payroll_payout_rule_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_payout_rule_shape CHECK (
    (
      allocation_kind = 'fixed'
      AND amount_minor IS NOT NULL
      AND basis_points IS NULL
    )
    OR
    (
      allocation_kind = 'percentage'
      AND amount_minor IS NULL
      AND basis_points BETWEEN 0 AND 10000
    )
    OR
    (
      allocation_kind = 'remainder'
      AND amount_minor IS NULL
      AND basis_points IS NULL
    )
  ),
  CONSTRAINT chk_payroll_payout_rule_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payout_allocations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  net_result_id         BIGINT UNSIGNED NOT NULL,
  payout_rule_id        BIGINT UNSIGNED NULL,
  allocation_reference  VARCHAR(96) NOT NULL,
  destination_kind      ENUM('bank','cash') NOT NULL,
  destination_reference VARCHAR(190) NULL,
  allocation_kind       ENUM('fixed','percentage','remainder') NOT NULL,
  amount_minor          BIGINT UNSIGNED NOT NULL,
  allocation_order      INT UNSIGNED NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payout_allocation_revision
    (supplier_id, revision_id, employee_id, allocation_reference),
  KEY idx_payroll_payout_allocation_result (supplier_id, net_result_id),
  CONSTRAINT fk_payroll_payout_allocation_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payout_allocation_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payout_allocation_result
    FOREIGN KEY (supplier_id, net_result_id)
    REFERENCES payroll_net_results (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payout_allocation_rule
    FOREIGN KEY (supplier_id, payout_rule_id)
    REFERENCES payroll_payout_rules (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
