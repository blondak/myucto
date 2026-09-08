-- Externí identita pohybu přežije změnu lokálního supplier/IMAP ID při obnově.
SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS external_identity VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_bs_scope_external_identity (dedup_scope_id, source, external_identity);

UPDATE bank_statements target
JOIN (
  WITH candidates AS (
    SELECT id, dedup_scope_id, source,
           COALESCE(external_identity, CASE
             WHEN source = 'email_notice' AND currency IS NOT NULL THEN
               CONCAT('email-month:', SHA2(CONCAT(account_number, '|', COALESCE(bank_code, ''), '|',
                 currency, '|', DATE_FORMAT(statement_date, '%Y-%m')), 256))
             WHEN source = 'idoklad' AND source_ref REGEXP '^[1-9][0-9]*:[1-9][0-9]*:[0-9]{4}-(0[1-9]|1[0-2])$' THEN
               CONCAT('idoklad-month:', SUBSTRING(source_ref, LOCATE(':', source_ref) + 1))
             ELSE NULL END) identity_value
      FROM bank_statements
  )
  SELECT MIN(id) id, identity_value FROM candidates WHERE identity_value IS NOT NULL
   GROUP BY dedup_scope_id, source, identity_value HAVING COUNT(*) = 1
) recovered ON recovered.id = target.id
SET target.external_identity = recovered.identity_value
WHERE target.external_identity IS NULL;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS external_identity VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_bt_scope_external_identity (dedup_scope_id, source, external_identity);

-- Doplnit pouze jednoznačný důkaz. Neodhadovat jednosměrné legacy hashe,
-- neslučovat již existující duplicitní pohyby ani jejich účetní vazby.
UPDATE bank_transactions target
JOIN (
  WITH candidates AS (
    SELECT id, dedup_scope_id, source, external_identity identity_value
      FROM bank_transactions WHERE external_identity IS NOT NULL
    UNION
    SELECT id, dedup_scope_id, source,
           CONCAT('idoklad:', SUBSTRING_INDEX(source_ref, ':', -1))
      FROM bank_transactions
     WHERE source = 'idoklad' AND source_ref REGEXP '^[1-9][0-9]*:[1-9][0-9]*$'
    UNION
    SELECT id, dedup_scope_id, source,
           CONCAT('email:', SHA2(SUBSTRING(source_ref, LOCATE(':', source_ref) + 1), 256))
      FROM bank_transactions
     WHERE source = 'email_notice' AND source_ref REGEXP '^imap-[0-9]+:.+$'
    UNION
    SELECT bt.id, bt.dedup_scope_id, bt.source,
           CONCAT('email:', SHA2(COALESCE(NULLIF(pm.message_id, ''), pm.fallback_hash), 256))
      FROM bank_transactions bt
      JOIN bank_statements bs ON bs.id = bt.statement_id
      JOIN bank_email_processed_messages pm
        ON pm.bank_transaction_id = bt.id AND pm.supplier_id = bs.supplier_id
     WHERE bt.source = 'email_notice'
       AND COALESCE(NULLIF(pm.message_id, ''), NULLIF(pm.fallback_hash, '')) IS NOT NULL
  ), unambiguous AS (
    SELECT id, dedup_scope_id, source, MIN(identity_value) identity_value
      FROM candidates
     GROUP BY id, dedup_scope_id, source
    HAVING COUNT(DISTINCT identity_value) = 1
  ), unique_identities AS (
    SELECT dedup_scope_id, source, identity_value
      FROM candidates
     GROUP BY dedup_scope_id, source, identity_value
    HAVING COUNT(DISTINCT id) = 1
  )
  SELECT u.id, u.identity_value
    FROM unambiguous u
    JOIN unique_identities k ON k.dedup_scope_id = u.dedup_scope_id
      AND k.source = u.source AND k.identity_value = u.identity_value
) recovered ON recovered.id = target.id
SET target.external_identity = recovered.identity_value
WHERE target.external_identity IS NULL;
