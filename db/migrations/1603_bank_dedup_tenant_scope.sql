-- Stejné bankovní bajty a pohyby mohou existovat v nezávislých firmách.
-- Scope 0 zachovává deduplikaci historických výpisů bez vlastníka; takový
-- výpis se automaticky neslučuje s výpisem konkrétní firmy.
SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS dedup_scope_id INT UNSIGNED
    AS (COALESCE(supplier_id, 0)) PERSISTENT,
  ADD UNIQUE KEY IF NOT EXISTS uq_bs_scope_hash (dedup_scope_id, file_hash);

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS dedup_scope_id INT UNSIGNED NOT NULL DEFAULT 0;

DELIMITER //
CREATE OR REPLACE TRIGGER trg_bank_transaction_dedup_insert
BEFORE INSERT ON bank_transactions FOR EACH ROW
BEGIN
  DECLARE owner_scope INT UNSIGNED DEFAULT 0;
  -- Zámek rodiče serializuje INSERT s případným přiřazením vlastníka výpisu.
  SELECT COALESCE(supplier_id, 0) INTO owner_scope
    FROM bank_statements WHERE id = NEW.statement_id LOCK IN SHARE MODE;
  SET NEW.dedup_scope_id = owner_scope;
END//
CREATE OR REPLACE TRIGGER trg_bank_transaction_dedup_update
BEFORE UPDATE ON bank_transactions FOR EACH ROW
BEGIN
  DECLARE owner_scope INT UNSIGNED DEFAULT 0;
  SELECT COALESCE(supplier_id, 0) INTO owner_scope
    FROM bank_statements WHERE id = NEW.statement_id LOCK IN SHARE MODE;
  SET NEW.dedup_scope_id = owner_scope;
END//
CREATE OR REPLACE TRIGGER trg_bank_statement_dedup_owner_update
AFTER UPDATE ON bank_statements FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id) THEN
    UPDATE bank_transactions SET dedup_scope_id = COALESCE(NEW.supplier_id, 0)
      WHERE statement_id = NEW.id;
  END IF;
END//
DELIMITER ;

UPDATE bank_transactions bt
JOIN bank_statements bs ON bs.id = bt.statement_id
SET bt.dedup_scope_id = COALESCE(bs.supplier_id, 0)
WHERE bt.dedup_scope_id <> COALESCE(bs.supplier_id, 0);

ALTER TABLE bank_transactions
  ADD UNIQUE KEY IF NOT EXISTS uq_bt_scope_fingerprint (dedup_scope_id, import_fingerprint);

-- Nové constrainty a triggery jsou připravené před odstraněním globálních.
ALTER TABLE bank_statements DROP INDEX IF EXISTS uq_bs_hash;
ALTER TABLE bank_transactions DROP INDEX IF EXISTS uq_bt_import_fingerprint;
