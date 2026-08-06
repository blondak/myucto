-- MyÚčto.cz — MZ-19-W09: odvozený inbox a alerting pro mzdová elektronická podání.
-- Read model nad payroll_obligations / payroll_submissions; nikdy neovlivňuje
-- jejich stav. Položky jsou klíčované SHA-256 zdrojového klíče (idempotence),
-- eskalace due_soon -> due_today -> overdue je monotónní a manuální akce
-- (potvrzení, odložení) mají optimistic lock přes row_version.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_submission_inbox_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  environment           ENUM('production','test') NOT NULL,
  obligation_id         BIGINT UNSIGNED NOT NULL,
  submission_id         BIGINT UNSIGNED NULL,
  source_key_hash       CHAR(64) NOT NULL,
  problem_kind          ENUM(
    'due_soon','due_today','overdue','rejected',
    'waiting_for_identity','manual_review'
  ) NOT NULL,
  escalation_level      ENUM('due_soon','due_today','overdue') NOT NULL
                          DEFAULT 'due_soon',
  status                ENUM('open','acknowledged','snoozed','resolved')
                          NOT NULL DEFAULT 'open',
  acknowledged_at       DATETIME NULL,
  acknowledged_by       BIGINT UNSIGNED NULL,
  snoozed_until         DATETIME NULL,
  snooze_reason         VARCHAR(500) NULL,
  snoozed_by            BIGINT UNSIGNED NULL,
  resolved_at           DATETIME NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_submission_inbox_items_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_submission_inbox_source (
    supplier_id, environment, source_key_hash
  ),
  KEY idx_payroll_submission_inbox_status (
    supplier_id, environment, status, escalation_level
  ),
  KEY idx_payroll_submission_inbox_obligation (
    supplier_id, environment, obligation_id
  ),
  CONSTRAINT fk_payroll_submission_inbox_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_inbox_obligation
    FOREIGN KEY (supplier_id, environment, obligation_id)
    REFERENCES payroll_obligations (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_inbox_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_inbox_acknowledger
    FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_submission_inbox_snoozer
    FOREIGN KEY (snoozed_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_submission_inbox_hash CHECK (
    source_key_hash REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_submission_inbox_ack CHECK (
    (acknowledged_at IS NULL) = (acknowledged_by IS NULL)
  ),
  CONSTRAINT chk_payroll_submission_inbox_snooze CHECK (
    (
      status = 'snoozed'
      AND snoozed_until IS NOT NULL
      AND snooze_reason IS NOT NULL
      AND snoozed_by IS NOT NULL
    )
    OR
    (
      status <> 'snoozed'
      AND snoozed_until IS NULL
      AND snooze_reason IS NULL
      AND snoozed_by IS NULL
    )
  ),
  CONSTRAINT chk_payroll_submission_inbox_resolved CHECK (
    (resolved_at IS NULL) = (status <> 'resolved')
  ),
  CONSTRAINT chk_payroll_submission_inbox_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
