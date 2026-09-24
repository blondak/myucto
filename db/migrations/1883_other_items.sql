SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS other_items (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  side                 ENUM('receivable','payable') NOT NULL,
  kind                 VARCHAR(64) NOT NULL DEFAULT 'other',
  title                VARCHAR(255) NOT NULL,
  partner_id           BIGINT UNSIGNED NULL,
  partner_name         VARCHAR(190) NULL,
  issued_on            DATE NOT NULL,
  accounting_on        DATE NULL,
  due_on               DATE NOT NULL,
  currency             CHAR(3) NOT NULL DEFAULT 'CZK',
  amount               DECIMAL(14,2) NOT NULL,
  exchange_rate        DECIMAL(18,8) NULL,
  amount_czk           DECIMAL(14,2) NULL,
  variable_symbol      VARCHAR(20) NULL,
  account_code         VARCHAR(20) NULL,
  counter_account_code VARCHAR(20) NULL,
  note                 TEXT NULL,
  status               ENUM('draft','confirmed','posted','reversed','cancelled') NOT NULL DEFAULT 'draft',
  document_no          VARCHAR(50) NULL,
  journal_entry_id     BIGINT UNSIGNED NULL,
  reversal_entry_id    BIGINT UNSIGNED NULL,
  posted_at            DATETIME NULL,
  created_by           BIGINT UNSIGNED NULL,
  updated_by           BIGINT UNSIGNED NULL,
  deleted_at           DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version          INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_other_item_doc_no (supplier_id, document_no),
  KEY idx_other_item_open (supplier_id, side, status, due_on),
  KEY idx_other_item_partner (supplier_id, partner_id, status),
  KEY idx_other_item_source (supplier_id, deleted_at, issued_on),
  CONSTRAINT fk_other_item_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_item_partner FOREIGN KEY (partner_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_item_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_item_reversal FOREIGN KEY (reversal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_item_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_item_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_other_item_amount CHECK (amount > 0),
  CONSTRAINT chk_other_item_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT chk_other_item_rate CHECK (exchange_rate IS NULL OR exchange_rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS other_item_allocations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  other_item_id       BIGINT UNSIGNED NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NULL,
  cash_document_id    BIGINT UNSIGNED NULL,
  amount              DECIMAL(14,2) NOT NULL,
  payment_on          DATE NOT NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_other_item_bank (other_item_id, bank_transaction_id),
  UNIQUE KEY uq_other_item_cash (other_item_id, cash_document_id),
  KEY idx_other_allocation_supplier (supplier_id, payment_on),
  KEY idx_other_allocation_bank (bank_transaction_id),
  KEY idx_other_allocation_cash (cash_document_id),
  CONSTRAINT fk_other_allocation_item FOREIGN KEY (other_item_id) REFERENCES other_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_other_allocation_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_allocation_bank FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_other_allocation_cash FOREIGN KEY (cash_document_id) REFERENCES cash_documents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_other_allocation_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_other_allocation_amount CHECK (amount > 0),
  CONSTRAINT chk_other_allocation_target CHECK ((bank_transaction_id IS NOT NULL) <> (cash_document_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounting_document_series MODIFY COLUMN series_code
  ENUM('closing','opening','fx','transfer','manual','cash_in','cash_out',
       'stock_in','stock_out','stock_transfer','offset','purchase_order',
       'other_receivable','other_payable') NOT NULL;

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
    'card_settlement','card_writeoff','other_item'
  ) NOT NULL DEFAULT 'manual';
