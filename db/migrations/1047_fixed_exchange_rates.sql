-- MyÚčto.cz — Fáze F: pevný kurz per firma (§24 odst. 7 ZoÚ)
--
-- Účetní jednotka si vnitřním předpisem může zvolit PEVNÝ kurz použitý po celé
-- účetní období (měsíc / rok) místo denního kurzu ČNB k DUZP. Režim je per firma
-- (accounting_supplier_settings.fx_rate_mode); default 'daily' = beze změny
-- chování (denní ČNB kurz k DUZP, jak zavedla Fáze C3').
--
-- Pevné kurzy se ukládají per firma × měna × rok × měsíc (month=0 = roční pevný
-- kurz platný pro celý rok; 1..12 = měsíční pevný kurz). Jeden zdroj pravdy: kurz
-- se při uložení dokladu zapíše do invoices.exchange_rate (přes ExchangeRateApplier),
-- odkud ho čtou i PostingService i VatLedgerService — žádný druhý výpočet.
--
-- Přepnutí režimu je jen do budoucna (ovlivní jen nově ukládané doklady) —
-- už zaúčtované doklady si drží zafixovaný kurz na hlavičce.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
  ADD COLUMN IF NOT EXISTS fx_rate_mode ENUM('daily','fixed_monthly','fixed_annual')
    NOT NULL DEFAULT 'daily'
    COMMENT '§24/7 ZoÚ: denní ČNB (default) / pevný měsíční / pevný roční kurz';

CREATE TABLE IF NOT EXISTS accounting_fixed_exchange_rates (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  currency_code  CHAR(3) NOT NULL COMMENT 'ISO 4217 (EUR, USD…)',
  fiscal_year    SMALLINT UNSIGNED NOT NULL,
  month          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = roční pevný kurz, 1..12 = měsíční',
  rate           DECIMAL(15,6) NOT NULL COMMENT 'kolik CZK za 1 jednotku měny (jako invoices.exchange_rate)',
  source         ENUM('manual','cnb') NOT NULL DEFAULT 'manual' COMMENT 'zdroj hodnoty (ČNB návrh vs. ruční)',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_fxr_supplier_ccy_period (supplier_id, currency_code, fiscal_year, month),
  KEY idx_fxr_supplier (supplier_id, fiscal_year),
  CONSTRAINT fk_fxr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
