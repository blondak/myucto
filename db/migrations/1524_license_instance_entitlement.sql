-- ==========================================================================
-- 1524 — co má instance ZAPLACENO, doručené licenčním serverem
-- ==========================================================================
-- Do `cfg.local.php` zapisuje `instance.quota_gb` a `instance.plan` zřizování,
-- a to JEDNOU — při založení instance. Zákazník si ale úložiště dokupuje a
-- tarif mění průběžně, a v ten okamžik nemá kdo do konfigurace sáhnout: hosting
-- o naší objednávce neví (dozví se jen kvótu, kterou má nastavit na disku) a my
-- na běžící instanci nemáme kam zaklepat.
--
-- Rozsah zaplacené služby proto NENÍ konfigurace. Vzniká tam, kde se platí — na
-- licenčním serveru — a na instanci se veze S DENNÍ OBNOVOU LICENCE, stejnou
-- cestou jako stav předplatného (`subscription_info`, migrace 1321). Instalace
-- si poslední doručenou podobu uloží sem, aby přežila výpadek serveru.
--
-- ⚠️ Konfigurace zůstává, ale jen jako ZÁLOŽNÍ hodnota pro první den provozu,
-- než proběhne první obnova. Jakmile licenční server řekne své, platí jeho
-- údaj — jinak by instance po dokoupení místa napořád ukazovala starou kvótu
-- a vyzývala k nákupu něčeho, co si zákazník už koupil.
--
-- ⚠️ NULL ZNAMENÁ „NEVÍME", NE NULU ani „nic nezaplaceno". Prázdný sloupec má
-- self-hosted instalace i spravovaná před první obnovou; ani jedna se nesmí
-- začít tvářit jako instance s nulovou kvótou (dělení nulou → 100 % obsazeno
-- → režim jen pro čtení na instalaci, která nic neprovedla).
--
-- Ukládá se surová odpověď serveru (JSON), ne rozparsované sloupce: pole, která
-- server přidá později, se tím dostanou na instanci bez migrace, a starší
-- instalace je prostě přehlédne.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE license
  ADD COLUMN IF NOT EXISTS instance_info LONGTEXT NULL
  COMMENT 'poslední doručený rozsah zaplacené služby (JSON); NULL = nevíme, NE nula'
  AFTER subscription_info;
