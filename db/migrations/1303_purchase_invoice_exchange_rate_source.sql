-- 1303: taxonomie zdroje kurzu na přijaté faktuře — rozšíření ENUMu, BEZ backfillu
--
-- Kurz na dokladu se má automaticky přenačíst, když se změní rozhodný den (DUZP,
-- resp. datum vystavení) nebo měna. Přepsat se přitom smí JEN hodnota, která je
-- funkcí toho data. Dosavadní čtyřhodnotový výčet ('cnb','manual','idoklad',
-- 'fakturoid') to rozlišit neuměl:
--
--   * 'manual' zapisovaly VŠECHNY importy (AI extrakce z PDF, ISDOC, iDoklad,
--     Fakturoid) i každý PUT z editoru, který pole neposlal → o původu kurzu
--     neříká vůbec nic,
--   * 'idoklad' a 'fakturoid' se nikdy nezapsaly ani jednou,
--   * pevný kurz období (§ 24/7 ZoÚ) neměl vlastní hodnotu, i když je to jiný
--     způsob odvození než denní kurz ČNB.
--
-- Nové hodnoty (SSOT: MyInvoice\Support\ExchangeRateSources):
--   * 'fixed'  — pevný kurz období (§ 24/7 ZoÚ); stejně jako 'cnb' funkce data,
--                takže přenačtení ho smí přepsat,
--   * 'import' — kurz přinesl cizí systém nebo doklad dodavatele; není odvozený
--                z data, nepřepisuje se,
--   * 'user'   — člověk vepsal kurz do formuláře; nepřepisuje se nikdy.
--
-- ── Proč ŽÁDNÝ UPDATE / backfill ────────────────────────────────────────────────
-- Nabízelo by se přejmenovat historické 'manual' na něco jako 'unknown'. Nejde to:
-- migrace musí být idempotentní (AGENTS.md), takže při druhém běhu by týž UPDATE
-- přepsal i hodnoty, které mezitím vznikly legitimně. Rozlišit „staré manual"
-- od „nového manual" po první migraci nelze — informace v datech není.
--
-- Řešení je opačné: 'manual' se ZACHOVÁ v původní podobě a nově se už NEZAPISUJE
-- (importy píšou 'import', formulář 'user', automatika 'cnb'/'fixed'; hlídá to
-- architektonický guard v api/tests/Architecture/ExchangeRateGuardTest.php).
-- Existující 'manual' tak přirozeně znamená „neznámý / historický zápis" a jako
-- takový se chová: automatika ho nepřepíše, uživatel dostane varování
-- `exchange_rate_not_reloaded`. Význam nese COLUMN COMMENT níž, docblock SSOT
-- třídy a i18n label.
--
-- ── Proč se mění DEFAULT z 'cnb' na 'manual' ────────────────────────────────────
-- Věcná změna, ne kosmetika: API klient, který pole nepošle, si dnes doklad
-- označí za „systémem odvozený z data" a přenačtení mu kurz smí přepsat. Fail-safe
-- je opačný směr — neznámý původ se nepřepisuje.
--
-- POZOR: MODIFY COLUMN na ENUM je ALGORITHM=COPY (rebuild tabulky, ne INSTANT) —
-- nové hodnoty se přidávají na KONEC výčtu, ale MariaDB rebuild stejně provede.
-- Na produkci maintenance window / pt-online-schema-change (stejně jako 1009/1010).
--
-- Idempotence: MODIFY COLUMN je deklarativní — opakované spuštění nastaví tentýž stav.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    MODIFY COLUMN exchange_rate_source
        ENUM('cnb','manual','idoklad','fakturoid','fixed','import','user')
        NOT NULL DEFAULT 'manual'
        COMMENT 'Kdo kurz nastavil. Přenačtení po změně rozhodného data/měny smí přepsat JEN cnb a fixed (funkce data). manual = DEPRECATED, neznámý/historický zápis (do migrace 1303 ho psaly všechny importy) — nezapisuje se, nepřepisuje se. idoklad/fakturoid = DEPRECATED, nikdy nezapsané, chovají se jako import. SSOT: MyInvoice\\Support\\ExchangeRateSources';
