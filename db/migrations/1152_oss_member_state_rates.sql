-- 1152: Číselník sazeb DPH členských států pro OSS (§ 110 a násl. ZDPH)
--
-- Systém žádný číselník neměl: sazbu pro zemi spotřeby si uživatel musel ručně založit
-- v obecné tabulce `vat_rates` (kam se seedují jen čtyři české sazby) a jediná existující
-- kontrola byla vnitřní konzistence `základ × sazba ≈ daň`. Konzistentně ŠPATNÁ sazba —
-- třeba německých 19 % použitých na rakouské plnění — tedy prošla bez jediného varování
-- a odvedla se nesprávná daň do státu spotřeby.
--
-- ── Proč s platností od/do ──────────────────────────────────────────────────────────
-- Sazby členských států se mění a podání se běžně opravuje zpětně, takže číselník musí
-- umět odpovědět „jaká sazba platila k datu plnění", ne jen „jaká platí dnes". Bez
-- `valid_from`/`valid_to` by oprava staršího období dostala dnešní sazbu a hlásila
-- neexistující chybu.
--
-- ── Číselník je vodítko, ne autorita ────────────────────────────────────────────────
-- Seed je platný ke dni migrace a nevyhnutelně zestárne. Kontrola proti němu proto
-- VARUJE, nikdy neblokuje — tvrdé odmítnutí by po první změně sazby v kterémkoli státě
-- znemožnilo vystavit legitimní doklad, což je horší než dnešní stav. Uživatel může
-- sazbu doplnit nebo opravit (`is_custom = 1`), aniž by čekal na release.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `oss_member_state_rates` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `country`     CHAR(2) NOT NULL COMMENT 'ISO2 státu spotřeby',
    `rate_type`   ENUM('standard','reduced','second_reduced','parking') NOT NULL,
    `rate_percent` DECIMAL(5,2) NOT NULL,
    `valid_from`  DATE NOT NULL,
    `valid_to`    DATE NULL COMMENT 'NULL = platí dosud',
    `is_custom`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = doplnil uživatel, seed nepřepíše',
    `note`        VARCHAR(190) NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_osmr_lookup` (`country`, `rate_type`, `valid_from`),
    UNIQUE KEY `uq_osmr` (`country`, `rate_type`, `rate_percent`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed základních a snížených sazeb států EU. Stav k 1. 1. 2026; `valid_from` je záměrně
-- 2021-07-01 (spuštění OSS), protože jde o sazby, které v mezidobí platily beze změny
-- u naprosté většiny států — případné odchylky se doplní jako další řádek s pozdějším
-- `valid_from`, ne přepisem tohoto.
INSERT INTO `oss_member_state_rates` (`country`, `rate_type`, `rate_percent`, `valid_from`)
SELECT * FROM (
    SELECT 'AT' AS c, 'standard' AS t, 20.00 AS r, '2021-07-01' AS f UNION ALL
    SELECT 'AT', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'BE', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'BE', 'reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'BG', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'BG', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'CY', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'CY', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'CZ', 'standard', 21.00, '2024-01-01' UNION ALL
    SELECT 'CZ', 'reduced', 12.00, '2024-01-01' UNION ALL
    SELECT 'DE', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'DE', 'reduced', 7.00, '2021-07-01' UNION ALL
    SELECT 'DK', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'EE', 'standard', 24.00, '2025-07-01' UNION ALL
    SELECT 'EE', 'standard', 22.00, '2024-01-01' UNION ALL
    SELECT 'EE', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'ES', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'ES', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'FI', 'standard', 25.50, '2024-09-01' UNION ALL
    SELECT 'FI', 'reduced', 14.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'second_reduced', 5.50, '2021-07-01' UNION ALL
    SELECT 'GR', 'standard', 24.00, '2021-07-01' UNION ALL
    SELECT 'GR', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'HR', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'HR', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'HU', 'standard', 27.00, '2021-07-01' UNION ALL
    SELECT 'HU', 'reduced', 18.00, '2021-07-01' UNION ALL
    SELECT 'IE', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'IE', 'reduced', 13.50, '2021-07-01' UNION ALL
    SELECT 'IT', 'standard', 22.00, '2021-07-01' UNION ALL
    SELECT 'IT', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'IT', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'LT', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'LT', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'LU', 'standard', 17.00, '2021-07-01' UNION ALL
    SELECT 'LU', 'reduced', 8.00, '2021-07-01' UNION ALL
    SELECT 'LV', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'LV', 'reduced', 12.00, '2021-07-01' UNION ALL
    SELECT 'MT', 'standard', 18.00, '2021-07-01' UNION ALL
    SELECT 'MT', 'reduced', 7.00, '2021-07-01' UNION ALL
    SELECT 'NL', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'NL', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'reduced', 8.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'second_reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'RO', 'standard', 21.00, '2025-08-01' UNION ALL
    SELECT 'RO', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'RO', 'reduced', 11.00, '2025-08-01' UNION ALL
    SELECT 'RO', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'SE', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'SE', 'reduced', 12.00, '2021-07-01' UNION ALL
    SELECT 'SI', 'standard', 22.00, '2021-07-01' UNION ALL
    SELECT 'SI', 'reduced', 9.50, '2021-07-01' UNION ALL
    SELECT 'SK', 'standard', 23.00, '2025-01-01' UNION ALL
    SELECT 'SK', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'SK', 'reduced', 19.00, '2025-01-01' UNION ALL
    SELECT 'SK', 'reduced', 10.00, '2021-07-01'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `oss_member_state_rates` x
     WHERE x.country = seed.c AND x.rate_type = seed.t
       AND x.rate_percent = seed.r AND x.valid_from = seed.f
);

-- Uzavření platnosti u států, které sazbu v mezidobí změnily — jinak by k datu plnění
-- platily obě a kontrola by tiše přijala i tu starou.
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-06-30'
 WHERE `country` = 'EE' AND `rate_type` = 'standard' AND `rate_percent` = 22.00 AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-07-31'
 WHERE `country` = 'RO' AND `rate_type` IN ('standard','reduced') AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2024-12-31'
 WHERE `country` = 'SK' AND `rate_type` IN ('standard','reduced') AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
