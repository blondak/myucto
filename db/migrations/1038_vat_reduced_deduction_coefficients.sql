-- MyÚčto.cz — C2' (audit 2026-07, vat): krácený nárok na odpočet koeficientem § 76 ZDPH.
--
-- Dva aditivní kusy:
--
-- 1) purchase_invoices.vat_deduction dostává novou hodnotu 'reduced' = krácený nárok
--    na odpočet dle § 76 (společné vstupy používané zároveň pro plnění s nárokem
--    i pro osvobozená plnění bez nároku dle § 51). Na rozdíl od 'proportional' (§ 75,
--    poměr známý per doklad) se § 76 krátí až na ÚROVNI OBDOBÍ/ROKU vypořádacím
--    koeficientem — v účetnictví i v DPHDP3 ř. 40–42 se proto zaúčtuje/vykáže PLNÁ daň
--    (sloupec „Krácený odpočet"), krácení se promítne až souhrnně na ř. 52/53.
--    MODIFY je idempotentní (opakované spuštění nastaví tutéž definici).
--
-- 2) vat_coefficients — per (firma, rok) zálohový a vypořádací koeficient § 76:
--      provisional_percent = zálohový koeficient POUŽITÝ během roku (§ 76 odst. 6):
--        buď kvalifikovaný odhad účetní (první rok), nebo auto-převzetí z final_percent
--        předchozího vypořádaného roku (carry-forward, viz VatCoefficientRepository).
--      final_percent       = vypořádací koeficient (§ 76 odst. 7) spočtený ze SKUTEČNÝCH
--        dat celého roku, zaokrouhlený NAHORU na celé % (§ 76 odst. 5).
--      numerator_czk/denominator_czk = čitatel/jmenovatel koeficientu (audit stopa).
--      settled_at/settled_by = kdy a kým bylo vypořádání explicitně uloženo (nikdy jako
--        vedlejší efekt GET/download — viz dorevize B8).
--
-- Aditivní, idempotentní.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  MODIFY COLUMN vat_deduction ENUM('full','none','proportional','reduced') NOT NULL DEFAULT 'full';

CREATE TABLE IF NOT EXISTS vat_coefficients (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  year                SMALLINT UNSIGNED NOT NULL,
  provisional_percent TINYINT UNSIGNED NULL COMMENT 'zálohový koeficient § 76/6 (%, 0-100)',
  final_percent       TINYINT UNSIGNED NULL COMMENT 'vypořádací koeficient § 76/7 (%, zaokrouhleno nahoru dle § 76/5)',
  numerator_czk       BIGINT NULL COMMENT 'čitatel koeficientu (plnění s nárokem, celé Kč)',
  denominator_czk     BIGINT NULL COMMENT 'jmenovatel koeficientu (všechna plnění, celé Kč)',
  settled_at          TIMESTAMP NULL DEFAULT NULL COMMENT 'čas explicitního vypořádání (ne přes GET/download)',
  settled_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vat_coefficients_supplier_year (supplier_id, year),
  CONSTRAINT fk_vat_coefficients_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
