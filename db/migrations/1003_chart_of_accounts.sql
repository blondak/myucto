-- MyÚčto.cz — Epic F1: účtová osnova (chart of accounts) pro podvojné účetnictví
--
-- Směrná účtová osnova dle vyhlášky 500/2002 Sb. (příloha č. 4) — syntetické
-- 3místné účty; analytické účty si firmy vytvářejí jako děti přes parent_id.
-- Šablona osnovy se do tabulky per-supplier naseeduje přes ChartOfAccountsSeeder,
-- když firma zapne double_entry (supplier.accounting_mode).
--
-- normal_side je ZÁMĚRNĚ NULLABLE: saldní/dvoustranné účty (343 DPH, 341 daň
-- z příjmů, 481 odložená daň, 431 VH, 097, 015, 414, 419, 491, 592, 395,
-- závěrkové 70x…) mohou skončit na MD i D podle znaménka zůstatku — stranu
-- určuje výpočet, ne pevná definice (viz recon Section C/E).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS (MariaDB 10.6+/11.8 native).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS chart_of_accounts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  account_code  VARCHAR(10) NOT NULL COMMENT 'Syntetický (3 číslice) nebo analytický (děti přes parent_id)',
  name          VARCHAR(190) NOT NULL,
  account_type  ENUM('asset','liability','equity','revenue','expense','offbalance') NOT NULL,
  normal_side   ENUM('debit','credit') NULL
                   COMMENT 'NULL = saldní/dvoustranný účet — strana dle znaménka zůstatku',
  is_synthetic  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = syntetický účet, 0 = analytický (má parent_id)',
  parent_id     BIGINT UNSIGNED NULL COMMENT 'Rodičovský syntetický účet pro analytiku (strom)',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_coa_supplier_code (supplier_id, account_code),
  KEY idx_coa_supplier_type (supplier_id, account_type),
  KEY idx_coa_parent (parent_id),
  CONSTRAINT fk_coa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_coa_parent   FOREIGN KEY (parent_id)   REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
