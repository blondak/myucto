-- MyÚčto.cz — dny okna náhrady mzdy (§ 192 ZP), které padly ještě u předchozího plátce.
--
-- Okno náhrady mzdy se dosud počítalo VÝHRADNĚ z `payroll_absences.date_from`
-- (AbsenceRuleset::sicknessWindowEnd), což platí jen pro neschopnost, která u nás
-- začala. Neschopnost převzatá z jiného mzdového programu do MyÚčta vstupuje až tou
-- částí, kterou vedeme — červenec zpracovala PAMICA, srpen my — a okno se jí tím
-- rozeběhlo znovu od nuly. Zaměstnanci by se tak vyplatila náhrada mzdy až za 28
-- kalendářních dnů místo zákonných 14.
--
-- Sloupec drží počet kalendářních dnů okna, které uplynuly PŘED `date_from`.
-- Výchozí 0 = případ začal tady, tedy dosavadní chování beze změny; vyplňuje ho
-- převod mezd z PAMICA (PohodaPayrollSicknessWriter) a ručně účetní u převzaté
-- neschopnosti. Čte ho výpočet okna, ne jen obrazovka:
--   * AbsenceRuleset::sicknessWindowEnd($from, $carried) zkrátí okno,
--   * PayrollAbsenceRepository::publishedShiftSegments(+BeyondSicknessWindow) tím ořízne
--     směny s náhradou a zbytek pošle do hodin za oknem (dávka ČSSZ),
--   * PayrollSicknessRepository::record() uloží stejně zkrácené `compensation_window_to`.
--
-- Platí jen pro druhy s oknem podle § 192 ZP (`dpn`, `quarantine`). U ošetřovného, PPM
-- a otcovské zaměstnavatel náhradu neposkytuje, takže tam zůstává nula.
--
-- SMALLINT UNSIGNED: horní mez okna hlídá ruleset a aplikace, databáze jen typ.
-- Aditivní, idempotentní (ADD COLUMN IF NOT EXISTS — nativní MariaDB).

SET NAMES utf8mb4;

ALTER TABLE payroll_absences
  ADD COLUMN IF NOT EXISTS sickness_window_carried_days SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'DPN/karanténa: kalendářních dnů okna náhrady mzdy (§ 192 ZP) vyčerpaných před date_from, tedy předchozím plátcem. 0 = případ začal v MyÚčtu.';
