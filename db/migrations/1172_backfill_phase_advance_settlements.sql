-- MyÚčto.cz — doplnit `advance_settlements` do ENUM `accounting_backfill_jobs.phase`.
--
-- BackfillService má od zavedení doúčtování záloh pátou fázi a hlásí ji přes
-- AccountingBackfillJobRepository::updateProgress($jobId, 'advance_settlements', …),
-- jenže ENUM zůstal na čtyřech hodnotách z původní migrace 1060. Zápis tedy do
-- sloupce nikdy nedošel.
--
-- PROČ TO NIKDO NEVIDĚL: mimo striktní režim MariaDB hodnotu mimo ENUM jen
-- ZKRÁTÍ na prázdný řetězec a vyhodí warning. Fáze se tiše ztratila (postup se
-- u páté fáze tvářil jako prázdný), ale nic nespadlo. Teprve v CI, kde běží
-- STRICT_TRANS_TABLES, se z warningu 1265 stane výjimka a shodí 122 testů —
-- proto to vyplavalo až prvním během integračních testů nad čerstvou databází.
--
-- Hodnota se přidává na KONEC výčtu: pořadí v ENUM je zároveň číselný index,
-- takže vložení doprostřed by přečíslovalo existující řádky.

SET NAMES utf8mb4;

ALTER TABLE accounting_backfill_jobs
    MODIFY COLUMN phase ENUM('opening','documents','cash','bank','advance_settlements') NULL;
