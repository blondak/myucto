-- MyÚčto.cz, Kniha jízd: odhadnutý stav tachometru u tankování.
--
--   fuelings.odometer_is_estimate  1 = stav tachometru doplnila aplikace odhadem
--                                  (z knihy jízd nebo ze sousedních známých stavů),
--                                  0 = zadaný / vytěžený skutečný stav.
--
-- Skutečný stav z dokladu nebo ruční úpravy odhad přepíše a příznak shodí.
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE fuelings
  ADD COLUMN IF NOT EXISTS odometer_is_estimate TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Stav tachometru je odhad aplikace' AFTER odometer;
