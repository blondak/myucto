-- MyÚčto.cz — D5 (audit 2026-07, Fáze D): perzistence kategorie ÚJ a kritérií při uzávěrce.
--
-- Zmrazí kritéria kategorizace (§1d ZoÚ po novele 316/2025 Sb.) pro právě uzavírané
-- období. Řeší tři věci najednou:
--   1. výkon — scope='auto' a EntityCategoryService::evaluate() přepočítávaly aktiva
--      netto + čistý obrat pro KAŽDÉ minulé období při každém volání (N rozvah);
--      po uzávěrce se čte zmražený řádek místo přepočtu,
--   2. historické zaměstnance — avg_employees se zmrazí ke dni uzávěrky (dnes se pro
--      všechna období použije aktuální hodnota, protože jinde není evidovaná),
--   3. správnost §1e kontinuity — kategorie se mění po dvou po sobě jdoucích rozvahových
--      dnech se shodnou raw kategorií; zdrojem je zmražený raw_category uzavřených období.
--
-- Neuzavřená období / období bez záznamu → EntityCategoryService fallbackuje na přepočet
-- (zachováno current behavior, žádná regrese pro firmy bez historie).
--
-- Tenant izolace: supplier_id + FK; unique (supplier_id, period_id) = jeden řádek per období.
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS entity_category_history (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  period_id     BIGINT UNSIGNED NOT NULL,
  assets_net    DECIMAL(18,2) NOT NULL COMMENT 'Aktiva netto k rozvahovému dni (kritérium §1d)',
  net_turnover  DECIMAL(18,2) NOT NULL COMMENT 'Čistý obrat 601+602+604 za období (kritérium §1d, R9)',
  avg_employees INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Průměrný počet zaměstnanců zmražený ke dni uzávěrky',
  raw_category  ENUM('micro','small','medium','large') NOT NULL COMMENT 'Raw kategorie k rozvahovému dni (bez §1e kontinuity)',
  frozen_at     DATETIME NOT NULL COMMENT 'Kdy byl řádek zmražen (krok close_books uzávěrky)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_ech_supplier_period (supplier_id, period_id),
  KEY idx_ech_supplier (supplier_id),
  CONSTRAINT fk_ech_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ech_period FOREIGN KEY (period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
