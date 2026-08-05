-- 1292: Doplnění chybějících snížených sazeb do číselníku členských států (OSS)
--
-- Seed migrace 1152 vedl u většiny států jen základní a JEDNU sníženou sazbu. Řada
-- členských států jich má ale víc a zákazníci na ně fakturují běžně — typicky maďarských
-- 5 % (léky, knihy, dálkové teplo) a slovenských 5 % zavedených reformou k 1. 1. 2025.
-- Sazba, kterou číselník nezná, se z dokladu nedá ověřit ani z ní odvodit typ sazby,
-- takže `invoice_items.oss_rate_type` zůstane prázdný a `OssXmlExporter` takový řádek
-- do podání nepustí. Chybějící řádek číselníku je tedy blokátor podání, ne kosmetika.
--
-- ── Proč jsou nové řádky vesměs `second_reduced` ────────────────────────────────────
-- ENUM je naše vlastní taxonomie o čtyřech hodnotách, do které se 27 národních škál
-- nevejde beze zbytku. Do podání se posílá jen dvojhodnotový kód
-- (`OssXmlExporter::rateTypeCode()`: `standard` → Z, cokoli jiného → S), takže u snížených
-- sazeb nese typ jen informaci „není základní". `reduced` je proto obsazený tím, co
-- doplnila migrace 1152, a druhá snížená sazba téhož státu dostává `second_reduced` —
-- i tam, kde je procentem VYŠŠÍ (AT 13 % vs. 10 %, BE 12 % vs. 6 %). Pořadí názvů
-- neurčuje výši, jen to, který řádek přibyl později.
--
-- ── Rozsah je záměrně užší, než jaká je realita ─────────────────────────────────────
-- Uvedené jsou jen sazby, které lze doložit a jednoznačně zařadit do ENUMu. Super-snížené
-- sazby (FR 2,1 %, IT/ES 4 %, IE 4,8 %, LU 3 %, CY 3 %) tu proto NEJSOU — pro pátou
-- kategorii v ENUMu místo není a schovat je pod `parking` by rozbilo kontrolu
-- `OssRateCodebook::checkRate()`, která typ porovnává. Číselník pořád VARUJE a NEBLOKUJE,
-- takže neúplnost je viditelná, ne tichá.
--
-- Číselník zatím nemá žádné údržbové UI — přidat nebo opravit sazbu jde jen migrací
-- (sloupec `is_custom` s tím počítá, ale CRUD nad ním se řeší až ve vlně 2).

SET NAMES utf8mb4;

INSERT INTO `oss_member_state_rates` (`country`, `rate_type`, `rate_percent`, `valid_from`, `note`)
SELECT * FROM (
    -- Rakousko: 20 / 13 / 10; 13 % kultura, ubytování, vstupné, umělci
    SELECT 'AT' AS c, 'second_reduced' AS t, 13.00 AS r, '2021-07-01' AS f, 'druhá snížená sazba' AS n UNION ALL
    -- Belgie: 21 / 12 / 6; 12 % restaurační služby, sociální bydlení, uhlí
    SELECT 'BE', 'second_reduced', 12.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Kypr: 19 / 9 / 5; 5 % potraviny, léky, knihy, vstupné
    SELECT 'CY', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Finsko: 25,5 / 14 / 10; 10 % knihy, léky, doprava, ubytování
    SELECT 'FI', 'second_reduced', 10.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Řecko: 24 / 13 / 6; 6 % léky, vakcíny, knihy, noviny
    SELECT 'GR', 'second_reduced', 6.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Chorvatsko: 25 / 13 / 5; 5 % chléb, mléko, knihy, léky
    SELECT 'HR', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Maďarsko: 27 / 18 / 5; 5 % léky, knihy, dálkové teplo, nové bydlení
    SELECT 'HU', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Irsko: 23 / 13,5 / 9; 9 % noviny, e-knihy, elektřina a plyn
    SELECT 'IE', 'second_reduced', 9.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Litva: 21 / 9 / 5; 5 % léky, zdravotnické prostředky, noviny a časopisy
    SELECT 'LT', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Lotyšsko: 21 / 12 / 5; 5 % čerstvé ovoce a zelenina, knihy
    SELECT 'LV', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Malta: 18 / 7 / 5; 5 % elektřina, knihy, zdravotnické potřeby
    SELECT 'MT', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Rumunsko do reformy 2025: 19 / 9 / 5; 5 % knihy, ubytování, vstupné
    SELECT 'RO', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba, zrušena reformou k 1. 8. 2025' UNION ALL
    -- Švédsko: 25 / 12 / 6; 6 % knihy, doprava osob, kultura a sport
    SELECT 'SE', 'second_reduced', 6.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Slovinsko: 22 / 9,5 / 5; 5 % knihy, noviny a časopisy
    SELECT 'SI', 'second_reduced', 5.00, '2021-07-01', 'druhá snížená sazba' UNION ALL
    -- Slovensko od reformy k 1. 1. 2025: 23 / 19 / 5; 5 % základní potraviny,
    -- léky, knihy, ubytování, nájemní bydlení. Před reformou 5 % takto široce neplatila,
    -- proto `valid_from` až 2025-01-01 a ne datum spuštění OSS.
    SELECT 'SK', 'second_reduced', 5.00, '2025-01-01', 'druhá snížená sazba zavedená reformou 2025'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `oss_member_state_rates` x
     WHERE x.country = seed.c AND x.rate_type = seed.t
       AND x.rate_percent = seed.r AND x.valid_from = seed.f
);

-- Rumunská reforma k 1. 8. 2025 slila 9 % a 5 % do jediné sazby 11 % (tu už zavedla
-- migrace 1152). Bez uzavření platnosti by k datu plnění po reformě platily obě a
-- kontrola by tiše přijala i tu zrušenou.
UPDATE `oss_member_state_rates` SET `valid_to` = '2025-07-31'
 WHERE `country` = 'RO' AND `rate_type` = 'second_reduced'
   AND `rate_percent` = 5.00 AND `valid_from` = '2021-07-01' AND `valid_to` IS NULL;
