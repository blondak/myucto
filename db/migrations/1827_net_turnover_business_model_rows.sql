-- 1827: výnosy obchodního modelu v čistém obratu podle úsudku firmy
--
-- Čistý obrat jsou od 1. 1. 2024 výnosy z prodeje výrobků, zboží a služeb (§ 1a odst. 2
-- zákona o účetnictví, § 35 vyhl. č. 500/2002 Sb.), ve výkazu výchozí řádky I. + II.
-- Vyhláška ale nechává na úsudku účetní jednotky, které další výnosy patří k jejímu
-- obchodnímu modelu (pronajímatel s tržbami z prodeje majetku, holding s výnosy z podílů,
-- finanční činnost s úroky). Firma si proto vybere řádky VZZ, které se k I. + II. přičtou.
--
-- JSON {"income_statement": ["III.1.", …], "income_statement_purpose": ["II.", …]};
-- NULL = jen výchozí I. + II. (účelové členění I.). Platí jen pro období od 1. 1. 2024.

SET NAMES utf8mb4;

ALTER TABLE accounting_supplier_settings
    ADD COLUMN IF NOT EXISTS net_turnover_extra_rows TEXT NULL
        COMMENT 'JSON: řádky VZZ přičítané k čistému obratu (§ 35 vyhl. 500/2002), podle typu výkazu';
