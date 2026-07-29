-- MyÚčto.cz — E8: autoritativní tenant bankovního výpisu a denní limit AI klasifikací

SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_bs_supplier (supplier_id, statement_date);

UPDATE bank_statements bs
JOIN (
  SELECT bt.statement_id, MIN(owner.supplier_id) supplier_id
    FROM bank_transactions bt
    JOIN (
      SELECT source_id bank_transaction_id, supplier_id FROM journal_entries WHERE source_type='bank'
      UNION ALL
      SELECT bank_transaction_id, supplier_id FROM invoice_payments WHERE bank_transaction_id IS NOT NULL
      UNION ALL
      SELECT bank_transaction_id, supplier_id FROM payment_matches WHERE bank_transaction_id IS NOT NULL
    ) owner ON owner.bank_transaction_id=bt.id
   GROUP BY bt.statement_id
  HAVING COUNT(DISTINCT owner.supplier_id)=1
) owned ON owned.statement_id=bs.id
SET bs.supplier_id=owned.supplier_id
WHERE bs.supplier_id IS NULL
  AND NOT EXISTS (
    SELECT 1
      FROM bank_transactions conflict_bt
      JOIN (
        SELECT source_id bank_transaction_id,supplier_id FROM journal_entries WHERE source_type='bank'
        UNION ALL
        SELECT bank_transaction_id,supplier_id FROM invoice_payments WHERE bank_transaction_id IS NOT NULL
        UNION ALL
        SELECT bank_transaction_id,supplier_id FROM payment_matches WHERE bank_transaction_id IS NOT NULL
      ) conflict_owner ON conflict_owner.bank_transaction_id=conflict_bt.id
     WHERE conflict_bt.statement_id=bs.id
  );

UPDATE bank_statements bs
JOIN (
  SELECT bs2.id statement_id,MIN(sba.supplier_id) supplier_id
    FROM bank_statements bs2
    JOIN supplier_bank_accounts sba ON sba.is_active=1
     AND (
       REPLACE(UPPER(bs2.account_number),' ','') IN (
         REPLACE(UPPER(COALESCE(sba.account_number,'')),' ',''),
         REPLACE(UPPER(COALESCE(sba.iban,'')),' ','')
       )
       OR TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(bs2.account_number,'/',1),'[^0-9]',''))=sba.account_canonical
     )
     AND (COALESCE(bs2.bank_code,'')='' OR COALESCE(sba.bank_code_norm,'')='' OR bs2.bank_code=sba.bank_code_norm)
   GROUP BY bs2.id
  HAVING COUNT(DISTINCT sba.supplier_id)=1
) owned ON owned.statement_id=bs.id
SET bs.supplier_id=owned.supplier_id
WHERE bs.supplier_id IS NULL;

CREATE TABLE IF NOT EXISTS ai_daily_usage (
  supplier_id INT UNSIGNED NOT NULL,
  usage_date DATE NOT NULL,
  classification_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (supplier_id, usage_date),
  CONSTRAINT fk_aidu_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
