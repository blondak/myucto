-- Bankovní účty obchodních partnerů (clients = zákazník, dodavatel nebo obojí).
-- Zdrojové příznaky jsou nezávislé: tentýž účet může být současně nalezený
-- v registru DPH, potvrzený bankovní transakcí a ručně spravovaný.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS client_bank_accounts (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id              INT UNSIGNED NOT NULL,
  client_id                BIGINT UNSIGNED NOT NULL,
  account_number           VARCHAR(40) NOT NULL,
  bank_code                VARCHAR(11) NULL,
  iban                     VARCHAR(34) NULL,
  account_key              VARCHAR(64) NOT NULL COMMENT 'Normalizovaný účet pro shodu a hledání',
  bank_key                 VARCHAR(11) NOT NULL DEFAULT '',
  source_manual            TINYINT(1) NOT NULL DEFAULT 0,
  source_vat_registry      TINYINT(1) NOT NULL DEFAULT 0,
  source_bank_statement    TINYINT(1) NOT NULL DEFAULT 0,
  last_bank_transaction_id BIGINT UNSIGNED NULL,
  is_active                TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cba_client_account (client_id, account_key, bank_key),
  KEY idx_cba_supplier_account (supplier_id, account_key, bank_key),
  KEY idx_cba_client_active (client_id, is_active),
  KEY idx_cba_last_transaction (last_bank_transaction_id),
  CONSTRAINT fk_cba_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_cba_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_cba_bank_transaction FOREIGN KEY (last_bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
