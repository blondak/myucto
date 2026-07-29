-- MyÚčto.cz — Epic F3: karty dlouhodobého majetku a technická zhodnocení
--
-- Karty HM/DNM/neodpisovaného majetku (§26–33 ZDP, ČÚS 013). Sazby/koeficienty
-- ZDP jsou konstanty ve strategiích (api/src/Service/Accounting/Assets/Strategy),
-- NE v DB — viz R4. Účty (majetkový, oprávky, pořízení) nese karta, kontace
-- posting_rules dodávají jen nákladovou stranu (R18).
--
-- Jediný ALTER existující tabulky: journal_entries.source_type ENUM rozšířen
-- o 'depreciation' a 'asset_disposal' (append na konec, R2/R3). journal_entries
-- je tabulka forku (1005_), MODIFY je idempotentní.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + MODIFY + INSERT ... WHERE NOT EXISTS.

SET NAMES utf8mb4;

-- Karty dlouhodobého majetku (Epic F3). Sazby/koeficienty ZDP jsou konstanty
-- ve strategiích (api/src/Service/Accounting/Assets/Strategy), NE v DB — viz R4.
CREATE TABLE IF NOT EXISTS assets (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  inventory_number          VARCHAR(30) NOT NULL COMMENT 'inventární číslo, unikátní per firma',
  name                      VARCHAR(255) NOT NULL,
  description               TEXT NULL,
  kind                      ENUM('tangible','intangible') NOT NULL DEFAULT 'tangible',
  asset_account_code        VARCHAR(10) NOT NULL DEFAULT '022' COMMENT 'majetkový účet 01x/02x/03x',
  accumulated_account_code  VARCHAR(10) NULL COMMENT 'oprávky 07x/08x; NULL = neodpisovaný (§27, pozemky)',
  acquisition_account_code  VARCHAR(10) NOT NULL DEFAULT '042' COMMENT 'pořízení 041/042',
  purchase_invoice_id       BIGINT UNSIGNED NULL COMMENT 'zdrojová PF (is_fixed_asset)',
  purchase_invoice_item_id  BIGINT NULL COMMENT 'řádek PF u mixed dokladů; bez FK (vzor expense_category_id)',
  input_price               DECIMAL(14,2) NOT NULL COMMENT 'vstupní cena §29 v CZK',
  acquisition_date          DATE NOT NULL COMMENT 'datum pořízení',
  put_into_use_date         DATE NULL COMMENT 'datum zařazení do užívání (start odpisů)',
  disposal_date             DATE NULL,
  disposal_type             ENUM('sold','liquidated','donated','damaged') NULL,
  disposal_price            DECIMAL(14,2) NULL COMMENT 'prodejní cena bez DPH — jen evidenční (R20)',
  status                    ENUM('draft','in_use','disposed') NOT NULL DEFAULT 'draft',
  tax_method                ENUM('straight','accelerated','extraordinary','by_accounting','none') NOT NULL DEFAULT 'straight',
  tax_group                 TINYINT UNSIGNED NULL COMMENT 'odpisová skupina 1-6 (§30), povinná pro straight/accelerated',
  tax_first_year_increase   ENUM('none','p10','p15','p20') NOT NULL DEFAULT 'none' COMMENT '§31/1 b-d, jen skupiny 1-3 + první odpisovatel',
  is_first_owner            TINYINT(1) NOT NULL DEFAULT 0,
  is_m1_vehicle             TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'vozidlo kategorie M1 (§30e limit 2 mil.)',
  m1_limit_exception        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sanitní/pohřební/koncese — mimo limit §30e',
  is_zero_emission          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'bezemisní vozidlo (podmínka §30a)',
  opening_tax_years         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'let daňově odepsáno před evidencí (R23)',
  opening_tax_amount        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  opening_acc_months        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  opening_acc_amount        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  acc_useful_life_months    SMALLINT UNSIGNED NULL COMMENT 'účetní doba použitelnosti v měsících',
  acc_residual_value        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'účetní zbytková hodnota',
  created_by                INT NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assets_supplier_invno (supplier_id, inventory_number),
  KEY idx_assets_supplier_status (supplier_id, status),
  KEY idx_assets_purchase_invoice (purchase_invoice_id),
  CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_assets_pi FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Technická zhodnocení (§33) — historie, agregace per zdaňovací období dělá engine (R15).
CREATE TABLE IF NOT EXISTS asset_improvements (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  asset_id             BIGINT UNSIGNED NOT NULL,
  completed_on         DATE NOT NULL COMMENT 'datum dokončení TZ',
  amount               DECIMAL(14,2) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_invoice_id  BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_asset_date (asset_id, completed_on),
  KEY idx_ai_supplier (supplier_id),
  CONSTRAINT fk_ai_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_pi FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rozšíření source_type deníku o odpisy a vyřazení (R2/R3). journal_entries je tabulka
-- forku (1005_), append na konec ENUM = bezpečné, MODIFY je idempotentní.
ALTER TABLE journal_entries
  MODIFY COLUMN source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal') NOT NULL DEFAULT 'manual';

-- Doplnění kontací vyřazení (nákladová strana dle typu, R18/R19). Styl seedu dle 1006_.
INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
SELECT NULL, s.rule_key, s.description, s.debit_account_code, s.credit_account_code, 0, 1
FROM (
              SELECT 'asset.disposal.liquidated.residual' AS rule_key, 'Vyřazení likvidací — doodepsání zůstatkové ceny (551 / oprávky dle karty)' AS description, '551' AS debit_account_code, NULL AS credit_account_code
    UNION ALL SELECT 'asset.disposal.donated.residual',     'Vyřazení darem — zůstatková cena (543 / oprávky dle karty)',                '543', NULL
    UNION ALL SELECT 'asset.disposal.damaged.residual',     'Vyřazení pro manko a škodu — zůstatková cena (549 / oprávky dle karty)',    '549', NULL
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM posting_rules pr
  WHERE pr.supplier_id IS NULL AND pr.rule_key = s.rule_key AND pr.priority = 0
);
