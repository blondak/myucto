-- MyÚčto.cz — Epic F1: účetní deník (journal) pro podvojné účetnictví
--
-- journal_entries = hlavička účetního zápisu (chronologicky = deník, §13 ZoÚ),
-- journal_entry_lines = jednotlivé strany MD/D. Hlavní kniha (systematicky dle
-- účtu) je agregovaný pohled nad těmito řádky.
--
-- posted_at NULL = koncept (draft), NOT NULL = zaúčtováno (§12 průkaznost).
-- posted_by = kdo zaúčtoval (§11 podpisový záznam), reversed_by = odkaz na
-- opravný (storno) zápis (§35 neměnnost — po uzávěrce jen storno + nový zápis).
-- row_version = optimistická konkurence (přepis draftu více uživateli).
--
-- INVARIANT Σ MD = Σ D (podvojnost) vynucuje PostingService + testy Epic F1/F4:
-- MariaDB neumí cross-row CHECK přes řádky jednoho zápisu, proto se rovnováha
-- nevynucuje na úrovni schématu, ale v aplikační vrstvě (a testy ji hlídají).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  period_id     BIGINT UNSIGNED NOT NULL,
  entry_date    DATE NOT NULL COMMENT 'Datum účetního případu (okamžik uskutečnění, §11)',
  document_date DATE NULL COMMENT 'Datum vyhotovení / DUZP dokladu (pokud se liší)',
  document_no   VARCHAR(50) NULL COMMENT 'Označení dokladu / číslo v řadě',
  description   VARCHAR(255) NULL,
  source_type   ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening')
                   NOT NULL DEFAULT 'manual',
  source_id     BIGINT UNSIGNED NULL COMMENT 'ID zdrojového dokladu (dle source_type) — idempotence + rozklik',
  posted_at     DATETIME NULL COMMENT 'NULL = koncept; NOT NULL = zaúčtováno',
  posted_by     BIGINT UNSIGNED NULL COMMENT 'Kdo zaúčtoval (§11)',
  reversed_by   BIGINT UNSIGNED NULL COMMENT 'Odkaz na opravný (storno) zápis (§35)',
  row_version   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Optimistická konkurence',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_je_supplier_period (supplier_id, period_id),
  KEY idx_je_supplier_source (supplier_id, source_type, source_id),
  CONSTRAINT fk_je_supplier    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_je_period      FOREIGN KEY (period_id)   REFERENCES accounting_periods(id),
  CONSTRAINT fk_je_posted_by   FOREIGN KEY (posted_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_je_reversed_by FOREIGN KEY (reversed_by) REFERENCES journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entry_lines (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id     BIGINT UNSIGNED NOT NULL,
  supplier_id  INT UNSIGNED NOT NULL COMMENT 'Denormalizováno pro tenant filtr / hlavní knihu',
  account_id   BIGINT UNSIGNED NOT NULL,
  side         ENUM('debit','credit') NOT NULL,
  amount       DECIMAL(15,2) NOT NULL COMMENT 'Vždy kladná částka; MD/D dáno sloupcem side',
  cost_center  VARCHAR(50) NULL COMMENT 'Středisko / zakázka (volitelné analytické členění)',
  line_no      INT UNSIGNED NOT NULL DEFAULT 0,

  KEY idx_jel_supplier_account (supplier_id, account_id),
  KEY idx_jel_entry (entry_id),
  CONSTRAINT fk_jel_entry    FOREIGN KEY (entry_id)    REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_jel_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_jel_account  FOREIGN KEY (account_id)  REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
