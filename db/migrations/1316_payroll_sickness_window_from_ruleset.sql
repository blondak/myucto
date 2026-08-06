-- MyÚčto.cz — MZ-07-W07: délka okna náhrady mzdy při DPN je parametr rulesetu.
--
-- CHECK `DATEDIFF(...) <= 13` byl druhou kopií zákonného čísla ze § 192 ZP
-- (14 dnů). To číslo se historicky měnilo (21 → 14) a od teď žije v rulesetu
-- (`wage_compensation.window_calendar_days`), takže ruleset s jinou délkou by
-- narazil na SQL CHECK a spadl na nesrozumitelné chybě.
--
-- CHECK se proto neruší, jen přestává být duplikátem zákonné délky: zůstává
-- jako HRUBÁ POJISTKA proti rozjetému oknu (nesmyslné datum, chyba v kódu).
-- Horní mez 92 dnů = jedno kalendářní čtvrtletí, což je nejdelší absence,
-- jakou vůbec lze založit (validátor dělí absenci s náhradou po čtvrtletích).
-- Zákonnou délku vynucuje ruleset, ne databáze.

SET NAMES utf8mb4;

ALTER TABLE payroll_sickness_events
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_window;

ALTER TABLE payroll_sickness_events
  ADD CONSTRAINT chk_payroll_sickness_window CHECK (
    compensation_window_to >= compensation_window_from
    AND DATEDIFF(compensation_window_to, compensation_window_from) <= 92
  );
