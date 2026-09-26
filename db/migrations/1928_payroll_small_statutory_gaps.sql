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
-- IDEMPOTENCE: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE payroll_business_trips
  ADD COLUMN IF NOT EXISTS advance_settlement ENUM('payroll','cash') NOT NULL DEFAULT 'payroll'
      COMMENT 'Kde se vypořádá rozdíl nároku a zálohy (§ 183 ZP)'
      AFTER advance_minor;
