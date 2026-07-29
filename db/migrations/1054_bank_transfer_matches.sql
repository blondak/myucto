-- MyÚčto.cz — E3: kontrolní párování nohou vlastních převodů přes účet 261

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_transfer_matches (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  out_transaction_id BIGINT UNSIGNED NOT NULL,
  in_transaction_id  BIGINT UNSIGNED NOT NULL,
  amount             DECIMAL(14,2) NOT NULL,
  currency           CHAR(3) NOT NULL,
  matched_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_btm_out (out_transaction_id),
  UNIQUE KEY uq_btm_in (in_transaction_id),
  KEY idx_btm_supplier (supplier_id),
  CONSTRAINT fk_btm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_btm_out FOREIGN KEY (out_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_btm_in FOREIGN KEY (in_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  CONSTRAINT chk_btm_distinct CHECK (out_transaction_id <> in_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_posting_suggestions
  MODIFY COLUMN IF EXISTS source ENUM('rule','learned','payment_match','transfer') NOT NULL;

INSERT INTO posting_rules
       (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'bank.transfer.own.out', 'Vlastní převod — odchozí noha (peníze na cestě)', '261', '221', 0, 1
 WHERE NOT EXISTS (
   SELECT 1 FROM posting_rules
    WHERE supplier_id IS NULL AND rule_key = 'bank.transfer.own.out' AND priority = 0
 );

INSERT INTO posting_rules
       (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'bank.transfer.own.in', 'Vlastní převod — příchozí noha (peníze na cestě)', '221', '261', 0, 1
 WHERE NOT EXISTS (
   SELECT 1 FROM posting_rules
    WHERE supplier_id IS NULL AND rule_key = 'bank.transfer.own.in' AND priority = 0
 );
