-- MyÚčto.cz — OSS na položkách šablony opakované faktury.
--
-- Opakované faktury byly poslední kanál, který o OSS nevěděl vůbec nic: šablona OSS
-- sloupce neměla, generátor je tedy neměl odkud přenést a KAŽDÁ vygenerovaná faktura
-- vznikla bez OSS. U zákazníka s e-shopem, který polským spotřebitelům fakturuje
-- měsíčně ze šablony, to znamená, že se po vyčištění historických dat začne tatáž
-- chyba vyrábět znovu — cizí daň na ř. 1 tuzemského přiznání, tentokrát cronem.
--
-- ── Proč sloupce na šabloně, když existuje derivace ─────────────────────────────────
-- Derivace ({@see OssItemDeriver}) běží při generování a odpoví u naprosté většiny
-- řádků správně. Nezná ale případy, kde rozhodl ČLOVĚK: typ sazby, který číselník
-- nepotvrdil a účetní ho dohledala, nebo typ plnění, který se z jednotky ani z NACE
-- odvodit nedá. Bez sloupců by se takové rozhodnutí muselo opakovat na každé
-- vygenerované faktuře ručně, tedy přesně to, čemu se šablona vyhýbá. Sloupce jsou
-- proto ULOŽENÉ ROZHODNUTÍ; derivace se ptá jen tam, kde šablona mlčí.
--
-- ── Co tu ZÁMĚRNĚ není ──────────────────────────────────────────────────────────────
-- `oss_needs_manual_review` na šabloně nedává smysl: příznak je výstup derivace ke
-- KONKRÉTNÍMU dokladu k jeho datu plnění, ne vlastnost šablony. Generátor ho zapisuje
-- na položku faktury podle toho, jak dopadla derivace TEHDY.
-- Kurz, přepočtené částky ani původní OSS období tu nejsou ze stejného důvodu jako
-- u importu: dopočítává je až OssLedgerService kurzem ČNB k DUZP.
--
-- Zpětně kompatibilní: repozitář i generátor sahají na sloupce jen pod
-- `Connection::hasColumn()`, takže instance bez téhle migrace generuje dál jako dosud.

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_template_items
  ADD COLUMN IF NOT EXISTS oss_applicable TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = řádek se na vygenerované faktuře vykáže v OSS'
      AFTER vat_rate_id,
  ADD COLUMN IF NOT EXISTS oss_consumer_country CHAR(2) NULL
      COMMENT 'Stát spotřeby (ISO2); NULL = odvodí se při generování'
      AFTER oss_applicable,
  -- VARCHAR(32) shodně s `invoice_items.oss_rate_type` (migrace 0137) — hodnoty hlídá
  -- aplikace proti OssItemDecision::RATE_TYPES, ne ENUM, aby přidání typu neznamenalo
  -- ALTER TABLE na dvou místech s rizikem, že se rozejdou.
  ADD COLUMN IF NOT EXISTS oss_rate_type VARCHAR(32) NULL
      COMMENT 'standard|reduced|second_reduced|parking; NULL = odvodí se z číselníku'
      AFTER oss_consumer_country,
  ADD COLUMN IF NOT EXISTS oss_supply_type ENUM('goods','services') NULL
      COMMENT 'Zboží vs. služba pro OSS podání; NULL = odvodí se při generování'
      AFTER oss_rate_type;
