-- MyÚčto.cz — náhrada mzdy při DPN: řetěz částí, DPN bez nároku a snížení náhrady
-- (audit podání mezd 2026-10, nálezy DPN-01 až DPN-04).
--
-- Výpočet náhrady (`payroll_sickness_events`) teď vzniká i tam, kde náhrada nepřísluší
-- nebo vychází nulová, protože z něj čte okno § 192 ZP evidenční list, měsíční hlášení
-- i krácení mzdy:
--   * navazující část neschopnosti, jejíž okno vyčerpaly předchozí části (DPN-01);
--   * neschopnost zaměstnance bez nároku na nemocenské (§ 15a zák. č. 187/2006 Sb.,
--     DPN-02) — `insurance_eligibility_confirmed = 0`;
--   * neschopnost, jejíž okno leží celé mimo trvání pracovního vztahu (DPN-04).
-- Takový výpočet nemá průměrný výdělek, proto je `average_snapshot_id` nově NULL,
-- a jen u nulové náhrady.
--
-- Prázdné okno se ukládá jako `compensation_window_to = compensation_window_from - 1`:
-- první den, od kterého by okno běželo, zůstává doložený a evidenční list z něj pozná,
-- že všechny dny neschopnosti leží za oknem.
--
-- Snížení náhrady (DPN-03): § 192 odst. 4 ZP (případy § 31 zák. č. 187/2006 Sb.)
-- snižuje náhradu na polovinu povinně, § 192 odst. 5 ZP (porušení režimu) dovoluje
-- snížit podílem nebo částkou až na nulu. Důvod je povinný u obou.
SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_events
  MODIFY COLUMN average_snapshot_id BIGINT UNSIGNED NULL;

ALTER TABLE payroll_sickness_events
  ADD COLUMN IF NOT EXISTS compensation_reduction ENUM('none','half_192_4','reduced_192_5') NOT NULL DEFAULT 'none'
    COMMENT 'snížení náhrady: § 192 odst. 4 ZP (polovina), § 192 odst. 5 ZP (porušení režimu)' AFTER compensation_minor,
  ADD COLUMN IF NOT EXISTS compensation_reduction_basis_points SMALLINT UNSIGNED NULL
    COMMENT 'snížený podíl náhrady v bazických bodech (5000 = o polovinu)' AFTER compensation_reduction,
  ADD COLUMN IF NOT EXISTS compensation_reduction_minor BIGINT UNSIGNED NULL
    COMMENT 'snížení pevnou částkou v haléřích (jen § 192 odst. 5 ZP)' AFTER compensation_reduction_basis_points,
  ADD COLUMN IF NOT EXISTS compensation_reduction_reason VARCHAR(500) NULL
    COMMENT 'důvod snížení' AFTER compensation_reduction_minor;

ALTER TABLE payroll_sickness_events
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_window;

ALTER TABLE payroll_sickness_events
  ADD CONSTRAINT chk_payroll_sickness_window CHECK (
    compensation_window_to >= DATE_SUB(compensation_window_from, INTERVAL 1 DAY)
    AND DATEDIFF(compensation_window_to, compensation_window_from) <= 92
  );

ALTER TABLE payroll_sickness_events
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_average;

ALTER TABLE payroll_sickness_events
  ADD CONSTRAINT chk_payroll_sickness_average CHECK (
    average_snapshot_id IS NOT NULL OR compensation_minor = 0
  );

ALTER TABLE payroll_sickness_events
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_reduction;

ALTER TABLE payroll_sickness_events
  ADD CONSTRAINT chk_payroll_sickness_reduction CHECK (
    (
      compensation_reduction = 'none'
      AND compensation_reduction_basis_points IS NULL
      AND compensation_reduction_minor IS NULL
      AND compensation_reduction_reason IS NULL
    )
    OR (
      compensation_reduction = 'half_192_4'
      AND compensation_reduction_basis_points = 5000
      AND compensation_reduction_minor IS NULL
      AND compensation_reduction_reason IS NOT NULL
    )
    OR (
      compensation_reduction = 'reduced_192_5'
      AND compensation_reduction_reason IS NOT NULL
      AND (
        (compensation_reduction_basis_points BETWEEN 1 AND 10000 AND compensation_reduction_minor IS NULL)
        OR (compensation_reduction_basis_points IS NULL AND compensation_reduction_minor > 0)
      )
    )
  );
