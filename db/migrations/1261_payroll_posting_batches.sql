-- 1261: MZ-18 — immutable payroll posting batches and target allocations.

SET NAMES utf8mb4;

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY COLUMN source_type
  ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
       'depreciation','asset_disposal','fx_revaluation','stock',
       'provision','income_tax','profit_distribution','offset','small_asset_accrual',
       'prepaid_expense_accrual','settlement','deferred_tax','payroll')
  NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS payroll_posting_batches (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  run_id            BIGINT UNSIGNED NOT NULL,
  revision_id       BIGINT UNSIGNED NOT NULL,
  previous_batch_id BIGINT UNSIGNED NULL,
  journal_entry_id  BIGINT UNSIGNED NULL,
  entry_date        DATE NOT NULL,
  status            ENUM('prepared','posted','no_change','reversed')
                    NOT NULL DEFAULT 'prepared',
  target_hash       CHAR(64) NOT NULL,
  delta_hash        CHAR(64) NOT NULL,
  created_by        BIGINT UNSIGNED NULL,
  posted_at         DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_posting_batch_revision (supplier_id, revision_id),
  UNIQUE KEY uq_payroll_posting_batch_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_posting_batch_journal (supplier_id, journal_entry_id),
  KEY idx_payroll_posting_batch_run (supplier_id, run_id, status),
  CONSTRAINT fk_payroll_posting_batch_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_posting_batch_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_posting_batch_previous
    FOREIGN KEY (supplier_id, previous_batch_id)
    REFERENCES payroll_posting_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_posting_batch_journal
    FOREIGN KEY (supplier_id, journal_entry_id)
    REFERENCES journal_entries (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_posting_batch_created_by
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_posting_batch_hashes CHECK (
    target_hash REGEXP '^[0-9a-f]{64}$'
    AND delta_hash REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_payroll_posting_batch_state CHECK (
    (status = 'prepared' AND journal_entry_id IS NULL AND posted_at IS NULL)
    OR (status = 'posted' AND journal_entry_id IS NOT NULL AND posted_at IS NOT NULL)
    OR (status = 'no_change' AND journal_entry_id IS NULL AND posted_at IS NOT NULL)
    OR (status = 'reversed' AND posted_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payroll_posting_allocations (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  batch_id       BIGINT UNSIGNED NOT NULL,
  allocation_key VARCHAR(191) NOT NULL,
  account_code   VARCHAR(16) NOT NULL,
  signed_minor   BIGINT NOT NULL,
  description    VARCHAR(255) NOT NULL,

  UNIQUE KEY uq_payroll_posting_allocation (supplier_id, batch_id, allocation_key),
  KEY idx_payroll_posting_allocation_account (supplier_id, account_code),
  CONSTRAINT fk_payroll_posting_allocation_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_posting_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_posting_allocation_amount CHECK (signed_minor <> 0),
  CONSTRAINT chk_payroll_posting_allocation_account CHECK (
    account_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
