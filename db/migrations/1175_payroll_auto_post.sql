-- 1175: pravidelná měsíční mzda na kartě zaměstnance + automatické zaúčtování
--
-- PROČ: mzdová rekapitulace umí spočítat i zaúčtovat měsíc přesně, ale VŽDY ji musí
-- někdo ručně odpálit — otevřít stránku, vybrat měsíc, opsat hrubou mzdu, kliknout.
-- U drtivé většiny firem je přitom hrubá mzda každý měsíc TÁŽ (smluvní mzda jednatele
-- nebo zaměstnance na HPP) a ručně se opisuje jen proto, že ji systém nikde nemá.
-- Účetní tak měsíc od měsíce přepisuje konstantu — a když na to zapomene, chybí
-- zápis nákladu i závazku vůči FÚ/ČSSZ/ZP za celý měsíc.
--
-- ── Proč zrovna tyhle dva sloupce ───────────────────────────────────────────────────
-- `monthly_gross` je DEKLAROVANÁ pravidelná mzda, ne historie: skutečně vyplacené
-- částky jsou (a zůstávají) v `payroll_monthly_records`, kde jsou snapshotované
-- k měsíci a nesmí je změnit pozdější úprava karty. Tenhle sloupec je jen vstup pro
-- příští výpočet, takže se smí přepsat kdykoli.
--
-- INT UNSIGNED v celých Kč záměrně — shodně s `payroll_monthly_records.gross INT`.
-- Desetinná hrubá mzda v ČR neexistuje a DECIMAL by tu jen vyrobil nesoulad typů mezi
-- deklarací a snapshotem. NULL = „pravidelná mzda není sjednaná" (dohodář placený
-- podle odpracovaných hodin), což je jiný stav než 0 Kč.
--
-- `auto_post` je oddělený od `monthly_gross` schválně: vyplnit částku pro pohodlí
-- (předvyplní se ve formuláři) je něco jiného než pověřit systém, ať za mě měsíčně
-- účtuje. Automat se navíc musí dát vypnout na jeden klik bez ztráty té částky.
--
-- ── Co s tím dělá cron ──────────────────────────────────────────────────────────────
-- `cron-payroll-post` běží 1. dne v měsíci a účtuje měsíc PŘEDCHOZÍ (k tomu dni jsou
-- už všechna jeho data známá). Datum zápisu zůstává poslední den účtovaného měsíce,
-- takže doklad sedí do správného období. Bere jen aktivní zaměstnance, kde je
-- `auto_post = 1` A ZÁROVEŇ `monthly_gross > 0` — bez částky nemá co zaúčtovat.
--
-- Idempotence se nezavádí nová: drží ji `uq_je_supplier_source` (jeden zápis na
-- supplier+RRRRMM) a `uq_pmr_employee_period` (jeden mzdový záznam na zaměstnance
-- a měsíc). Druhý běh téhož měsíce tedy nic nezdvojí.

SET NAMES utf8mb4;

ALTER TABLE payroll_employees
    ADD COLUMN IF NOT EXISTS monthly_gross INT UNSIGNED NULL
        COMMENT 'pravidelná měsíční hrubá mzda v celých Kč; NULL = nesjednaná (vstup pro cron-payroll-post)'
        AFTER child_count,
    ADD COLUMN IF NOT EXISTS auto_post TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'účtovat mzdu měsíčně automaticky (cron-payroll-post); vyžaduje vyplněný monthly_gross'
        AFTER monthly_gross;

-- Default 0 je jediný bezpečný: zapnout účtování za uživatele, který o něm neví,
-- by do deníku dosavadních firem nasypalo zápisy, které nikdo neschválil.
