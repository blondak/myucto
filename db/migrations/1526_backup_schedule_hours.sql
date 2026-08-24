-- 1526 — rozvrh záloh na 02:00 / 08:00 / 14:00 / 20:00.
--
-- Migrace 1522 nasadila jako cílový rozvrh `0 */6 * * *` a v komentáři u něj
-- tvrdila „tedy 02:00, 08:00, 14:00, 20:00". Nesedí to: `0 */6 * * *` sedne na
-- 00/06/12/18. Dohodnuté (a hostingu potvrzené) časy jsou ty první, takže se
-- opravuje výraz, ne dokumentace — ranní běh má padnout do nočního okna.
--
-- Druhá půlka opravy je v kódu: do teď rozvrh z téhle tabulky NIKDO nečetl,
-- plánovač i generátor crontabu braly `linux_cron` z CronCatalog (1× denně ve
-- 02:00). Uložený kontrakt byl mrtvý zápis a instalace zálohovala jednou denně,
-- ať tu stálo cokoli. Vazbu doplňuje CronCatalog::withContractedSchedules().
--
-- Přepisuje se JEN řádek, který pořád nese seed z 1522. Vlastní rozvrh
-- provozovatele (cokoli jiného) zůstává — je to vědomé rozhodnutí a migrace
-- ho přemazat nesmí. Počet běhů za den je u obou výrazů 4, strop se nemění.

SET NAMES utf8mb4;

UPDATE backup_schedule_contract
   SET cron_expr    = '0 2,8,14,20 * * *',
       runs_per_day = 4,
       updated_at   = NOW()
 WHERE id = 1
   AND cron_expr = '0 */6 * * *';

ALTER TABLE backup_schedule_contract
  MODIFY COLUMN cron_expr VARCHAR(120) NOT NULL DEFAULT '0 2,8,14,20 * * *'
    COMMENT 'pětipolový cron výraz; default = 02:00, 08:00, 14:00, 20:00';
