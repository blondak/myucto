-- Převzaté mzdy: vyloučené dny podle § 18 odst. 7 zák. č. 187/2006 Sb.
-- a příjem z nepojištěné činnosti (JMHZ 10476) u převzatého měsíce.
--
-- PROČ: rozhodné období v NEMPRI se u měsíců před vedením mezd v MyÚčtu skládá
-- z převzatých mezd. Sloupec `excluded_days` (migrace 1851) ale drží vyloučené
-- DOBY § 16 odst. 4 zák. č. 155/1995 Sb. (JMHZ 10357), tedy veličinu pro
-- důchodové pojištění. Rozhodné období nemocenských dávek dělí vyměřovací
-- základ počtem dnů snížených o vyloučené DNY § 18 odst. 7 (JMHZ 10366 =
-- 10473 + 10474 + 10475) — neplacené volno a dny s náhradou mzdy do 10357
-- nepatří. Měsíc celý v neplaceném volnu měl proto v NEMPRI 0 vyloučených dnů
-- a denní vyměřovací základ vyšel nižší.
--
-- `sickness_excluded_days` NULL = původní systém údaj nevydal (PAMICA,
-- POHODA, PREMIER, tabulkový import, hlášení bez 10366 i bez rozpadu). NEMPRI
-- pak měsíc s nulovým příjmem nepoužije a vyžádá ruční doplnění; u měsíce
-- s příjmem zůstává vyloučenou dobou `excluded_days`, jako dosud.
--
-- `uninsured_income_minor` je JMHZ 10476 (vykázaný příjem včetně nepojištěné
-- činnosti). § 19 odst. 9 zák. č. 187/2006 Sb. započítává do rozhodného období
-- i příjem z dohody o provedení práce a zaměstnání malého rozsahu v měsících,
-- kdy zaměstnanec pojištěn nebyl; `social_base_minor` (10477) je v nich nula.
-- NULL = zdroj údaj nenese.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS; CHECK MariaDB s IF NOT EXISTS neumí,
-- proto se nejdřív zahodí a přidá znovu.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  ADD COLUMN IF NOT EXISTS sickness_excluded_days SMALLINT UNSIGNED NULL
    COMMENT 'vyloučené dny § 18 odst. 7 z. č. 187/2006 Sb. (JMHZ 10366, jinak 10473+10474+10475); NULL = zdroj nevydal'
    AFTER excluded_days,
  ADD COLUMN IF NOT EXISTS uninsured_income_minor BIGINT NULL
    COMMENT 'příjem včetně nepojištěné činnosti v haléřích (JMHZ 10476); NULL = zdroj nevydal'
    AFTER social_base_minor;

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_sickness_excluded_days;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_sickness_excluded_days CHECK (
    sickness_excluded_days IS NULL OR sickness_excluded_days <= 31
  );

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_uninsured_income;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_uninsured_income CHECK (
    uninsured_income_minor IS NULL OR uninsured_income_minor >= 0
  );
