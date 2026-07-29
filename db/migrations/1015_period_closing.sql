-- MyÚčto.cz — Epic F4: uzávěrka — stav 'approved', konkurence a kroky průvodce
--
-- Stavový automat období rozšířen o 'approved' (§17/7 ZoÚ — po schválení závěrky
-- oprava jen v následujícím období, §35). row_version drží optimistickou
-- konkurenci (CAS, R4); closed_by/approved_at/approved_by jsou evidenční sloupce
-- bez FK (vzor assets.created_by). Kroky průvodce v accounting_closing_steps
-- nesou stav + payload (podklady precheck, FX rozpad per doklad, id zápisů).
--
-- journal_entries.source_type: append 'fx_revaluation' na konec ENUM (R6) —
-- FX přecenění zůstává ve výkazech, closing/opening jsou z nich vyloučené (R16).
--
-- Idempotence: MODIFY + ADD COLUMN IF NOT EXISTS + CREATE TABLE IF NOT EXISTS
-- + INSERT ... WHERE NOT EXISTS (vzor 1006_).

SET NAMES utf8mb4;

-- Epic F4: stav 'approved' (§17/7 ZoÚ), optimistická konkurence a audit sloupce období.
ALTER TABLE accounting_periods
  MODIFY COLUMN status ENUM('open','closing','closed','approved') NOT NULL DEFAULT 'open';
ALTER TABLE accounting_periods
  ADD COLUMN IF NOT EXISTS row_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Optimistická konkurence (CAS, R4)' AFTER closed_at,
  ADD COLUMN IF NOT EXISTS closed_by   INT NULL COMMENT 'user id — kdo uzavřel' AFTER row_version,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL COMMENT 'okamžik schválení závěrky (§17/7)' AFTER closed_by,
  ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER approved_at;

-- Nový source_type pro přecenění k rozvahovému dni (append na konec ENUM, R6).
ALTER TABLE journal_entries
  MODIFY COLUMN source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal','fx_revaluation') NOT NULL DEFAULT 'manual';

-- Kroky uzávěrkového průvodce per období (stav + payload s podklady/detaily).
CREATE TABLE IF NOT EXISTS accounting_closing_steps (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  period_id    BIGINT UNSIGNED NOT NULL,
  step_key     ENUM('precheck','depreciation','fx_revaluation','estimates','deferrals',
                    'close_books','open_next') NOT NULL,
  status       ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  payload      JSON NULL COMMENT 'podklady kroku: precheck výsledky, FX rozpad per doklad, id dohadových zápisů…',
  note         VARCHAR(500) NULL,
  done_at      DATETIME NULL,
  done_by      INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acs_period_step (period_id, step_key),
  KEY idx_acs_supplier (supplier_id, period_id),
  CONSTRAINT fk_acs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_acs_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontace: účet Peníze na cestě pro dvě nohy převodů (R14). Idempotentní INSERT vzor 1006_.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, 'transfer.money_in_transit', 'Převody mezi účty — Peníze na cestě (261)', '261', NULL, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id IS NULL AND rule_key = 'transfer.money_in_transit');
