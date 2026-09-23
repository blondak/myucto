-- Úvěrové účty kreditních karet (kontokorent ke kartě) a nastavení jejich účtování.
--
-- Kreditní výpis je výpis účtu jako každý jiný: pohyby žijí v bank_transactions a účtují
-- se bankovní automatikou. Liší se jen vlastní noha zápisu - úvěrový účet se účtuje na
-- 231.xxx (krátkodobý úvěr), ne na 221.xxx. Proto:
--
--   * supplier_bank_accounts.kind dostane 'credit_card' - účet zůstává v registru vlastních
--     účtů, takže splátka z běžného účtu se rozpozná jako vlastní převod a výpis se připíše
--     firmě stejně jako u běžného účtu. Jeho analytic_suffix (221) zůstává prázdný.
--   * credit_card_accounts nese údaje úvěrového účtu a analytiku 231 (jen číslo suffixu,
--     kód skládá aplikace: '231.' + suffix, stejně jako u bankovních účtů a karet).
--   * credit_card_settings: účty úroků, poplatků, splátek, výběrů a odměn per firma.
--     NULL = výchozí účet (562 / 568 / 261 / 261 / 648).
--
-- Migrace nic nepřeúčtovává a do deníku nesahá.

SET NAMES utf8mb4;

ALTER TABLE supplier_bank_accounts
  MODIFY COLUMN kind ENUM('current','savings','term_deposit','credit_card') NOT NULL DEFAULT 'current';

CREATE TABLE IF NOT EXISTS credit_card_accounts (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  bank_account_id      BIGINT UNSIGNED NOT NULL COMMENT 'supplier_bank_accounts.id (kind = credit_card)',
  issuer               ENUM('kb','rb','csob','erste','other') NOT NULL DEFAULT 'other',
  label                VARCHAR(120) NOT NULL,
  account_number       VARCHAR(40) NOT NULL COMMENT 'číslo úvěrového účtu, u RB referenční číslo karty',
  bank_code            CHAR(4) NULL,
  currency             CHAR(3) NOT NULL DEFAULT 'CZK',
  credit_limit         DECIMAL(14,2) NULL,
  analytic_suffix      VARCHAR(6) NULL COMMENT 'analytika 231 - jen číslo, kód = 231.<suffix>',
  repayment_account    VARCHAR(40) NULL COMMENT 'účet, na který se splácí (RB: sběrný účet banky)',
  repayment_bank_code  CHAR(4) NULL,
  repayment_vs         VARCHAR(20) NULL,
  is_verified          TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = účet založil import výpisu',
  archived_at          DATETIME NULL,
  note                 VARCHAR(500) NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cca_bank_account (bank_account_id),
  UNIQUE KEY uq_cca_supplier_analytic (supplier_id, analytic_suffix),
  KEY idx_cca_supplier (supplier_id, archived_at),
  CONSTRAINT fk_cca_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_cca_bank_account FOREIGN KEY (bank_account_id) REFERENCES supplier_bank_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE credit_card_accounts DROP CONSTRAINT IF EXISTS chk_cca_analytic_suffix;
ALTER TABLE credit_card_accounts
  ADD CONSTRAINT chk_cca_analytic_suffix CHECK (analytic_suffix IS NULL OR analytic_suffix REGEXP '^[0-9]{1,6}$');

ALTER TABLE credit_card_accounts DROP CONSTRAINT IF EXISTS chk_cca_limit;
ALTER TABLE credit_card_accounts
  ADD CONSTRAINT chk_cca_limit CHECK (credit_limit IS NULL OR credit_limit >= 0);

ALTER TABLE credit_card_accounts DROP CONSTRAINT IF EXISTS chk_cca_currency;
ALTER TABLE credit_card_accounts
  ADD CONSTRAINT chk_cca_currency CHECK (currency REGEXP '^[A-Z]{3}$');

CREATE TABLE IF NOT EXISTS credit_card_settings (
  supplier_id           INT UNSIGNED NOT NULL PRIMARY KEY,
  interest_account_id   BIGINT UNSIGNED NULL COMMENT 'úroky z úvěru (NULL = 562)',
  fee_account_id        BIGINT UNSIGNED NULL COMMENT 'poplatky (NULL = 568)',
  repayment_account_id  BIGINT UNSIGNED NULL COMMENT 'splátka bez protiúčtu (NULL = 261)',
  cash_account_id       BIGINT UNSIGNED NULL COMMENT 'výběr hotovosti kartou (NULL = 261)',
  reward_account_id     BIGINT UNSIGNED NULL COMMENT 'odměna / cashback (NULL = 648)',
  updated_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ccst_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccst_interest FOREIGN KEY (interest_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccst_fee FOREIGN KEY (fee_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccst_repayment FOREIGN KEY (repayment_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccst_cash FOREIGN KEY (cash_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccst_reward FOREIGN KEY (reward_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccst_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
