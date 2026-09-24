-- MyÚčto.cz — ř. 24 přiznání k DPH jsou „Vybraná plnění (§ 110b odst. 2)"
--
-- Kód 24z vznikl v migraci 1512 s popisem „Zasílání zboží do jiného členského
-- státu (§ 8) – mimo režim OSS". Tiskopis 25 5401 i pokyny k němu (MFin 5412)
-- ale ř. 24 od 1. 7. 2021 vymezují jinak: hodnota vybraných plnění s nárokem na
-- odpočet, na která je použit zvláštní režim jednoho správního místa (OSS), nebo
-- by použit být mohl — služby nepovinným osobám s místem plnění v jiném členském
-- státě, prodej zboží na dálku (§ 8 odst. 1, § 8a) a dodání přes elektronické
-- rozhraní (§ 13a odst. 2 písm. b). Plnění v OSS se na ř. 24 propisují samy
-- (VatLedgerService::ossSelectedSupplyRows), kód 24z slouží pro ruční zařazení
-- téhož druhu plnění mimo OSS.
--
-- Mění se jen globální řádek a jen tehdy, když nese původní text — upravený popis
-- ani per-tenant override se nepřepisují. Idempotentní: druhé spuštění nic nenajde.

SET NAMES utf8mb4;

UPDATE vat_classifications
   SET label = 'Vybraná plnění (§ 110b odst. 2) – služby nepovinným osobám a prodej zboží na dálku do JČS (OSS i mimo OSS)'
 WHERE supplier_id IS NULL
   AND code = '24z'
   AND label = 'Zasílání zboží do jiného členského státu (§ 8) – mimo režim OSS';
