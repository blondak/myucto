-- MyÚčto.cz — drobné mezery mzdové agendy (audit 26. 9. 2026).
--
-- ── Záloha na pracovní cestu se musí vypořádat ──────────────────────────────
-- Vyúčtování cesty (§ 183 zákoníku práce) je nárok MINUS poskytnutá záloha.
-- Aplikace zálohu jen ukládala a do mzdy poslala celý nárok, takže zaměstnanec
-- dostal zálohu dvakrát. `advance_settlement` říká, KDE se rozdíl vypořádá:
--   * `payroll` — doplatek (nebo odpočet zálohy) jde do nejbližší výplaty,
--   * `cash`    — nezdaněná část a záloha se vyrovnají pokladnou; do mzdy jde
--                 jen nadlimitní (zdanitelná) část.
-- Výchozí `payroll` odpovídá tomu, kudy cestovní náhrada tekla dosud.
--
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS; MODIFY na úplný výčet.

SET NAMES utf8mb4;

ALTER TABLE payroll_business_trips
  ADD COLUMN IF NOT EXISTS advance_settlement ENUM('payroll','cash') NOT NULL DEFAULT 'payroll'
      COMMENT 'Kde se vypořádá rozdíl nároku a zálohy (§ 183 ZP)'
      AFTER advance_minor;

-- ── Pracovní volno bez náhrady mzdy jako vlastní druhy nepřítomnosti ─────────
-- Výkon veřejné funkce (§ 200 až 202 ZP) a překážka na straně zaměstnance,
-- za kterou náhrada mzdy nepřísluší, neměly kam padnout. Zbývalo `other`, na
-- kterém se měsíční hlášení i ELDP fail-closed zastaví, nebo `employee_obstacle`,
-- který hlášení čte jako překážku S NÁHRADOU mzdy (10471) — to by byla nepravda.
-- Obě se vedou jako omluvená nepřítomnost bez náhrady příjmu (úhrn 10275,
-- v ELDP stejně jako neplacené volno). Hodnoty přibývají na konec výčtu,
-- existující řádky se nemění. MODIFY na úplný výčet je idempotentní.

ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','unexcused','other',
    'public_function','employee_obstacle_unpaid'
  ) NOT NULL;
