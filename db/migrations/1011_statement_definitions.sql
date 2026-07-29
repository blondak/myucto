-- MyÚčto.cz — Epic F2: definice účetních výkazů (rozvaha + výkaz zisku a ztráty)
--
-- Verzované definice výkazů dle vyhlášky 500/2002 Sb. (přílohy 1 a 2):
--   statement_versions        — verze výkazu v čase (valid_from/valid_to; R4 — globální, bez supplier_id)
--   statement_rows            — řádky výkazu (strom přes parent_row_code, level pro zkrácený rozsah R12)
--   statement_account_map     — mapa syntetických účtů na řádky (prefix match, brutto/korekce,
--                               balance_condition pro saldové účty R8, sign pro odčitatelné položky)
--   accounting_supplier_settings — per-firma atributy pro kategorizaci ÚJ (R10, R11)
--
-- Idempotence: CREATE TABLE IF NOT EXISTS (MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS statement_versions (
  id             SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  statement_type ENUM('balance_sheet','income_statement') NOT NULL,
  version_code   VARCHAR(32) NOT NULL COMMENT 'např. vyhl500-2002/2024',
  valid_from     DATE NOT NULL,
  valid_to       DATE NULL COMMENT 'NULL = dosud platná',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sv_type_code (statement_type, version_code),
  KEY idx_sv_type_valid (statement_type, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS statement_rows (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id      SMALLINT UNSIGNED NOT NULL,
  row_code        VARCHAR(20) NOT NULL COMMENT 'A. / B.I. / B.I.2.1. / I. / *',
  parent_row_code VARCHAR(20) NULL,
  section         ENUM('assets','liabilities','profit_loss') NOT NULL,
  label           VARCHAR(255) NOT NULL,
  level           TINYINT UNSIGNED NOT NULL COMMENT '0=celkem, 1=písmeno/římská VZZ, 2=římská/číslo, 3=arabská, 4=pod-položka',
  position        SMALLINT UNSIGNED NOT NULL COMMENT 'pořadí ve výkazu',
  row_type        ENUM('detail','subtotal','computed') NOT NULL DEFAULT 'detail',
  calc_key        VARCHAR(32) NULL COMMENT 'profit_current | operating_profit | financial_profit | profit_before_tax | profit_after_tax | net_turnover',
  UNIQUE KEY uq_sr_version_code (version_id, row_code),
  KEY idx_sr_version_pos (version_id, position),
  CONSTRAINT fk_sr_version FOREIGN KEY (version_id) REFERENCES statement_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS statement_account_map (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_id        SMALLINT UNSIGNED NOT NULL,
  row_code          VARCHAR(20) NOT NULL,
  account_prefix    VARCHAR(10) NOT NULL COMMENT 'prefix match na syntetický kód; seed = přesné 3místné kódy',
  target            ENUM('gross','correction') NOT NULL DEFAULT 'gross' COMMENT 'correction jen u aktiv (brutto/korekce/netto)',
  balance_condition ENUM('any','debit','credit') NOT NULL DEFAULT 'any' COMMENT 'saldové účty: debit→aktiva, credit→pasiva',
  sign              TINYINT NOT NULL DEFAULT 1 COMMENT '-1 = odečítá se (429, 432, 252)',
  UNIQUE KEY uq_sam (version_id, row_code, account_prefix, target, balance_condition),
  KEY idx_sam_version_prefix (version_id, account_prefix),
  CONSTRAINT fk_sam_version FOREIGN KEY (version_id) REFERENCES statement_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_supplier_settings (
  supplier_id              INT UNSIGNED PRIMARY KEY,
  avg_employees            SMALLINT UNSIGNED NULL COMMENT 'průměrný přepočtený počet zaměstnanců (ručně)',
  statement_scope_override ENUM('full','small','micro') NULL COMMENT 'NULL = dle spočítané kategorie ÚJ',
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ass_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
