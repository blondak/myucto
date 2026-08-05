-- 1294: Historické sazby ZEMĚ DODAVATELE do číselníku členských států (OSS)
--
-- ── Proč to najednou vadí ───────────────────────────────────────────────────────────
-- Odvození místa plnění ({@see MyInvoice\Service\Oss\OssItemDeriver}) má od téhle vlny
-- jediné pravidlo: do TUZEMSKÉ větve smí výhradně řádek, u kterého číselník členských
-- států POZITIVNĚ potvrdil, že sazba v zemi dodavatele k datu plnění platí. Odpověď
-- „nevím" vede k odmítnutí položky, ne k tuzemskému zařazení — dřív se z ní tiše stávalo
-- tuzemsko a přesně tudy unikala cizí daň na ř. 1 českého přiznání.
--
-- Jenže seed migrace 1152 začíná u ČR až 1. 1. 2024 (a u ostatních států 1. 7. 2021,
-- kdy se OSS spustilo), protože ho zajímal STÁT SPOTŘEBY — a tam starší datum nedává
-- smysl. Země dodavatele je ale druhá strana téhož dotazu a ta se ptá i na doklady
-- z doby dávno před OSS: migrace 1 670 historických faktur je běžný vstup. Bez tohohle
-- doplnění by se každá česká faktura s DUZP před 1. 1. 2024 odmítla s hláškou
-- „číselník tuhle zemi k datu plnění nevede", ačkoli je na ní všechno v pořádku.
--
-- ── Rozsah: jen země, ve které může být DODAVATEL identifikovaný ────────────────────
-- Doplňují se ČR a SR, ne všech 27 států. Historie ostatních zemí by se uplatnila jedině
-- jako země spotřeby, a tam neznalost k žádnému úniku nevede — řádek jde do OSS a označí
-- se k ručnímu posouzení. Naopak zadrátovat „tuzemsko = CZ" nejde (proto existuje
-- `OssItemDeriver::domesticCountry()`), takže dodavatele identifikovaného na Slovensku
-- musí číselník obsloužit stejně.
--
-- ── Rozsah: jen doložitelné sazby, dolní hranice vědomá ─────────────────────────────
-- ČR se doplňuje po 1. 1. 2010, SR po 1. 1. 2011. Starší doklady se odmítnou dál — je to
-- hluboko za desetiletou dobou uchování daňových dokladů, takže dohadovat sazby 90. let
-- kvůli importu by přineslo víc rizika než užitku. Druhá snížená sazba ČR (10 %) vznikla
-- až 1. 1. 2015, dřív neexistovala a řádek pro ni proto nezačíná dřív.
--
-- ── Platnosti se UZAVÍRAJÍ ──────────────────────────────────────────────────────────
-- Každý řádek má `valid_to` a navazuje na následující období bez překryvu i bez mezery
-- (…-12-31 → …-01-01). Překryv by znamenal, že `OssRateCodebook::ratesFor()` vrátí k témuž
-- datu dvě sazby téhož typu, takže by kontrola tiše přijala i tu zrušenou a `checkRate()`
-- by nabízela nesmyslný výčet „platné: 21 %, 21 %".
--
-- Idempotence: shoda na (country, rate_type, rate_percent, valid_from) = `uq_osmr`, takže
-- opakované spuštění nic nepřidá a uživatelem doplněné řádky (`is_custom = 1`) nepřepíše.

SET NAMES utf8mb4;

INSERT INTO `oss_member_state_rates` (`country`, `rate_type`, `rate_percent`, `valid_from`, `valid_to`, `note`)
SELECT * FROM (
    -- ── Česká republika ────────────────────────────────────────────────────────────
    -- 1. 1. 2013 – 31. 12. 2023: 21 / 15, od 1. 1. 2015 navíc druhá snížená 10 %.
    -- Na 1. 1. 2024 navazuje seed migrace 1152 (21 / 12), proto tu platnost končí.
    SELECT 'CZ' AS c, 'standard' AS t, 21.00 AS r, '2013-01-01' AS f, '2023-12-31' AS u,
           'základní sazba do sloučení snížených sazeb k 1. 1. 2024' AS n UNION ALL
    SELECT 'CZ', 'reduced', 15.00, '2013-01-01', '2023-12-31',
           'první snížená sazba, k 1. 1. 2024 sloučena s druhou na 12 %' UNION ALL
    SELECT 'CZ', 'second_reduced', 10.00, '2015-01-01', '2023-12-31',
           'druhá snížená sazba (knihy, léky, kojenecká výživa), zavedena 1. 1. 2015' UNION ALL
    -- 1. 1. 2012 – 31. 12. 2012: 20 / 14 (jednoletá sazba před sjednocením na 21 / 15).
    SELECT 'CZ', 'standard', 20.00, '2010-01-01', '2012-12-31', 'základní sazba do 31. 12. 2012' UNION ALL
    SELECT 'CZ', 'reduced', 14.00, '2012-01-01', '2012-12-31', 'snížená sazba platná jen v roce 2012' UNION ALL
    -- 1. 1. 2010 – 31. 12. 2011: 20 / 10.
    SELECT 'CZ', 'reduced', 10.00, '2010-01-01', '2011-12-31', 'snížená sazba do 31. 12. 2011' UNION ALL

    -- ── Slovenská republika ────────────────────────────────────────────────────────
    -- 1. 1. 2011 – 30. 6. 2021: 20 / 10. Na 1. 7. 2021 navazuje seed migrace 1152
    -- (rovněž 20 / 10, uzavřený k 31. 12. 2024 reformou 2025), proto tu platnost končí.
    SELECT 'SK', 'standard', 20.00, '2011-01-01', '2021-06-30',
           'základní sazba zvýšená z 19 % k 1. 1. 2011' UNION ALL
    SELECT 'SK', 'reduced', 10.00, '2011-01-01', '2021-06-30', 'snížená sazba do reformy 2025'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `oss_member_state_rates` x
     WHERE x.country = seed.c AND x.rate_type = seed.t
       AND x.rate_percent = seed.r AND x.valid_from = seed.f
);
