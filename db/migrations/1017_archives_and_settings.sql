-- MyÚčto.cz — Epic F4: metadata per-firma archivů a uzávěrková nastavení
--
-- accounting_archives eviduje exportované ZIP archivy (R15) — soubor leží
-- v RuntimePaths::storage('archives/sup-{N}'), zde metadata + sha256 + scope
-- (kopie tabulka→počty řádků z manifestu). created_by je evidenční INT bez FK
-- (vzor assets.created_by).
--
-- accounting_supplier_settings (1011_): povinný audit §3a vyhl. 500/2002 (R18),
-- opt-in číselná řada ručních zápisů (R13), FX storno k 1. dni období (R11).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

-- Metadata per-firma archivů (R15).
CREATE TABLE IF NOT EXISTS accounting_archives (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  filename     VARCHAR(255) NOT NULL,
  size_bytes   BIGINT UNSIGNED NOT NULL,
  sha256       CHAR(64) NOT NULL,
  scope        JSON NULL COMMENT 'tabulky + počty řádků (kopie z manifestu)',
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aa_supplier (supplier_id, created_at),
  CONSTRAINT fk_aa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nastavení: povinný audit §3a (R18), opt-in manual řada (R13), FX storno k 1. dni (R11).
ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS statutory_audit     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'povinný audit §20 ZoÚ → plný rozsah výkazů (§3a vyhl.)',
  ADD COLUMN IF NOT EXISTS manual_doc_series   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'auto document_no pro ruční zápisy z řady ID',
  ADD COLUMN IF NOT EXISTS fx_reversal_at_open TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'storno přecenění saldokonta k 1. dni nového období (R11)';
