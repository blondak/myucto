-- MyÚčto.cz — Epic F4: číselné řady deníku (F1 B-b / F4 R13)
--
-- Per firma × řada × účetní rok; výdej čísla drží DocumentSeriesService
-- v transakci přes SELECT ... FOR UPDATE. Řádek řady vzniká lazy při prvním
-- výdeji (INSERT ... ON DUPLICATE KEY), default prefixy UZ/OT/KR/PP/ID.
-- Mezery po smazaných zápisech se nedorovnávají (§11 ZoÚ — označení dokladu).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

-- Číselné řady deníku (F1 B-b / F4 R13). Řádek vzniká lazy při prvním výdeji čísla.
CREATE TABLE IF NOT EXISTS accounting_document_series (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  series_code  ENUM('closing','opening','fx','transfer','manual') NOT NULL,
  fiscal_year  SMALLINT UNSIGNED NOT NULL,
  prefix       VARCHAR(10) NOT NULL COMMENT 'UZ/OT/KR/PP/ID — editovatelné per firma',
  next_number  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ads_supplier_series_year (supplier_id, series_code, fiscal_year),
  CONSTRAINT fk_ads_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
