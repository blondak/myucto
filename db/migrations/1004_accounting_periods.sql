-- MyÚčto.cz — Epic F1: účetní období (accounting periods) pro podvojné účetnictví
--
-- Období drží stav uzávěrky (open → closing → closed). Deník (journal_entries)
-- se váže na období přes period_id; zápisy v uzavřeném období jsou neměnné
-- (§35 ZoÚ) — oprava jen stornem + novým zápisem (vynucuje PostingService).
--
-- Kalendářní i hospodářský rok — proto starts_on/ends_on (ne jen fiscal_year).
-- fiscal_year je identifikátor období (label + UNIQUE per firma).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_periods (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  fiscal_year  SMALLINT UNSIGNED NOT NULL COMMENT 'Účetní rok (kalendářní i hospodářský — přesné hranice v starts_on/ends_on)',
  starts_on    DATE NOT NULL,
  ends_on      DATE NOT NULL,
  status       ENUM('open','closing','closed') NOT NULL DEFAULT 'open',
  closed_at    DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_period_supplier_year (supplier_id, fiscal_year),
  KEY idx_period_supplier (supplier_id),
  CONSTRAINT fk_period_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
