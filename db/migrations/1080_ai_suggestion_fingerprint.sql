-- MyÚčto.cz — E8: vazba AI návrhu na obsah dokladu a oprava konfliktních výpisů

SET NAMES utf8mb4;

ALTER TABLE ai_suggestions
  ADD COLUMN IF NOT EXISTS input_hash CHAR(64) NULL AFTER payload_json;

UPDATE bank_statements bs
JOIN (
  SELECT bt.statement_id,COUNT(DISTINCT owner.supplier_id) owner_count
    FROM bank_transactions bt
    JOIN (
      SELECT source_id bank_transaction_id,supplier_id FROM journal_entries WHERE source_type='bank'
      UNION ALL
      SELECT bank_transaction_id,supplier_id FROM invoice_payments WHERE bank_transaction_id IS NOT NULL
      UNION ALL
      SELECT bank_transaction_id,supplier_id FROM payment_matches WHERE bank_transaction_id IS NOT NULL
    ) owner ON owner.bank_transaction_id=bt.id
   GROUP BY bt.statement_id
) conflict ON conflict.statement_id=bs.id AND conflict.owner_count>1
SET bs.supplier_id=NULL;
