-- 1299: Referenční kurzy ECB pro přepočet do měny OSS podání
--
-- ── Proč nová tabulka, a ne exchange_rates ──────────────────────────────────────────
-- `exchange_rates` (migrace 0004) drží DENNÍ kurzy ČNB v orientaci „kolik CZK za 1 jednotku
-- měny". Pro OSS podání se ale podle sdělení Finanční správy k režimu EU použije SMĚNNÝ
-- KURZ ECB zveřejněný pro POSLEDNÍ DEN ZDAŇOVACÍHO OBDOBÍ (kvartálu), a není-li pro ten den
-- zveřejněn, pro nejbližší následující den. Je to tedy jiný vydavatel, jiná perioda i jiná
-- orientace kurzu: ECB publikuje „kolik jednotek měny za 1 EUR" (CZK 24,195 = za 1 EUR).
--
-- Uložit obojí do jedné tabulky by znamenalo dva různé významy sloupce `rate` odlišené jen
-- konvencí — a přesně tenhle druh tiché záměny je to, co u přepočtu podání dělá chybu
-- v částkách, které zákazník odešle správci daně. Proto vlastní tabulka s vlastním
-- pojmenováním (`units_per_eur`), aby nešlo použít jednu hodnotu místo druhé omylem.
--
-- ── Proč druhá tabulka jen s dny ────────────────────────────────────────────────────
-- Pravidlo „není-li kurz zveřejněn, vezmi nejbližší NÁSLEDUJÍCÍ den" vyžaduje umět odlišit
-- „ECB ten den nezveřejnila" (víkend, svátek TARGET) od „ten den nemáme staženo". Bez toho
-- rozlišení by cache s dírou vypadala stejně jako víkend a přepočet by tiše přeskočil na
-- pozdější den, než jaký zákon určuje. `ecb_exchange_rate_days` proto nese příznak
-- `published` pro každý den, o kterém už feed ECB odpověděl — včetně dnů se `published = 0`.
-- Den, který v téhle tabulce NENÍ, je „nevíme" a vyvolá stažení feedu.
--
-- Do budoucna se nic nemarkuje: pokrytí se odvozuje z rozsahu dat, které feed sám obsahuje,
-- takže dnešek před 16:00 SEČ (kdy ECB publikuje) zůstane „nevíme" a nezafixuje se jako
-- nezveřejněný.
--
-- Idempotence: obojí `CREATE TABLE IF NOT EXISTS`, žádný seed — cache se plní za běhu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ecb_exchange_rates (
  rate_date     DATE NOT NULL,
  currency_code CHAR(3) NOT NULL,
  -- Orientace ECB: kolik JEDNOTEK MĚNY za 1 EUR (CZK 24.195000 = 24,195 Kč za 1 €).
  -- Opačná orientace než `exchange_rates.rate` (ČNB, CZK za 1 jednotku) — schválně jiný
  -- název sloupce, ať se hodnoty nedají zaměnit.
  units_per_eur DECIMAL(20,10) NOT NULL,
  fetched_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_date, currency_code),
  KEY idx_ecb_rates_currency (currency_code, rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecb_exchange_rate_days (
  rate_date  DATE NOT NULL,
  -- 1 = ECB pro tenhle den kurzy zveřejnila, 0 = nezveřejnila (víkend / svátek TARGET).
  -- Chybějící řádek = nevíme, je potřeba se zeptat feedu.
  published  TINYINT(1) NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
