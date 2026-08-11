-- MyÚčto.cz — stav předplatného licence (automatické prodlužování).
--
-- PROBLÉM: aplikace nevěděla nic o předplatném za licencí — jestli se automaticky
-- prodlužuje a kdy je další stržení. Zákazník tak neměl v aplikaci co zobrazit ani
-- co zrušit; samoobslužná cesta vedla jen přes podepsaný odkaz z e-mailu.
--
-- ŘEŠENÍ: licenční server vrací v odpovědi na `activate` / `renew` / `cancel-renewal`
-- aditivní pole `subscription` ({state, period, auto_renew, next_charge_at,
-- cancelled_at, valid_until}). Ukládá se sem tak, jak přišlo — je to cache poslední
-- odpovědi serveru, ne vlastní evidence: autoritou zůstává licenční server.
--
-- Sloupec je NULLable a starší instalace (i doživotní licence bez předplatného) ho
-- nechají prázdný — UI pak sekci prodlužování prostě nezobrazí.

SET NAMES utf8mb4;

ALTER TABLE license
  ADD COLUMN IF NOT EXISTS subscription_info JSON NULL AFTER token_payload;
