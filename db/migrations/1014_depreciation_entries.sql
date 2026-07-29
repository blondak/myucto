-- MyÚčto.cz — Epic F3: uplatněné/zaúčtované odpisy majetku
--
-- Řádek = rok × druh (tax/accounting) SKUTEČNOSTI — budoucí roky se
-- NEmaterializují, plán odpisů se počítá on-the-fly (R11). Daňový řádek vzniká
-- při potvrzení roku (book), účetní při zaúčtování (journal zápis přes source
-- ('depreciation', id), R3/R12). Přerušení §26/8 = potvrzený rok s is_paused=1
-- a amount=0 (R14).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS (MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

-- Uplatněné/zaúčtované odpisy — rok × druh (tax/accounting). Budoucí roky se
-- NEmaterializují (plán on-the-fly, R11). Unikát (asset, kind, rok).
CREATE TABLE IF NOT EXISTS depreciation_entries (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  asset_id            BIGINT UNSIGNED NOT NULL,
  kind                ENUM('tax','accounting') NOT NULL,
  fiscal_year         SMALLINT UNSIGNED NOT NULL COMMENT 'zdaňovací období = kalendářní rok (R5)',
  amount              DECIMAL(14,2) NOT NULL COMMENT 'uplatněný odpis (tax: po krácení §30e a §26/7; accounting: zaúčtováno 551)',
  full_amount         DECIMAL(14,2) NOT NULL COMMENT 'stanovený odpis před krácením §30e (tax; u accounting = amount)',
  residual_value_end  DECIMAL(14,2) NOT NULL COMMENT 'ZC k 31.12. daného druhu (tax ZC z full_amount)',
  is_paused           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'rok přerušení §26/8 (amount=0)',
  is_half             TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'půlodpis §26/7 v roce vyřazení',
  months_count        TINYINT UNSIGNED NULL COMMENT 'počet měsíců (§30a, účetní)',
  detail              JSON NULL COMMENT 'měsíční rozpis [{month:"2026-04", amount:...}] pro §30a a účetní odpisy',
  status              ENUM('confirmed','posted') NOT NULL DEFAULT 'confirmed' COMMENT 'tax=confirmed; accounting=posted (má journal zápis přes source depreciation/id)',
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_de_asset_kind_year (asset_id, kind, fiscal_year),
  KEY idx_de_supplier_year (supplier_id, fiscal_year, kind),
  CONSTRAINT fk_de_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_de_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
