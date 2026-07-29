-- E5: deduplikovaná učicí pozorování a idempotentní backfill historie párování.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_counterparty_observations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  map_id              BIGINT UNSIGNED NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NOT NULL,
  manual              TINYINT(1) NOT NULL DEFAULT 0,
  fee_pct             DECIMAL(6,4) NULL,
  observed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bco_map_tx (map_id, bank_transaction_id),
  KEY idx_bco_tx (bank_transaction_id),
  CONSTRAINT fk_bco_map FOREIGN KEY (map_id) REFERENCES bank_counterparty_map(id) ON DELETE CASCADE,
  CONSTRAINT fk_bco_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_e5_counterparty_history;
CREATE TEMPORARY TABLE tmp_e5_counterparty_history AS
SELECT ip.supplier_id, i.client_id, bt.id AS bank_transaction_id,
       CASE
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 9, 16))
         ELSE TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(bt.counterparty_account, '/', 1), '[^0-9]', ''))
       END AS account_key,
       CASE
         WHEN REGEXP_REPLACE(COALESCE(bt.counterparty_bank, ''), '[^0-9]', '') <> ''
           THEN LPAD(RIGHT(REGEXP_REPLACE(bt.counterparty_bank, '[^0-9]', ''), 4), 4, '0')
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 5, 4)
         ELSE ''
       END AS bank_key,
       'incoming' AS side, bt.match_status = 'manual' AS manual,
       COALESCE(bt.matched_at, ip.created_at) AS matched_at
  FROM invoice_payments ip
  JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ip.supplier_id
  JOIN bank_transactions bt ON bt.id = ip.bank_transaction_id
 WHERE ip.bank_transaction_id IS NOT NULL
   AND bt.counterparty_account IS NOT NULL AND bt.counterparty_account REGEXP '[1-9]'
UNION ALL
SELECT i.supplier_id, i.client_id, bt.id,
       CASE
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 9, 16))
         ELSE TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(bt.counterparty_account, '/', 1), '[^0-9]', ''))
       END,
       CASE
         WHEN REGEXP_REPLACE(COALESCE(bt.counterparty_bank, ''), '[^0-9]', '') <> ''
           THEN LPAD(RIGHT(REGEXP_REPLACE(bt.counterparty_bank, '[^0-9]', ''), 4), 4, '0')
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 5, 4)
         ELSE ''
       END,
       'incoming', bt.match_status = 'manual', COALESCE(bt.matched_at, CAST(bt.posted_at AS DATETIME))
  FROM bank_transactions bt
  JOIN invoices i ON i.id = bt.matched_invoice_id
 WHERE bt.match_status IN ('auto_exact','auto_partial','manual')
   AND bt.counterparty_account IS NOT NULL AND bt.counterparty_account REGEXP '[1-9]'
   AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
UNION ALL
SELECT pm.supplier_id, pi.vendor_id, bt.id,
       CASE
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN TRIM(LEADING '0' FROM SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 9, 16))
         ELSE TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(bt.counterparty_account, '/', 1), '[^0-9]', ''))
       END,
       CASE
         WHEN REGEXP_REPLACE(COALESCE(bt.counterparty_bank, ''), '[^0-9]', '') <> ''
           THEN LPAD(RIGHT(REGEXP_REPLACE(bt.counterparty_bank, '[^0-9]', ''), 4), 4, '0')
         WHEN UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')) REGEXP '^CZ[0-9]{22}$'
           THEN SUBSTRING(UPPER(REGEXP_REPLACE(bt.counterparty_account, '[^A-Za-z0-9]', '')), 5, 4)
         ELSE ''
       END,
       'outgoing', pm.match_type = 'manual', pm.created_at
  FROM payment_matches pm
  JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
  JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
 WHERE pm.purchase_invoice_id IS NOT NULL
   AND bt.counterparty_account IS NOT NULL AND bt.counterparty_account REGEXP '[1-9]';

INSERT INTO client_bank_accounts
    (supplier_id, client_id, account_number, bank_code, account_key, bank_key,
     source_bank_statement, last_bank_transaction_id, first_seen_at, last_seen_at)
SELECT supplier_id, client_id, account_key, NULLIF(bank_key, ''), account_key, bank_key,
       1, MAX(bank_transaction_id), MIN(matched_at), MAX(matched_at)
  FROM tmp_e5_counterparty_history
 WHERE account_key <> ''
 GROUP BY supplier_id, client_id, account_key, bank_key
ON DUPLICATE KEY UPDATE
    source_bank_statement = 1,
    last_bank_transaction_id = VALUES(last_bank_transaction_id),
    last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at));

INSERT INTO bank_counterparty_map
    (supplier_id, client_bank_account_id, side, match_count, manual_count, promoted_at, last_match_at)
SELECT h.supplier_id, cba.id, h.side,
       COUNT(DISTINCT h.bank_transaction_id),
       COUNT(DISTINCT IF(h.manual, h.bank_transaction_id, NULL)),
       IF(COUNT(DISTINCT h.bank_transaction_id) >= 3, NOW(), NULL), MAX(h.matched_at)
  FROM tmp_e5_counterparty_history h
  JOIN client_bank_accounts cba
    ON cba.supplier_id = h.supplier_id AND cba.client_id = h.client_id
   AND cba.account_key = h.account_key AND cba.bank_key = h.bank_key
 WHERE h.account_key <> ''
 GROUP BY h.supplier_id, cba.id, h.side
ON DUPLICATE KEY UPDATE
    match_count = GREATEST(match_count, VALUES(match_count)),
    manual_count = GREATEST(manual_count, VALUES(manual_count)),
    promoted_at = IF(promoted_at IS NULL AND contradiction_count = 0
                     AND GREATEST(match_count, VALUES(match_count)) >= 3, NOW(), promoted_at),
    last_match_at = GREATEST(COALESCE(last_match_at, VALUES(last_match_at)), VALUES(last_match_at));

INSERT IGNORE INTO bank_counterparty_observations
    (map_id, bank_transaction_id, manual, observed_at)
SELECT bcm.id, h.bank_transaction_id, MAX(h.manual), MAX(h.matched_at)
  FROM tmp_e5_counterparty_history h
  JOIN client_bank_accounts cba
    ON cba.supplier_id = h.supplier_id AND cba.client_id = h.client_id
   AND cba.account_key = h.account_key AND cba.bank_key = h.bank_key
  JOIN bank_counterparty_map bcm
    ON bcm.supplier_id = h.supplier_id AND bcm.client_bank_account_id = cba.id AND bcm.side = h.side
 GROUP BY bcm.id, h.bank_transaction_id;

DROP TEMPORARY TABLE IF EXISTS tmp_e5_counterparty_history;
