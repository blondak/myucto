-- 1369: MZ-19-W11 — splatnost mzdových odvodů posunutá na pracovní den
--
-- Materializéry odvodů skládaly splatnost jako holý den v měsíci: sociální
-- i zdravotní pojistné a záloha na daň 20. den následujícího měsíce, srážková
-- daň jeho poslední den. Posun podle lhůtního pravidla („připadne-li poslední
-- den lhůty na sobotu, neděli nebo svátek, je posledním dnem nejblíže
-- následující pracovní den") tam ale nebyl, takže zapsaná splatnost mohla
-- padnout na sobotu nebo na svátek — např. odvody za 05/2026 na sobotu
-- 20. 6. 2026 místo pondělí 22. 6. 2026.
--
-- Kód se opravuje v PayrollLevyDeadlinePolicy. Tahle migrace srovnává DŘÍVE
-- zapsané závazky, a to ze dvou důvodů:
--   1. uložená splatnost je podklad pro platbu a pro hlášení „po termínu";
--   2. materializér při opakovaném stisku „Připravit závazky" porovnává
--      existující řádek s nově spočítaným (assertReplay). Bez srovnání by
--      opakovaná příprava starší revize skončila hláškou o změněné splatnosti.
--
-- Rovná se jen to, co je prokazatelně špatně: řádek, jehož splatnost NENÍ
-- pracovní den. Splatnost, která na pracovní den padla, zůstává beze změny,
-- takže migrace nepřepisuje správně vzniklou historii. Hashe se netýká —
-- source_snapshot_hash ani idempotency_key_hash datum splatnosti neobsahují.
--
-- Kalendář počítají dočasné funkce, ne ručně vypsaný seznam dat: svátky podle
-- zákona o státních svátcích jsou pevné plus Velký pátek a Velikonoční pondělí
-- odvozené anonymním gregoriánským algoritmem (stejná derivace jako
-- MyInvoice\Service\Report\CzechWorkingDays::easterSunday). Funkce se na konci
-- zase zahodí, ve schématu po migraci nezůstane nic.
--
-- Re-run safe: DROP FUNCTION IF EXISTS před i po, UPDATE po prvním běhu
-- nenajde žádný řádek k posunu.

SET NAMES utf8mb4;

DELIMITER //

DROP FUNCTION IF EXISTS mig1369_easter_sunday //
DROP FUNCTION IF EXISTS mig1369_is_working_day //
DROP FUNCTION IF EXISTS mig1369_next_working_day //

CREATE FUNCTION mig1369_easter_sunday(p_year INT)
  RETURNS DATE
  DETERMINISTIC
  NO SQL
BEGIN
  DECLARE v_a INT; DECLARE v_b INT; DECLARE v_c INT; DECLARE v_d INT;
  DECLARE v_e INT; DECLARE v_f INT; DECLARE v_g INT; DECLARE v_h INT;
  DECLARE v_i INT; DECLARE v_k INT; DECLARE v_l INT; DECLARE v_m INT;
  DECLARE v_month INT; DECLARE v_day INT;

  SET v_a = p_year MOD 19;
  SET v_b = FLOOR(p_year / 100);
  SET v_c = p_year MOD 100;
  SET v_d = FLOOR(v_b / 4);
  SET v_e = v_b MOD 4;
  SET v_f = FLOOR((v_b + 8) / 25);
  SET v_g = FLOOR((v_b - v_f + 1) / 3);
  SET v_h = (19 * v_a + v_b - v_d - v_g + 15) MOD 30;
  SET v_i = FLOOR(v_c / 4);
  SET v_k = v_c MOD 4;
  SET v_l = (32 + 2 * v_e + 2 * v_i - v_h - v_k) MOD 7;
  SET v_m = FLOOR((v_a + 11 * v_h + 22 * v_l) / 451);
  SET v_month = FLOOR((v_h + v_l - 7 * v_m + 114) / 31);
  SET v_day = ((v_h + v_l - 7 * v_m + 114) MOD 31) + 1;

  RETURN MAKEDATE(p_year, 1)
       + INTERVAL (v_month - 1) MONTH
       + INTERVAL (v_day - 1) DAY;
END //

CREATE FUNCTION mig1369_is_working_day(p_date DATE)
  RETURNS TINYINT
  DETERMINISTIC
  CONTAINS SQL
BEGIN
  DECLARE v_easter DATE;

  -- DAYOFWEEK: 1 = neděle, 7 = sobota.
  IF DAYOFWEEK(p_date) IN (1, 7) THEN
    RETURN 0;
  END IF;
  IF DATE_FORMAT(p_date, '%m-%d') IN (
    '01-01', '05-01', '05-08', '07-05', '07-06',
    '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'
  ) THEN
    RETURN 0;
  END IF;

  SET v_easter = mig1369_easter_sunday(YEAR(p_date));
  IF p_date = v_easter - INTERVAL 2 DAY OR p_date = v_easter + INTERVAL 1 DAY THEN
    RETURN 0;
  END IF;

  RETURN 1;
END //

CREATE FUNCTION mig1369_next_working_day(p_date DATE)
  RETURNS DATE
  DETERMINISTIC
  CONTAINS SQL
BEGIN
  DECLARE v_date DATE DEFAULT p_date;

  WHILE mig1369_is_working_day(v_date) = 0 DO
    SET v_date = v_date + INTERVAL 1 DAY;
  END WHILE;

  RETURN v_date;
END //

DELIMITER ;

-- Jen zákonné odvody. net_wage, deduction, enforcement, insolvency a benefit
-- mají splatnost z výplatního termínu běhu, ne ze zákonné lhůty, a posouvat
-- se nesmějí.
UPDATE payroll_payment_liabilities
   SET due_on = mig1369_next_working_day(due_on)
 WHERE liability_kind IN (
         'social_insurance', 'health_insurance',
         'advance_tax', 'withholding_tax'
       )
   AND mig1369_is_working_day(due_on) = 0;

DROP FUNCTION IF EXISTS mig1369_next_working_day;
DROP FUNCTION IF EXISTS mig1369_is_working_day;
DROP FUNCTION IF EXISTS mig1369_easter_sunday;
