-- MyÚčto.cz — E5: skórované párování bankovních transakcí

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_match_suggestions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NOT NULL,
  kind                ENUM('single','split','vs_typo','overpayment','fee_gap') NOT NULL,
  reason              VARCHAR(40) NOT NULL,
  candidates_json     JSON NOT NULL,
  top_score           DECIMAL(4,3) NOT NULL,
  margin              DECIMAL(4,3) NULL,
  deterministic_core  TINYINT(1) NOT NULL DEFAULT 0,
  status              ENUM('pending','accepted','rejected','superseded','auto_applied')
                      NOT NULL DEFAULT 'pending',
  accepted_candidate  SMALLINT UNSIGNED NULL,
  reviewed_by         BIGINT UNSIGNED NULL,
  reviewed_at         DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  pending_tx          BIGINT UNSIGNED AS (IF(status = 'pending', bank_transaction_id, NULL)) PERSISTENT,
  UNIQUE KEY uq_bms_pending (pending_tx),
  KEY idx_bms_supplier_status (supplier_id, status, created_at),
  KEY idx_bms_tx (bank_transaction_id),
  CONSTRAINT fk_bms_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bms_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_bms_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_counterparty_map (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  counterparty_account VARCHAR(35) NOT NULL,
  counterparty_bank    VARCHAR(10) NOT NULL DEFAULT '',
  side                 ENUM('incoming','outgoing') NOT NULL,
  client_id            BIGINT UNSIGNED NOT NULL,
  match_count          INT UNSIGNED NOT NULL DEFAULT 0,
  manual_count         INT UNSIGNED NOT NULL DEFAULT 0,
  contradiction_count  INT UNSIGNED NOT NULL DEFAULT 0,
  promoted_at          DATETIME NULL,
  demoted_at           DATETIME NULL,
  fee_pct_last         DECIMAL(6,4) NULL,
  fee_pct_samples      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_match_at        DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bcm (supplier_id, counterparty_account, counterparty_bank, side, client_id),
  KEY idx_bcm_lookup (supplier_id, counterparty_account, counterparty_bank, side),
  CONSTRAINT fk_bcm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bcm_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_match_audit (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NOT NULL,
  decision            ENUM('auto','suggest','accept','reject','manual','unmatch') NOT NULL,
  kind                ENUM('single','split','vs_typo','overpayment','fee_gap') NULL,
  invoice_ids         JSON NULL,
  purchase_invoice_id BIGINT UNSIGNED NULL,
  score               DECIMAL(4,3) NULL,
  margin              DECIMAL(4,3) NULL,
  deterministic_core  TINYINT(1) NULL,
  signals_json        JSON NULL,
  suggestion_id       BIGINT UNSIGNED NULL,
  reverted_at         DATETIME NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bma_supplier_created (supplier_id, decision, created_at),
  KEY idx_bma_tx (bank_transaction_id),
  CONSTRAINT fk_bma_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bma_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_bma_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
