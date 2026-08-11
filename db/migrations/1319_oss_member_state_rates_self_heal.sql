-- 1319: Sebeopravný dotah číselníku sazeb členských států (OSS)
--
-- ── Hlášení zákazníka ────────────────────────────────────────────────────────────────
-- Čerstvá instalace 5.3.0 → 5.3.1 → 5.6.0 doběhla se VŠEMI migracemi (`migrate.php`
-- hlásí „žádné nové migrace k aplikaci"), ale `oss_member_state_rates` má jen 23 řádků —
-- přesně součet toho, co vkládají migrace 1292 (15 druhých snížených sazeb) a 1294
-- (8 historických řádků ČR/SR). Chybí celý seed migrace 1152: aktuální ČR 21 % od
-- 2024-01-01, SR 23 % od 2025-01-01, maďarská základní 27 % i Polsko úplně celé.
--
-- Na čistém běhu (`myucto_test`, všechny migrace od nuly) je tabulka úplná — 85 řádků,
-- CZ/SK/HU/PL mají platnou aktuální sazbu. Obsah migrace 1152 tedy není chybný ani
-- neúplný; něco na konkrétní instanci způsobilo, že se INSERT z 1152 nezapsal, přestože
-- se soubor zapsal do bookkeeping tabulky `migrations` jako hotový (jinak by ho
-- `migrate.php` nabízel dál jako pending). `api/bin/migrate.php` sám dokumentuje známé
-- riziko souběhu dvou migrátorů (viz komentář u `INSERT IGNORE INTO migrations`) —
-- typicky `docker-entrypoint.sh` a souběžně spuštěný `docker compose exec ... migrate.php`
-- při přechodu mezi verzemi. Přesnou historickou příčinu na už běžící instanci zpětně
-- nedokážeme prokázat; tahle migrace proto neopravuje PŘÍČINU, ale NÁSLEDEK — a dělá to
-- tak, aby oprava byla bezpečná ať byla příčina jakákoli.
--
-- ── Co migrace dělá ─────────────────────────────────────────────────────────────────
-- Znovu vloží PŘESNĚ tutéž množinu řádků, jakou by měly zapsat migrace 1152 a 1292
-- (identická data, stejné pořadí, včetně UPDATE, které uzavírají platnost nahrazených
-- sazeb). Na zdravé instanci je no-op — `WHERE NOT EXISTS` po klíči
-- (country, rate_type, rate_percent, valid_from) = `uq_osmr` nenajde nic k vložení.
-- Na instanci s dírou (jakoukoli, ne jen tou z hlášení) díru dotáhne.
--
-- ── Proč je to bezpečné vůči uživatelským zásahům (odpověď na dotaz zákazníka) ───────
-- Vlastní řádky (`is_custom = 1`, migrace 1296) mají identitu VŽDY jinou než seed —
-- `uq_osmr` na tabulce garantuje, že (country, rate_type, rate_percent, valid_from)
-- nemůže současně patřit vlastnímu i seedovanému řádku (viz `OssRateCodebook::createCustom()`,
-- které seedovanou identitu odmítne unikátním klíčem). Ruční doplnění „stejná data jako
-- seed" bude tedy PO téhle migraci buď (a) nadbytečné — pokud uživatel založil vlastní
-- řádek se STEJNOU čtveřicí, `WHERE NOT EXISTS` uvidí existující (jeho) řádek a seed
-- nevloží nic navíc, takže vznikne jen jeden řádek, ten uživatelův; nebo (b) neškodné —
-- pokud se čtveřice byť jen v setinách procenta liší, budou v číselníku ležet vedle sebe
-- oba, přesně jak popisuje komentář migrace 1296. V žádném případě nemůže dojít k tomu,
-- že by seed PŘEPSAL uživatelův řádek: INSERT se seedovanou identitou nikdy nesahá na
-- existující řádek, jen ověřuje, jestli už tam něco se stejným klíčem je.
--
-- Idempotence: shodná s 1152/1292/1294 — `INSERT ... WHERE NOT EXISTS` nad `uq_osmr`.

SET NAMES utf8mb4;

INSERT INTO `oss_member_state_rates` (`country`, `rate_type`, `rate_percent`, `valid_from`)
SELECT * FROM (
    SELECT 'AT' AS c, 'standard' AS t, 20.00 AS r, '2021-07-01' AS f UNION ALL
    SELECT 'AT', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'BE', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'BE', 'reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'BG', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'BG', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'CY', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'CY', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'CZ', 'standard', 21.00, '2024-01-01' UNION ALL
    SELECT 'CZ', 'reduced', 12.00, '2024-01-01' UNION ALL
    SELECT 'DE', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'DE', 'reduced', 7.00, '2021-07-01' UNION ALL
    SELECT 'DK', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'EE', 'standard', 24.00, '2025-07-01' UNION ALL
    SELECT 'EE', 'standard', 22.00, '2024-01-01' UNION ALL
    SELECT 'EE', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'ES', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'ES', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'FI', 'standard', 25.50, '2024-09-01' UNION ALL
    SELECT 'FI', 'reduced', 14.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'FR', 'second_reduced', 5.50, '2021-07-01' UNION ALL
    SELECT 'GR', 'standard', 24.00, '2021-07-01' UNION ALL
    SELECT 'GR', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'HR', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'HR', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'HU', 'standard', 27.00, '2021-07-01' UNION ALL
    SELECT 'HU', 'reduced', 18.00, '2021-07-01' UNION ALL
    SELECT 'IE', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'IE', 'reduced', 13.50, '2021-07-01' UNION ALL
    SELECT 'IT', 'standard', 22.00, '2021-07-01' UNION ALL
    SELECT 'IT', 'reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'IT', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'LT', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'LT', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'LU', 'standard', 17.00, '2021-07-01' UNION ALL
    SELECT 'LU', 'reduced', 8.00, '2021-07-01' UNION ALL
    SELECT 'LV', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'LV', 'reduced', 12.00, '2021-07-01' UNION ALL
    SELECT 'MT', 'standard', 18.00, '2021-07-01' UNION ALL
    SELECT 'MT', 'reduced', 7.00, '2021-07-01' UNION ALL
    SELECT 'NL', 'standard', 21.00, '2021-07-01' UNION ALL
    SELECT 'NL', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'reduced', 8.00, '2021-07-01' UNION ALL
    SELECT 'PL', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'standard', 23.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'PT', 'second_reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'RO', 'standard', 21.00, '2025-08-01' UNION ALL
    SELECT 'RO', 'standard', 19.00, '2021-07-01' UNION ALL
    SELECT 'RO', 'reduced', 11.00, '2025-08-01' UNION ALL
    SELECT 'RO', 'reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'SE', 'standard', 25.00, '2021-07-01' UNION ALL
    SELECT 'SE', 'reduced', 12.00, '2021-07-01' UNION ALL
    SELECT 'SI', 'standard', 22.00, '2021-07-01' UNION ALL
    SELECT 'SI', 'reduced', 9.50, '2021-07-01' UNION ALL
    SELECT 'SK', 'standard', 23.00, '2025-01-01' UNION ALL
    SELECT 'SK', 'standard', 20.00, '2021-07-01' UNION ALL
    SELECT 'SK', 'reduced', 19.00, '2025-01-01' UNION ALL
    SELECT 'SK', 'reduced', 10.00, '2021-07-01' UNION ALL
    -- Druhé snížené sazby doplněné migrací 1292 (viz její komentář pro zdroj/vysvětlení
    -- jednotlivých procent) — patří do stejného dotahu, ze stejného důvodu chybí.
    SELECT 'AT', 'second_reduced', 13.00, '2021-07-01' UNION ALL
    SELECT 'BE', 'second_reduced', 12.00, '2021-07-01' UNION ALL
    SELECT 'CY', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'FI', 'second_reduced', 10.00, '2021-07-01' UNION ALL
    SELECT 'GR', 'second_reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'HR', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'HU', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'IE', 'second_reduced', 9.00, '2021-07-01' UNION ALL
    SELECT 'LT', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'LV', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'MT', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'RO', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'SE', 'second_reduced', 6.00, '2021-07-01' UNION ALL
    SELECT 'SI', 'second_reduced', 5.00, '2021-07-01' UNION ALL
    SELECT 'SK', 'second_reduced', 5.00, '2025-01-01' UNION ALL
    -- Historické řádky migrace 1294 (země DODAVATELE do roku 2024/2021) — pro úplnost
    -- dotahu, kdyby chyběly i ty (u zákazníka nechyběly, ale díra jinde by chybět mohla).
    SELECT 'CZ', 'standard', 21.00, '2013-01-01' UNION ALL
    SELECT 'CZ', 'reduced', 15.00, '2013-01-01' UNION ALL
    SELECT 'CZ', 'second_reduced', 10.00, '2015-01-01' UNION ALL
    SELECT 'CZ', 'standard', 20.00, '2010-01-01' UNION ALL
    SELECT 'CZ', 'reduced', 14.00, '2012-01-01' UNION ALL
    SELECT 'CZ', 'reduced', 10.00, '2010-01-01' UNION ALL
    SELECT 'SK', 'standard', 20.00, '2011-01-01' UNION ALL
    SELECT 'SK', 'reduced', 10.00, '2011-01-01'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `oss_member_state_rates` x
     WHERE x.country = seed.c AND x.rate_type = seed.t
       AND x.rate_percent = seed.r AND x.valid_from = seed.f
);

-- Stejné uzavření platnosti jako 1152/1292 — jen pro řádky, které tahle migrace sama
-- právě vložila (`valid_to IS NULL`); už uzavřený nebo uživatelem vyřazený řádek se
-- nedotkne, protože podmínka na `valid_to IS NULL` u něj neplatí.
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-06-30'
 WHERE `country` = 'EE' AND `rate_type` = 'standard' AND `rate_percent` = 22.00 AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-07-31'
 WHERE `country` = 'RO' AND `rate_type` IN ('standard', 'reduced') AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-07-31'
 WHERE `country` = 'RO' AND `rate_type` = 'second_reduced' AND `rate_percent` = 5.00
   AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2024-12-31'
 WHERE `country` = 'SK' AND `rate_type` IN ('standard', 'reduced') AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2023-12-31'
 WHERE `country` = 'CZ' AND `rate_type` IN ('standard', 'reduced') AND `valid_from` = '2013-01-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2023-12-31'
 WHERE `country` = 'CZ' AND `rate_type` = 'second_reduced' AND `valid_from` = '2015-01-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2012-12-31'
 WHERE `country` = 'CZ' AND `rate_type` = 'standard' AND `rate_percent` = 20.00
   AND `valid_from` = '2010-01-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2012-12-31'
 WHERE `country` = 'CZ' AND `rate_type` = 'reduced' AND `rate_percent` = 14.00
   AND `valid_from` = '2012-01-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2011-12-31'
 WHERE `country` = 'CZ' AND `rate_type` = 'reduced' AND `rate_percent` = 10.00
   AND `valid_from` = '2010-01-01' AND `valid_to` IS NULL;
UPDATE `oss_member_state_rates` SET `valid_to` = '2021-06-30'
 WHERE `country` = 'SK' AND `rate_type` IN ('standard', 'reduced') AND `valid_from` = '2011-01-01' AND `valid_to` IS NULL;
