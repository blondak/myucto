-- MyÚčto.cz — výchozí nastavení OSS v kartě odběratele.
--
-- Dvě otázky, na které se dnes odpovídá na KAŽDÉM dokladu znovu, přestože odpověď je
-- vlastností odběratele:
--   1. „u tohohle odběratele se OSS neuplatňuje"  → oss_mode = 'never'
--   2. „fakturujeme mu zboží / služby"            → oss_default_supply_type
--
-- ── Uplatní se automaticky, ale JEN v tom rozsahu, kde nejde o místo plnění ─────────
-- O tom, jestli je řádek OSS, rozhoduje výhradně {@see OssItemDeriver} z číselníku
-- sazeb členských států. Karta odběratele je DATA pro to rozhodnutí, nikdy druhá
-- autorita nad ním:
--
--  * `oss_mode = 'never'` umí OSS jedině UBRAT. Vynutit ho nesmí — „always" by uměl
--    poslat do OSS i plnění, které tam nepatří (odběratel s DIČ, tuzemská dodávka,
--    doklad v režimu přenesené daňové povinnosti), a to je přesně ta chyba, jen
--    zrcadlově otočená. Ubrat je bezpečné, protože invariant proti úniku cizí daně
--    platí dál: řádek s cizí sazbou se ani u vyloučeného odběratele nestane tuzemským,
--    jen se odmítne s hláškou, která pojmenuje příčinu.
--    K čemu to je: odběratel, u kterého je najisto známo, že jde o osobu povinnou
--    k dani, jen zatím nedodal DIČ. Bez tohohle přepínače by se jeho doklady
--    označovaly k ručnímu posouzení donekonečna.
--
--  * `oss_default_supply_type` odpovídá na otázku, kterou derivace dnes DOHADUJE
--    (fallback „služba", protože jednotka ani CZ-NACE nic neřekly). Typ plnění přitom
--    rozhoduje o sazbě ve státě spotřeby, takže špatně dosazený typ = špatně odvedená
--    daň. Uživatelská znalost je vždy lepší než fallback a o MÍSTĚ plnění nevypovídá
--    nic, proto se uplatní automaticky — ale až POD měrnou jednotkou položky: jednotka
--    je důkaz z konkrétního řádku, default je vlastnost karty.
--
-- ── Proč tu NENÍ „výchozí země spotřeby" ────────────────────────────────────────────
-- Byla v zadání, ale jako sloupec by škodila. Země spotřeby se bere z adresy odběratele
-- (u importu z dokladu) a je to TÝŽ údaj, proti kterému se rozhoduje o tuzemsku:
-- default v kartě by uměl poslat daň do jiného státu, než jaký doklad uvádí, a nikde
-- by nebylo vidět, že se ty dva rozešly. Chybí-li země úplně, je to vada karty
-- s vlastní hláškou („doplňte zemi odběratele") — druhé místo, kam ji vyplnit, problém
-- neřeší, jen ho rozdvojí.
--
-- Zpětně kompatibilní: derivace čte sloupce pod `Connection::hasColumn()`; bez migrace
-- se karta prostě nevyjadřuje a chová se jako 'auto'.

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS oss_mode ENUM('auto','never') NOT NULL DEFAULT 'auto'
      COMMENT 'auto = rozhoduje derivace; never = u tohoto odběratele OSS neuplatňovat'
      AFTER reverse_charge,
  ADD COLUMN IF NOT EXISTS oss_default_supply_type ENUM('goods','services') NULL
      COMMENT 'Výchozí typ plnění pro OSS; NULL = odvodit z jednotky / CZ-NACE'
      AFTER oss_mode;
