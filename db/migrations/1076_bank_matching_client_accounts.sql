-- E5 navazuje učicí statistiky párování na kanonický registr účtů partnerů z 1075.

SET NAMES utf8mb4;

INSERT INTO client_bank_accounts
    (supplier_id, client_id, account_number, bank_code, account_key, bank_key,
     source_bank_statement, first_seen_at, last_seen_at)
SELECT supplier_id, client_id, counterparty_account, NULLIF(counterparty_bank, ''),
       counterparty_account, counterparty_bank, 1, MIN(created_at), COALESCE(MAX(last_match_at), MAX(created_at))
  FROM bank_counterparty_map
 WHERE counterparty_account IS NOT NULL AND client_id IS NOT NULL
 GROUP BY supplier_id, client_id, counterparty_account, counterparty_bank
ON DUPLICATE KEY UPDATE
    source_bank_statement = 1,
    last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at));

ALTER TABLE bank_counterparty_map
  ADD COLUMN IF NOT EXISTS client_bank_account_id BIGINT UNSIGNED NULL AFTER supplier_id;

UPDATE bank_counterparty_map bcm
JOIN client_bank_accounts cba
  ON cba.supplier_id = bcm.supplier_id
 AND cba.client_id = bcm.client_id
 AND cba.account_key = bcm.counterparty_account
 AND cba.bank_key = bcm.counterparty_bank
SET bcm.client_bank_account_id = cba.id
WHERE bcm.client_bank_account_id IS NULL;

ALTER TABLE bank_counterparty_map DROP INDEX IF EXISTS uq_bcm;
ALTER TABLE bank_counterparty_map DROP FOREIGN KEY IF EXISTS fk_bcm_client_account;

ALTER TABLE bank_counterparty_map
  MODIFY COLUMN client_bank_account_id BIGINT UNSIGNED NOT NULL,
  MODIFY COLUMN counterparty_account VARCHAR(35) NULL,
  MODIFY COLUMN counterparty_bank VARCHAR(10) NULL,
  MODIFY COLUMN client_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_bcm_account_side (supplier_id, client_bank_account_id, side),
  ADD KEY IF NOT EXISTS idx_bcm_account_lookup (supplier_id, side, client_bank_account_id),
  ADD CONSTRAINT fk_bcm_client_account FOREIGN KEY (client_bank_account_id)
    REFERENCES client_bank_accounts(id) ON DELETE CASCADE;
