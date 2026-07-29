-- MyÚčto.cz — EP-6: povinná inventarizace rozvahových účtů + manifest balíčku +
-- stav `completed_with_warnings` uzávěrkového balíčku.
--
-- Dosud inventarizace (BalanceInventoryService) jen generovala soupis účetních
-- zůstatků k rozvahovému dni bez perzistence skutečného stavu — nešlo evidovat
-- napočítaný stav, inventurní rozdíl, odpovědnou osobu ani odkaz na podepsaný
-- protokol, a inventarizace nebyla mezi povinnými kontrolami před uzavřením knih.
--
-- Nově:
--   accounting_balance_inventory        = hlavička inventarizace per období
--       (stav dokončení, odpovědná osoba, datum, odkaz na protokol, počty).
--   accounting_balance_inventory_items  = per rozvahový účet — účetní (book) stav,
--       skutečný (counted) stav, rozdíl a stav vyřešení (open/resolved) + poznámka.
--
-- Uzavření knih (ClosingService::closeBooks) blokuje kontrola inventory_unresolved:
-- knihy nelze uzavřít, dokud inventarizace není označena `completed` a všechny
-- inventurní rozdíly nejsou vyřešené/potvrzené (resolution='resolved').
--
-- import_jobs.status += 'completed_with_warnings' — uzávěrkový balíček je
-- `completed` jen když všechny POVINNÉ části uspěly; když povinné OK a doplňkové
-- selhaly → `completed_with_warnings`; když povinná selže → `failed`.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + MODIFY (opakovatelné).

SET NAMES utf8mb4;

-- Uzávěrkový balíček: nový terminální stav pro „hotovo, ale s upozorněními"
-- (povinné části OK, doplňkové části selhaly). Append na KONEC ENUM (R6 —
-- zachová interní indexy stávajících hodnot).
ALTER TABLE import_jobs
    MODIFY COLUMN status
        ENUM('queued', 'running', 'completed', 'failed', 'cancelled', 'completed_with_warnings')
        NOT NULL DEFAULT 'queued';

-- Hlavička inventarizace rozvahových účtů per účetní období (§29–30 ZoÚ).
CREATE TABLE IF NOT EXISTS accounting_balance_inventory (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  period_id          BIGINT UNSIGNED NOT NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress'
                        COMMENT 'in_progress = rozpracováno; completed = uzavřená inventarizace (podklad pro uzavření knih)',
  responsible_person VARCHAR(190) NULL COMMENT 'odpovědná osoba za inventarizaci (§30/2 ZoÚ)',
  inventory_date     DATE NULL COMMENT 'den provedení fyzické/dokladové inventury',
  protocol_ref       VARCHAR(190) NULL COMMENT 'odkaz na podepsaný inventarizační protokol (č. j. / DMS)',
  note               VARCHAR(1000) NULL,
  item_count         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'počet inventarizovaných účtů',
  unresolved_count   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'počet nevyřešených rozdílů (blokuje uzavření knih)',
  completed_at       DATETIME NULL,
  updated_by         INT NULL COMMENT 'user id — kdo naposledy uložil',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_abi_period (supplier_id, period_id),
  KEY idx_abi_supplier (supplier_id),
  CONSTRAINT fk_abi_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_abi_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per rozvahový účet — účetní vs. skutečný stav a stav vyřešení rozdílu.
CREATE TABLE IF NOT EXISTS accounting_balance_inventory_items (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id      INT UNSIGNED NOT NULL,
  inventory_id     BIGINT UNSIGNED NOT NULL,
  account_id       INT UNSIGNED NOT NULL,
  account_code     VARCHAR(20) NOT NULL,
  book_balance     DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'účetní zůstatek k rozvahovému dni (MD kladně)',
  counted_balance  DECIMAL(15,2) NULL COMMENT 'skutečný (napočítaný) stav; NULL = dosud nenapočítáno',
  difference       DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'counted - book (0 při nenapočítaném)',
  resolution       ENUM('open','resolved') NOT NULL DEFAULT 'open'
                      COMMENT 'resolved = rozdíl vyřešen/potvrzen účetní; open = nevyřešeno (blokuje uzavření)',
  note             VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_abii_account (inventory_id, account_id),
  KEY idx_abii_supplier (supplier_id, inventory_id),
  CONSTRAINT fk_abii_inventory FOREIGN KEY (inventory_id) REFERENCES accounting_balance_inventory(id) ON DELETE CASCADE,
  CONSTRAINT fk_abii_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
