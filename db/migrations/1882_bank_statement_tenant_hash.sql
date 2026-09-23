-- Stejný obsah bankovního výpisu může patřit různým firmám, zejména po obnově archivu.
-- NULL vlastníka zůstává jedním dočasným prostorem pro neidentifikované výpisy.
ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS dedup_scope_supplier_id INT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(supplier_id, 0)) PERSISTENT
    AFTER supplier_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_bs_supplier_hash (supplier_id, file_hash),
  ADD UNIQUE KEY IF NOT EXISTS uq_bs_scope_hash (dedup_scope_supplier_id, file_hash);

ALTER TABLE bank_statements
  DROP INDEX IF EXISTS uq_bs_hash;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS portable_fingerprint CHAR(64) NULL AFTER import_fingerprint;
