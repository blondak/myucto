-- MyÚčto.cz — pracovní cesta nese časovou zónu stejně jako směna a docházka.
--
-- Stav před migrací: `payroll_business_trips.departure_at` / `arrival_at` držely
-- HOLÝ MÍSTNÍ čas tak, jak ho uživatel napsal do formuláře (`datetime-local`),
-- bez jakékoli zóny. Vyloučení směny s nárokem na stravné podle § 6 odst. 9
-- písm. b) ZDP ale porovnává tenhle interval se směnami, které jsou uložené jako
-- pravý UTC instant (`payroll_shifts.starts_at_utc` + `timezone_name`). Cesta se
-- proto do směn trefovala o 1 hodinu (SEČ) až 2 hodiny (SELČ) vedle.
--
-- Po migraci platí vzor směn: sloupce `departure_at_utc` / `arrival_at_utc` jsou
-- pravý UTC instant a `timezone_name` je IANA zóna, ve které byl čas zadán.
--
-- PŘEDPOKLAD PŘEVODU DAT: uložené hodnoty jsou místní časy zóny **Europe/Prague**.
-- Modul mezd je český, formulář cest zónu nikdy nenabízel a jiná zóna se do dat
-- nemohla dostat jinak než ručním INSERTem. Pro data, u kterých předpoklad
-- neplatí (cesta zapsaná někým v jiné zóně přímo do DB), se posun spočítá podle
-- Prahy — hodnota bude o rozdíl obou zón vedle a musí se opravit ručně; poznat
-- se to dá podle `timezone_name = 'Europe/Prague'` u cesty, která do Prahy
-- nepatří. Instalace bez jediné cesty (běžný stav) převádí nula řádků.
--
-- PŘECHOD LETNÍHO ČASU: převod kopíruje chování PHP `DateTimeZone`, aby migrace
-- a aplikace říkaly totéž.
--   * NEEXISTUJÍCÍ místní čas (poslední neděle v březnu 02:00–02:59) se bere
--     ještě zimním posunem (−1 h), tedy 02:30 → 01:30 UTC = 03:30 SELČ.
--   * DVOJZNAČNÝ místní čas (poslední neděle v říjnu 02:00–02:59) se bere už
--     zimním posunem (−1 h), tedy druhým výskytem hodiny.
-- Pravidlo přechodu (poslední neděle v březnu 03:00 místního / poslední neděle
-- v říjnu 02:00 místního) platí v EU od roku 1996; starší data mzdový modul mít
-- nemůže, vznikl mnohem později.
--
-- Idempotence: přejmenování je `CHANGE COLUMN IF EXISTS`, převod dat je vázaný
-- na `timezone_name IS NULL` (nový sloupec bez hodnoty = řádek ještě nepřeveden),
-- takže druhý běh nic neposune podruhé.

SET NAMES utf8mb4;

-- CHECK nad starými názvy by přejmenování zablokoval.
ALTER TABLE payroll_business_trips
  DROP CONSTRAINT IF EXISTS chk_payroll_business_trip_interval;

ALTER TABLE payroll_business_trips
  CHANGE COLUMN IF EXISTS departure_at departure_at_utc DATETIME NOT NULL;

ALTER TABLE payroll_business_trips
  CHANGE COLUMN IF EXISTS arrival_at arrival_at_utc DATETIME NOT NULL;

-- Zatím NULL: prázdná hodnota je značka „řádek ještě není převedený".
ALTER TABLE payroll_business_trips
  ADD COLUMN IF NOT EXISTS timezone_name VARCHAR(64) NULL AFTER country_code;

UPDATE payroll_business_trips
   SET departure_at_utc = CASE
         WHEN departure_at_utc
                >= CONCAT(YEAR(departure_at_utc), '-03-31 03:00:00')
                   - INTERVAL ((WEEKDAY(CONCAT(YEAR(departure_at_utc), '-03-31')) + 1) % 7) DAY
          AND departure_at_utc
                < CONCAT(YEAR(departure_at_utc), '-10-31 02:00:00')
                   - INTERVAL ((WEEKDAY(CONCAT(YEAR(departure_at_utc), '-10-31')) + 1) % 7) DAY
         THEN departure_at_utc - INTERVAL 2 HOUR
         ELSE departure_at_utc - INTERVAL 1 HOUR
       END,
       arrival_at_utc = CASE
         WHEN arrival_at_utc
                >= CONCAT(YEAR(arrival_at_utc), '-03-31 03:00:00')
                   - INTERVAL ((WEEKDAY(CONCAT(YEAR(arrival_at_utc), '-03-31')) + 1) % 7) DAY
          AND arrival_at_utc
                < CONCAT(YEAR(arrival_at_utc), '-10-31 02:00:00')
                   - INTERVAL ((WEEKDAY(CONCAT(YEAR(arrival_at_utc), '-10-31')) + 1) % 7) DAY
         THEN arrival_at_utc - INTERVAL 2 HOUR
         ELSE arrival_at_utc - INTERVAL 1 HOUR
       END,
       timezone_name = 'Europe/Prague'
 WHERE timezone_name IS NULL;

ALTER TABLE payroll_business_trips
  MODIFY COLUMN timezone_name VARCHAR(64) NOT NULL DEFAULT 'Europe/Prague';

ALTER TABLE payroll_business_trips
  ADD CONSTRAINT chk_payroll_business_trip_interval
    CHECK (arrival_at_utc > departure_at_utc);
